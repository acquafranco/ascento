<?php

namespace App\Services\Emergency;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Resources\Emergency\EmergencyRequestResource;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\EmergencyDelivery;
use App\Models\EmergencyRequest;
use App\Models\PortalMembership;
use App\Models\User;
use App\Notifications\App\EmergencyRequestNotification;
use App\Services\Notifications\Notifier;
use App\Services\OnCall\OnCallService;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use App\Support\Portal\PortalAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Solicitudes de ayuda por posible atrapamiento desde el QR público.
 *
 * Todo se resuelve en el servidor a partir del token del QR (nunca de datos
 * del navegador): edificio, empresa, cliente y destinatarios.
 *
 * Defensa por capas contra abuso (sin servicios externos):
 * 1. Límite por IP en la ruta (throttle de Laravel, en la caché de la base).
 * 2. Comprobante firmado (HMAC con la clave de la app) que entrega la pantalla de
 *    confirmación: sin él no se registra nada; su hash es la clave de idempotencia
 *    (UNIQUE): un doble clic o un reintento del navegador devuelven la MISMA solicitud.
 * 3. Mismo incidente: si el edificio ya tiene una solicitud abierta reciente, se suma
 *    como repetición (no se crea otra) y se vuelve a avisar como mucho cada 10 minutos.
 * 4. Topes de incidentes nuevos por edificio y por empresa (abuso distribuido): no
 *    bloquean una emergencia en curso, que se suma a la abierta.
 * 5. Transacción con bloqueo del QR del edificio: dos pedidos simultáneos no crean dos.
 */
class EmergencyRequestService
{
    /** Una solicitud abierta de las últimas 2 h se considera el mismo incidente. */
    public const ACTIVE_MINUTES = 120;

    /** Volver a avisar por el mismo incidente, como mucho cada 10 min (hasta 6 rondas). */
    public const RENOTIFY_MINUTES = 10;

    public const MAX_ROUNDS = 6;

    /** Incidentes NUEVOS por hora: por edificio y por empresa. */
    public const MAX_NEW_PER_BUILDING_HOUR = 3;

    public const MAX_NEW_PER_COMPANY_HOUR = 20;

    /** Validez del comprobante de la pantalla de confirmación. */
    public const NONCE_HOURS = 3;

    public function __construct(private Notifier $notifier) {}

    /*
    |--------------------------------------------------------------------------
    | DISPONIBILIDAD
    |--------------------------------------------------------------------------
    */

    /** ¿Este QR puede recibir solicitudes AHORA? (QR activo, edificio activo, empresa con acceso, plan e interruptor). */
    public function availableFor(?BuildingPublicPage $page): bool
    {
        if (! $page || ! $page->is_active) {
            return false;
        }

        $company = Company::find($page->company_id);
        $building = Building::withoutGlobalScopes()->where('company_id', $page->company_id)->whereNull('deleted_at')->find($page->building_id);

        return $company && $building
            && $company->hasActiveAccess()
            && (bool) $company->emergency_requests_enabled
            && PlanGuard::for($company)->allows(PlanFeature::PublicQr)
            && PlanGuard::for($company)->allows(PlanFeature::EmergencyRequests);
    }

    /** Ascensores que la persona puede elegir (solo si el QR publica los equipos). */
    public function elevatorChoices(BuildingPublicPage $page): Collection
    {
        if (! $page->show_elevators) {
            return collect();
        }

        return Elevator::withoutGlobalScopes()->where('company_id', $page->company_id)->where('building_id', $page->building_id)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label', 'kind']);
    }

    /*
    |--------------------------------------------------------------------------
    | COMPROBANTE FIRMADO (anti-falsificación e idempotencia, sin cookies)
    |--------------------------------------------------------------------------
    */

    public function issueNonce(BuildingPublicPage $page): string
    {
        $random = Str::random(24);
        $ts = now()->timestamp;

        return $random.'.'.$ts.'.'.$this->sign($page, $random, $ts);
    }

    /** Clave de idempotencia si el comprobante es válido para ESTE QR; null si no. */
    public function idempotencyKey(BuildingPublicPage $page, mixed $nonce): ?string
    {
        if (! is_string($nonce) || strlen($nonce) > 200 || ! preg_match('/^([A-Za-z0-9]{24})\.(\d{9,11})\.([a-f0-9]{64})$/', $nonce, $m)) {
            return null;
        }

        [, $random, $ts, $sig] = $m;
        $ts = (int) $ts;
        $age = now()->timestamp - $ts;

        if ($age < -60 || $age > self::NONCE_HOURS * 3600 || ! hash_equals($this->sign($page, $random, $ts), $sig)) {
            return null;
        }

        return hash('sha256', $nonce);
    }

    private function sign(BuildingPublicPage $page, string $random, int $ts): string
    {
        // Incluye el token: regenerar o desactivar el QR invalida los comprobantes anteriores.
        return hash_hmac('sha256', 'emergency|'.$page->id.'|'.$page->token.'|'.$random.'|'.$ts, (string) config('app.key'));
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRO
    |--------------------------------------------------------------------------
    */

    /**
     * Registra (o reutiliza) la solicitud. Resultado:
     * - status: created | repeat | duplicate (mismo envío) | unavailable | invalid | limited
     * - request: la solicitud (salvo unavailable / invalid / limited)
     *
     * @return array{status: string, request: ?EmergencyRequest}
     */
    public function submit(BuildingPublicPage $page, mixed $nonce, mixed $elevatorId, ?string $ip): array
    {
        if (! $this->availableFor($page)) {
            return ['status' => 'unavailable', 'request' => null];
        }

        $key = $this->idempotencyKey($page, $nonce);
        if ($key === null) {
            return ['status' => 'invalid', 'request' => null];
        }

        // El ascensor es opcional: un valor que no sea un equipo activo de ESTE edificio (y publicado) se ignora.
        $elevator = null;
        if (is_scalar($elevatorId) && ctype_digit((string) $elevatorId)) {
            $elevator = $this->elevatorChoices($page)->firstWhere('id', (int) $elevatorId);
        }

        try {
            $result = DB::transaction(function () use ($page, $key, $elevator, $ip) {
                // Serializa por edificio: dos pedidos simultáneos no crean dos incidentes.
                BuildingPublicPage::withoutGlobalScopes()->lockForUpdate()->find($page->id);

                if ($existing = EmergencyRequest::withoutGlobalScopes()->where('idempotency_key', $key)->first()) {
                    return ['status' => 'duplicate', 'request' => $existing];
                }

                $active = EmergencyRequest::withoutGlobalScopes()->where('company_id', $page->company_id)->where('building_id', $page->building_id)
                    ->open()->where('created_at', '>=', now()->subMinutes(self::ACTIVE_MINUTES))
                    ->when($elevator, fn ($q) => $q->where(fn ($w) => $w->whereNull('elevator_id')->orWhere('elevator_id', $elevator->id)))
                    ->latest('id')->first();

                $overLimit = EmergencyRequest::withoutGlobalScopes()->where('building_id', $page->building_id)->where('created_at', '>=', now()->subHour())->count() >= self::MAX_NEW_PER_BUILDING_HOUR
                    || EmergencyRequest::withoutGlobalScopes()->where('company_id', $page->company_id)->where('created_at', '>=', now()->subHour())->count() >= self::MAX_NEW_PER_COMPANY_HOUR;

                // Con el tope alcanzado, una emergencia en curso del edificio (cualquier ascensor) igual se suma.
                $active ??= $overLimit
                    ? EmergencyRequest::withoutGlobalScopes()->where('company_id', $page->company_id)->where('building_id', $page->building_id)
                        ->open()->where('created_at', '>=', now()->subMinutes(self::ACTIVE_MINUTES))->latest('id')->first()
                    : null;

                if ($active) {
                    $active->forceFill(['repeat_count' => $active->repeat_count + 1, 'last_repeat_at' => now()])->save();

                    return ['status' => 'repeat', 'request' => $active];
                }

                if ($overLimit) {
                    return ['status' => 'limited', 'request' => null];
                }

                $building = Building::withoutGlobalScopes()->find($page->building_id);
                $request = new EmergencyRequest;
                // Sin eventos: con una sesión abierta (por ejemplo, un técnico de OTRA empresa que
                // escanea el QR), BelongsToCompany pondría la empresa del usuario. La empresa sale del QR.
                $request->forceFill([
                    'company_id' => $page->company_id,
                    'client_id' => $building?->client_id,
                    'building_id' => $page->building_id,
                    'elevator_id' => $elevator?->id,
                    'reference' => $this->newReference(),
                    'idempotency_key' => $key,
                    'status' => EmergencyRequest::OPEN,
                    'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
                ]);
                EmergencyRequest::withoutEvents(fn () => $request->save());

                return ['status' => 'created', 'request' => $request];
            });
        } catch (UniqueConstraintViolationException) {
            // Mismo comprobante en dos pedidos simultáneos: gana uno; el otro devuelve el mismo.
            return ['status' => 'duplicate', 'request' => EmergencyRequest::withoutGlobalScopes()->where('idempotency_key', $key)->first()];
        }

        // Avisos (fuera de la transacción): al crearla, o si se repite y pasó el intervalo.
        $request = $result['request'];
        if ($request && in_array($result['status'], ['created', 'repeat'], true) && $this->dueForNotification($request)) {
            $this->notify($request);
        }

        return ['status' => $result['status'], 'request' => $request?->fresh()];
    }

    private function dueForNotification(EmergencyRequest $request): bool
    {
        $rounds = (int) $request->notify_rounds; // recién creada, el valor por defecto lo pone la base

        return $rounds === 0
            || ($rounds < self::MAX_ROUNDS && (bool) $request->last_notified_at?->lte(now()->subMinutes(self::RENOTIFY_MINUTES)));
    }

    private function newReference(): string
    {
        do {
            $ref = 'E-'.strtoupper(Str::random(6));
        } while (EmergencyRequest::withoutGlobalScopes()->where('reference', $ref)->exists());

        return $ref;
    }

    /*
    |--------------------------------------------------------------------------
    | DESTINATARIOS Y AVISOS
    |--------------------------------------------------------------------------
    */

    /**
     * Destinatarios reales (relaciones verificables del sistema):
     * - Empresa conservadora: dueño/administradores de ESA empresa, administrativos
     *   activos con permiso de «Órdenes de trabajo» y los técnicos que están de guardia
     *   ahora (si la empresa usa guardias).
     * - Administración del consorcio: usuarios del portal con acceso activo al cliente
     *   del edificio EN ESA EMPRESA y con ese edificio autorizado (PortalAccess).
     * Nunca usuarios de otra empresa ni de otro cliente; el Notifier lo vuelve a verificar.
     *
     * @return Collection<int, array{user: User, audience: string}>
     */
    public function recipients(EmergencyRequest $request): Collection
    {
        $companyId = (int) $request->company_id;
        $rows = collect();

        User::query()->where('company_id', $companyId)->whereIn('role', ['admin', User::ROLE_STAFF])->where('is_super_admin', false)->get()
            ->filter(fn (User $u) => $u->role === 'admin' || Access::can($u, PermissionArea::WorkOrders))
            ->each(fn (User $u) => $rows->push(['user' => $u, 'audience' => 'company']));

        if (PlanGuard::companyAllows($companyId, PlanFeature::OnCall)) {
            collect(app(OnCallService::class)->onDutyAt($companyId, now()))
                ->flatMap(fn ($row) => $row['shifts']->map->user)
                ->filter(fn ($u) => $u && (int) $u->company_id === $companyId)
                ->each(fn (User $u) => $rows->push(['user' => $u, 'audience' => 'technician']));
        }

        $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->find($request->building_id);
        if ($building?->client_id) {
            PortalMembership::where('company_id', $companyId)->where('client_id', $building->client_id)->active()->with('user')->get()
                ->map->user->filter()
                ->filter(fn (User $u) => PortalAccess::buildingIds($u, $companyId)->contains((int) $building->id))
                ->each(fn (User $u) => $rows->push(['user' => $u, 'audience' => 'consortium']));
        }

        return $rows->unique(fn ($row) => $row['user']->id)->values();
    }

    /** Una ronda de avisos (in-app y correo; push después de responder). Registra cada entrega. */
    public function notify(EmergencyRequest $request): int
    {
        $round = (int) $request->notify_rounds + 1;
        $request->forceFill(['notify_rounds' => $round, 'last_notified_at' => now()])->save();

        $company = Company::find($request->company_id);
        $request->load(['building' => fn ($q) => $q->withoutGlobalScopes(), 'elevator' => fn ($q) => $q->withoutGlobalScopes()]);
        $at = $request->created_at->format('d/m/Y H:i');
        $delivered = 0;

        foreach ($this->recipients($request) as ['user' => $user, 'audience' => $audience]) {
            $notification = new EmergencyRequestNotification($request->id, $request->reference, $request->placeLabel(), $at, $round, $this->pathFor($audience, $request, $company), (int) $request->building_id);
            $row = null;
            $failure = null;

            try {
                $row = $this->notifier->sendTo($user, $notification, (int) $request->company_id, mail: true, pushAfterResponse: true);
                $failure = $row ? null : 'no habilitado para recibirlo';
            } catch (Throwable $e) {
                $failure = 'error al registrar el aviso';
                Log::warning('No se pudo avisar una solicitud de ayuda', ['emergency_request_id' => $request->id, 'user_id' => $user->id, 'exception' => $e::class]);
            }

            $delivery = new EmergencyDelivery;
            $delivery->forceFill([
                'company_id' => $request->company_id,
                'emergency_request_id' => $request->id,
                'user_id' => $user->id,
                'audience' => $audience,
                'round' => $round,
                'notification_id' => $row?->id,
                'push_attempted' => $row !== null && $user->canReceivePush() && filled(config('webpush.vapid.public_key')),
                'mail_expected' => $row !== null && filled($user->email),
                'mail_attempts' => $row !== null && filled($user->email) ? 1 : 0,
                'failure' => $failure,
            ]);
            EmergencyDelivery::withoutEvents(fn () => $delivery->save());

            $delivered += $row ? 1 : 0;
        }

        if ($round === 1) {
            $request->forceFill(['recipients_count' => $delivered])->save();
        }

        return $delivered;
    }

    private function pathFor(string $audience, EmergencyRequest $request, ?Company $company): ?string
    {
        return match ($audience) {
            'consortium' => route('portal.emergencies', [], false),
            'technician' => $company ? route('on-call.index', ['company' => $company->slug], false) : null,
            default => parse_url(EmergencyRequestResource::getUrl('view', ['record' => $request->id], panel: 'ascensores_app'), PHP_URL_PATH),
        };
    }

    /**
     * Reintenta los correos fallidos (hasta 3 intentos por entrega), sin duplicar los
     * que ya salieron. Lo corre el scheduler.
     */
    public function retryFailedMail(): int
    {
        $retried = 0;

        EmergencyDelivery::withoutGlobalScopes()->where('mail_expected', true)->where('mail_attempts', '<', 3)
            ->whereNotNull('notification_id')->where('created_at', '>=', now()->subDay())
            ->with(['request' => fn ($q) => $q->withoutGlobalScopes(), 'user'])
            ->orderBy('id')->get()
            ->each(function (EmergencyDelivery $delivery) use (&$retried) {
                $n = DatabaseNotification::find($delivery->notification_id);
                if (! $n || $n->mailed_at || ! $n->mail_failed_at || ! $delivery->user || ! $delivery->request) {
                    return;
                }

                $r = $delivery->request->load(['building' => fn ($q) => $q->withoutGlobalScopes(), 'elevator' => fn ($q) => $q->withoutGlobalScopes()]);
                $notification = new EmergencyRequestNotification($r->id, $r->reference, $r->placeLabel(), $r->created_at->format('d/m/Y H:i'), $delivery->round, $n->data['path'] ?? null, (int) $r->building_id);
                if ($message = $notification->toMail($delivery->user)) {
                    $delivery->forceFill(['mail_attempts' => $delivery->mail_attempts + 1])->save();
                    $this->notifier->mail($delivery->user, [$n->id], $message);
                    $retried++;
                }
            });

        return $retried;
    }

    /** Cierre por la empresa (atendida, falsa alarma o cerrada), con nota. */
    public function close(EmergencyRequest $request, User $actor, string $status, ?string $note): void
    {
        abort_unless(in_array($status, ['attended', 'false_alarm', 'closed'], true), 422);

        DB::transaction(function () use ($request, $actor, $status, $note) {
            $locked = EmergencyRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            abort_unless((int) $locked->company_id === (int) $actor->company_id || $actor->isSuperAdmin(), 404);

            if (! $locked->isOpen()) {
                return;
            }

            $locked->forceFill(['status' => $status, 'closed_at' => now(), 'closed_by' => $actor->id, 'close_note' => $note ? mb_substr(trim($note), 0, 500) : null])->save();
        });
    }
}
