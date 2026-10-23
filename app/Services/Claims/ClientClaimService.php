<?php

namespace App\Services\Claims;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Resources\Claims\ClientClaimResource;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\ClientClaim;
use App\Models\ClientClaimDelivery;
use App\Models\ClientClaimEvent;
use App\Models\ClientClaimPhoto;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\PortalMembership;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\ClientClaimNotification;
use App\Services\Notifications\Notifier;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use App\Support\Plans\PlanGuard;
use App\Support\Portal\PortalAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reclamos de clientes desde el portal.
 *
 * - La empresa, el cliente y el edificio salen de las relaciones reales: el
 *   acceso vigente al portal de la persona y los edificios que tiene
 *   autorizados. Nunca de ids de empresa o cliente enviados por el navegador.
 * - Primero se guarda el reclamo; los avisos van después y si fallan no lo
 *   deshacen (se registra el error, sin datos del reclamo).
 * - Todo cambio queda en el historial. Las notas internas nunca son públicas.
 * - Una orden de trabajo se crea solo con la acción explícita, una sola vez.
 */
final class ClientClaimService
{
    public const MAX_PHOTOS = 3;

    /** Reportes del QR: topes por edificio y por empresa (abuso distribuido). */
    public const QR_MAX_PER_BUILDING_HOUR = 10;

    public const QR_MAX_PER_COMPANY_HOUR = 60;

    /** El mismo texto del mismo edificio en estos minutos es el mismo reporte (doble envío). */
    public const QR_DUPLICATE_MINUTES = 10;

    public function __construct(private Notifier $notifier) {}

    /** ¿Puede esta persona del portal presentar reclamos en su empresa actual? */
    public static function portalCanClaim(?User $user): bool
    {
        $companyId = $user?->isClientUser() ? PortalAccess::currentCompanyId($user) : null;

        return $companyId !== null && PlanGuard::companyAllows($companyId, PlanFeature::ClientPortal);
    }

    /**
     * @param  array{building_id: int, elevator_id?: ?int, category: string, description: string, occurred_at?: ?string, contact_phone?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function create(User $user, array $data, array $photos = []): ClientClaim
    {
        abort_unless(self::portalCanClaim($user), 403);
        $companyId = (int) PortalAccess::currentCompanyId($user);
        $membership = PortalAccess::currentMembership($user);
        abort_unless($membership !== null, 403);

        // Edificio: de la empresa actual, del cliente de esta persona y autorizado para ella.
        $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->find((int) $data['building_id']);
        if (! $building || (int) $building->client_id !== (int) $membership->client_id || ! PortalAccess::buildingIds($user, $companyId)->contains((int) $building->id)) {
            throw ValidationException::withMessages(['building_id' => 'Elegí uno de tus edificios.']);
        }

        $elevator = null;
        if (! empty($data['elevator_id'])) {
            $elevator = Elevator::withoutGlobalScopes()->where('building_id', $building->id)->where('is_active', true)->find((int) $data['elevator_id']);
            if (! $elevator) {
                throw ValidationException::withMessages(['elevator_id' => 'Ese ascensor no es de este edificio.']);
            }
        }

        $stored = [];
        try {
            $claim = DB::transaction(function () use ($user, $data, $companyId, $membership, $building, $elevator, $photos, &$stored) {
                // Número correlativo por empresa: se serializa con el registro de la empresa.
                Company::whereKey($companyId)->lockForUpdate()->first();
                $number = (int) ClientClaim::withoutGlobalScopes()->where('company_id', $companyId)->max('number') + 1;

                $claim = new ClientClaim;
                $claim->forceFill([
                    'company_id' => $companyId,
                    'number' => $number,
                    'client_id' => (int) $membership->client_id,
                    'building_id' => $building->id,
                    'elevator_id' => $elevator?->id,
                    'created_by' => $user->id,
                    'category' => $data['category'],
                    'description' => trim($data['description']),
                    'occurred_at' => $data['occurred_at'] ?? null,
                    'contact_phone' => filled($data['contact_phone'] ?? null) ? trim($data['contact_phone']) : null,
                    'status' => ClientClaim::NEW,
                ]);
                ClientClaim::withoutEvents(fn () => $claim->save());

                $this->event($claim, $user, 'created', public: true);

                foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $photo) {
                    $ext = match ($photo->getMimeType()) {
                        'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
                    };
                    $path = $photo->storeAs("client-claims/{$companyId}/{$claim->id}", Str::random(32).'.'.$ext, 'local');
                    $stored[] = $path;
                    (new ClientClaimPhoto)->forceFill([
                        'company_id' => $companyId, 'client_claim_id' => $claim->id, 'path' => $path,
                        'mime' => (string) $photo->getMimeType(), 'size' => (int) $photo->getSize(),
                    ])->save();
                }

                return $claim;
            });
        } catch (Throwable $e) {
            // Si algo falló, no quedan fotos sueltas.
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $this->notifyCompany($claim);

        return $claim;
    }

    /* ===================== REPORTES DESDE EL QR (CONECTA) ===================== */

    /**
     * ¿Este QR recibe reportes ahora? QR y edificio activos, empresa con acceso,
     * plan con QR y con reportes, y la empresa no los apagó.
     */
    public static function qrAvailableFor(?BuildingPublicPage $page): bool
    {
        if (! $page || ! $page->is_active) {
            return false;
        }

        $company = Company::find($page->company_id);
        $building = Building::withoutGlobalScopes()->where('company_id', $page->company_id)->whereNull('deleted_at')->find($page->building_id);

        return $company && $building
            && $company->hasActiveAccess()
            && (bool) $company->qr_reports_enabled
            && PlanGuard::for($company)->allows(PlanFeature::PublicQr)
            && PlanGuard::for($company)->allows(PlanFeature::QrReports);
    }

    /** Ascensores que se pueden elegir: solo si el QR publica los equipos. */
    public static function qrElevators(BuildingPublicPage $page): Collection
    {
        if (! $page->show_elevators) {
            return collect();
        }

        return Elevator::withoutGlobalScopes()->where('company_id', $page->company_id)->where('building_id', $page->building_id)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label', 'kind']);
    }

    /**
     * Registra un reporte desde el QR. Primero se guarda (en una transacción) y
     * después se avisa: si nadie está conectado o un aviso falla, el reporte
     * queda igual. Empresa, edificio y cliente salen del QR, nunca del navegador.
     *
     * @param  array{category: string, description: string, elevator_id?: mixed}  $data
     * @return array{status: string, claim: ?ClientClaim} status: created | duplicate | limited | unavailable
     */
    public function createFromQr(BuildingPublicPage $page, array $data, ?UploadedFile $photo, string $idempotencyKey, ?string $ip): array
    {
        if (! self::qrAvailableFor($page)) {
            return ['status' => 'unavailable', 'claim' => null];
        }

        $companyId = (int) $page->company_id;
        $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->findOrFail($page->building_id);
        // Un ascensor que no es de este edificio (o no se publica) se ignora: el reporte no se pierde.
        $elevator = is_scalar($data['elevator_id'] ?? null) && ctype_digit((string) $data['elevator_id'])
            ? self::qrElevators($page)->firstWhere('id', (int) $data['elevator_id'])
            : null;
        $description = trim(preg_replace('/\s+/u', ' ', (string) $data['description']));

        $stored = [];
        try {
            $result = DB::transaction(function () use ($companyId, $building, $elevator, $data, $description, $photo, $idempotencyKey, $ip, &$stored) {
                // Serializa por empresa (número correlativo y topes).
                Company::whereKey($companyId)->lockForUpdate()->first();

                if ($existing = ClientClaim::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first()) {
                    return ['status' => 'duplicate', 'claim' => $existing];
                }

                $recent = fn () => ClientClaim::withoutGlobalScopes()->where('origin', ClientClaim::ORIGIN_QR)->where('created_at', '>=', now()->subHour());
                $same = $recent()->where('company_id', $companyId)->where('building_id', $building->id)->where('category', $data['category'])
                    ->where('created_at', '>=', now()->subMinutes(self::QR_DUPLICATE_MINUTES))->get()
                    ->first(fn (ClientClaim $c) => mb_strtolower($c->description) === mb_strtolower($description));
                if ($same) {
                    return ['status' => 'duplicate', 'claim' => $same];
                }

                if ($recent()->where('building_id', $building->id)->count() >= self::QR_MAX_PER_BUILDING_HOUR
                    || $recent()->where('company_id', $companyId)->count() >= self::QR_MAX_PER_COMPANY_HOUR) {
                    return ['status' => 'limited', 'claim' => null];
                }

                $claim = new ClientClaim;
                $claim->forceFill([
                    'company_id' => $companyId,
                    'number' => (int) ClientClaim::withoutGlobalScopes()->where('company_id', $companyId)->max('number') + 1,
                    'client_id' => $building->client_id,
                    'building_id' => $building->id,
                    'elevator_id' => $elevator?->id,
                    'created_by' => null,
                    'origin' => ClientClaim::ORIGIN_QR,
                    'category' => $data['category'],
                    'description' => $description,
                    'status' => ClientClaim::NEW,
                    'idempotency_key' => $idempotencyKey,
                    'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
                ]);
                // Sin eventos: con una sesión abierta de otra empresa, BelongsToCompany pondría la suya.
                ClientClaim::withoutEvents(fn () => $claim->save());

                $this->event($claim, null, 'qr_created', public: true);

                if ($photo) {
                    $stored[] = $this->storePhoto($claim, $photo);
                }

                return ['status' => 'created', 'claim' => $claim];
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            if ($e instanceof UniqueConstraintViolationException) {
                return ['status' => 'duplicate', 'claim' => ClientClaim::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first()];
            }
            Log::warning('No se pudo registrar un reporte del QR', ['building_page_id' => $page->id, 'exception' => $e::class]);
            throw $e;
        }

        if ($result['status'] === 'created') {
            $this->notifyQrReport($result['claim']);
        }

        return $result;
    }

    private function storePhoto(ClientClaim $claim, UploadedFile $photo): string
    {
        $mime = (string) $photo->getMimeType(); // por el contenido, no por el nombre
        $ext = match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        $path = $photo->storeAs("client-claims/{$claim->company_id}/{$claim->id}", Str::random(40).'.'.$ext, 'local');
        (new ClientClaimPhoto)->forceFill([
            'company_id' => $claim->company_id, 'client_claim_id' => $claim->id, 'path' => $path,
            'mime' => $mime, 'size' => (int) $photo->getSize(),
        ])->save();

        return $path;
    }

    /**
     * Técnicos con una asignación real en ese edificio (mantenimiento o
     * inspección), activos y de la misma empresa.
     */
    public function buildingTechnicians(ClientClaim $claim): Collection
    {
        return User::query()->where('company_id', $claim->company_id)->where('role', 'technician')->whereNull('deleted_at')
            ->whereIn('id', DB::table('building_user')->where('building_id', $claim->building_id)->select('user_id'))
            ->get();
    }

    /**
     * Avisos de un reporte del QR: a la empresa (panel) y a los técnicos del
     * edificio (su pantalla del reporte). Cada intento queda registrado; nada
     * de esto confirma que alguien lo vio.
     */
    private function notifyQrReport(ClientClaim $claim): void
    {
        $claim->load(['building', 'elevator']);
        $company = Company::find($claim->company_id);
        $at = $claim->created_at->format('d/m/Y H:i');
        $sent = 0;
        $rows = $this->companyRecipients($claim)->map(fn (User $u) => [$u, 'company', $this->panelPath($claim)])
            ->concat($this->buildingTechnicians($claim)->map(fn (User $u) => [$u, 'technician', $company ? route('qr-reports.technician', ['company' => $company->slug, 'claim' => $claim->id], false) : null]));

        foreach ($rows->unique(fn ($row) => $row[0]->id) as [$user, $audience, $path]) {
            $row = null;
            $failure = null;
            try {
                $row = $this->notifier->sendTo($user, $this->notification($claim, 'qr_created', $path, $at), (int) $claim->company_id, mail: true, pushAfterResponse: true);
                $failure = $row ? null : 'no habilitado para recibirlo';
            } catch (Throwable $e) {
                $failure = 'error al registrar el aviso';
                Log::warning('No se pudo avisar un reporte del QR', ['client_claim_id' => $claim->id, 'user_id' => $user->id, 'exception' => $e::class]);
            }

            (new ClientClaimDelivery)->forceFill([
                'company_id' => $claim->company_id, 'client_claim_id' => $claim->id, 'user_id' => $user->id,
                'audience' => $audience, 'notification_id' => $row?->id, 'failure' => $failure, 'created_at' => now(),
            ])->save();
            $sent += $row ? 1 : 0;
        }

        $this->event($claim, null, 'notified', body: $sent === 0
            ? 'No había nadie habilitado para recibir el aviso. El reporte quedó registrado.'
            : 'Aviso registrado en Ascento para '.$sent.' '.($sent === 1 ? 'persona' : 'personas').'. Esto no confirma que lo hayan visto.', public: false);
    }

    /** Compartir un reporte del QR con la administración del edificio (o dejar de hacerlo). */
    public function shareWithClient(ClientClaim $claim, User $actor, bool $shared): ClientClaim
    {
        $this->ensureManager($claim, $actor);
        abort_unless($claim->isFromQr(), 422);

        $changed = DB::transaction(function () use ($claim, $actor, $shared) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            if ((bool) $fresh->shared_with_client === $shared) {
                return null;
            }
            $fresh->forceFill(['shared_with_client' => $shared]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'shared', null, $shared ? 'on' : 'off');

            return $fresh;
        });

        if ($changed && $shared) {
            $changed->load(['building', 'elevator']);
            foreach (self::portalViewers($changed) as $viewer) {
                $this->safely(fn () => $this->notifier->sendTo($viewer, $this->notification($changed, 'qr_shared', route('portal.claims.show', $changed->id, false)), (int) $changed->company_id, mail: false));
            }
        }

        return ($changed ?? $claim)->fresh();
    }

    /** Publicar el estado del reporte en el pasaporte público del QR (sin la descripción ni fotos). */
    public function publishOnQr(ClientClaim $claim, User $actor, bool $public): ClientClaim
    {
        $this->ensureManager($claim, $actor);

        DB::transaction(function () use ($claim, $actor, $public) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            if ((bool) $fresh->public_on_qr === $public) {
                return;
            }
            $fresh->forceFill(['public_on_qr' => $public]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'published', null, $public ? 'on' : 'off');
        });

        return $claim->fresh();
    }

    /**
     * Personas del portal que ven este reclamo: quien lo presentó (portal) o, si
     * es del QR y está compartido, quienes tienen ese edificio autorizado.
     *
     * @return Collection<int, User>
     */
    public static function portalViewers(ClientClaim $claim): Collection
    {
        if (! $claim->isFromQr()) {
            return collect([$claim->created_by ? User::find($claim->created_by) : null])->filter()->values();
        }
        if (! $claim->shared_with_client) {
            return collect();
        }

        return PortalMembership::where('company_id', $claim->company_id)->where('client_id', $claim->client_id)->active()->with('user')->get()
            ->map->user->filter(fn (?User $u) => $u && PortalAccess::buildingIds($u, (int) $claim->company_id)->contains((int) $claim->building_id))
            ->values();
    }

    /** ¿Esta persona del portal puede ver este reclamo? (los suyos, o los del QR compartidos de sus edificios). */
    public static function portalCanSee(User $user, ClientClaim $claim): bool
    {
        $companyId = PortalAccess::currentCompanyId($user);
        if ($companyId === null || (int) $claim->company_id !== (int) $companyId) {
            return false;
        }

        return $claim->isFromQr()
            ? $claim->shared_with_client && PortalAccess::buildingIds($user, $companyId)->contains((int) $claim->building_id)
            : (int) $claim->created_by === (int) $user->id;
    }

    /** Personas de la empresa que reciben el aviso: dueño y administrativos con «Órdenes de trabajo». */
    public function companyRecipients(ClientClaim $claim): Collection
    {
        return User::query()->where('company_id', $claim->company_id)->whereIn('role', ['admin', User::ROLE_STAFF])
            ->where('is_super_admin', false)->whereNull('deleted_at')->get()
            ->filter(fn (User $u) => $u->role === 'admin' || (Access::isActiveStaff($u) && Access::can($u, PermissionArea::WorkOrders)))
            ->values();
    }

    private function notifyCompany(ClientClaim $claim): void
    {
        $claim->load(['building', 'elevator']);
        foreach ($this->companyRecipients($claim) as $user) {
            $this->safely(fn () => $this->notifier->sendTo($user, $this->notification($claim, 'created', $this->panelPath($claim)), (int) $claim->company_id, mail: true, pushAfterResponse: true));
        }
    }

    private function notifyCreator(ClientClaim $claim, string $kind, string $extra, string $key): void
    {
        $creator = $claim->created_by ? User::find($claim->created_by) : null;
        if ($creator?->isClientUser()) {
            $claim->load(['building', 'elevator']);
            $this->safely(fn () => $this->notifier->sendTo($creator, $this->notification($claim, $kind, route('portal.claims.show', $claim->id), $extra, $key), (int) $claim->company_id, mail: false, pushAfterResponse: true));
        }
    }

    private function notification(ClientClaim $claim, string $kind, ?string $target, string $extra = '', string $key = ''): ClientClaimNotification
    {
        return new ClientClaimNotification($kind, $claim->id, $claim->reference(), $claim->placeLabel(), $claim->categoryLabel(), $target, (int) $claim->building_id, $extra, $key);
    }

    private function panelPath(ClientClaim $claim): ?string
    {
        try {
            return ClientClaimResource::getUrl('view', ['record' => $claim->id]);
        } catch (Throwable) {
            return null;
        }
    }

    /** Un aviso que falla no deshace nada: se registra sin datos del reclamo. */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar un reclamo', ['exception' => $e::class]);
        }
    }

    /* ======================== GESTIÓN (EMPRESA) ======================== */

    private function ensureManager(ClientClaim $claim, User $actor): void
    {
        abort_unless(
            Access::panelUser($actor) && Access::can($actor, PermissionArea::WorkOrders, PermissionArea::MANAGE)
            && ($actor->isSuperAdmin() ? (int) CompanyContext::currentId() === (int) $claim->company_id : (int) $actor->company_id === (int) $claim->company_id),
            403,
        );
    }

    /**
     * El cambio de estado lo ve el cliente (sin la nota interna). Resolver o
     * cerrar exige el motivo, que queda en el historial con quién y cuándo.
     * «Asociado a una orden» lo pone la acción de crear la orden.
     */
    public function changeStatus(ClientClaim $claim, User $actor, string $status, ?string $reason = null): ClientClaim
    {
        abort_unless(array_key_exists($status, ClientClaim::STATUSES), 422);
        $this->ensureManager($claim, $actor);
        if ($status === ClientClaim::LINKED && ! $claim->work_order_id) {
            throw ValidationException::withMessages(['status' => 'Para asociarlo a una orden, usá «Crear orden de trabajo».']);
        }
        $reason = filled($reason) ? $this->text((string) $reason) : null;
        if (in_array($status, ClientClaim::NEEDS_REASON, true) && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Contá en qué se basa: queda en el historial.']);
        }

        $changed = DB::transaction(function () use ($claim, $actor, $status, $reason) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            if ($fresh->status === $status) {
                return null;
            }
            $from = $fresh->status;
            $fresh->forceFill([
                'status' => $status,
                'resolved_at' => $status === 'resolved' ? now() : ($status === 'closed' ? $fresh->resolved_at : null),
                'closed_at' => $status === 'closed' ? now() : null,
            ]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'status', $from, $status, $reason, public: true);

            return $fresh;
        });

        if ($changed && ! $changed->isFromQr()) {
            $this->notifyCreator($changed, 'status', 'Ahora: '.$changed->statusLabel(), $status.':'.$changed->events()->count());

            return $changed;
        }

        return $claim->fresh();
    }

    /** Responsable: dueño o administrativo vigente de la MISMA empresa (o nadie). */
    public function assign(ClientClaim $claim, User $actor, ?int $userId): ClientClaim
    {
        $this->ensureManager($claim, $actor);
        $assignee = null;
        if ($userId) {
            $assignee = User::query()->where('company_id', $claim->company_id)->whereIn('role', ['admin', User::ROLE_STAFF])->whereNull('deleted_at')->find($userId);
            abort_unless($assignee && ($assignee->role === 'admin' || Access::isActiveStaff($assignee)), 422);
        }

        DB::transaction(function () use ($claim, $actor, $assignee) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            $fresh->forceFill(['assigned_to' => $assignee?->id]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'assigned', null, $assignee?->name);
        });

        if ($assignee && ! $assignee->is($actor)) {
            $claim->load(['building', 'elevator']);
            $this->safely(fn () => $this->notifier->sendTo($assignee, $this->notification($claim, 'assigned', $this->panelPath($claim), '', (string) now()->timestamp), (int) $claim->company_id, mail: false));
        }

        return $claim->fresh();
    }

    /** Nota interna: nunca la ve el cliente. */
    public function addNote(ClientClaim $claim, User $actor, string $body): void
    {
        $this->ensureManager($claim, $actor);
        $this->event($claim, $actor, 'note', body: $this->text($body), public: false);
    }

    /** Respuesta al cliente: la ve en su portal y le llega un aviso. */
    public function reply(ClientClaim $claim, User $actor, string $body): void
    {
        $this->ensureManager($claim, $actor);
        $event = $this->event($claim, $actor, 'reply', body: $this->text($body), public: true);
        if (! $claim->isFromQr()) {
            $this->notifyCreator($claim, 'reply', 'Tenés una respuesta', (string) $event->id);
        }
    }

    /**
     * Crea la orden de trabajo del reclamo (acción explícita). Si ya tiene
     * una, devuelve esa: nunca dos órdenes por el mismo reclamo.
     */
    public function createWorkOrder(ClientClaim $claim, User $actor): WorkOrder
    {
        $this->ensureManager($claim, $actor);

        return DB::transaction(function () use ($claim, $actor) {
            $fresh = ClientClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            if ($fresh->work_order_id && ($existing = WorkOrder::withoutGlobalScopes()->find($fresh->work_order_id))) {
                return $existing;
            }

            $order = new WorkOrder;
            $order->forceFill([
                'company_id' => $fresh->company_id,
                'building_id' => $fresh->building_id,
                'type' => 'claim',
                'status' => 'pending',
                'priority' => $fresh->category === 'stopped' ? 'urgent' : ($fresh->category === 'request' ? 'medium' : 'high'),
                'unit' => $fresh->elevator?->label,
                'notes' => ($fresh->isFromQr() ? 'Reporte del QR ' : 'Reclamo ').$fresh->reference().' ('.$fresh->categoryLabel().'): '.$fresh->description,
                // Aviso = cuándo llegó este reclamo a Ascento (no occurred_at, que es cuándo pasó según quien reclama).
                'call_received_at' => $fresh->created_at,
                'call_source' => $fresh->isFromQr() ? 'qr' : 'portal',
            ])->save();

            $from = $fresh->status;
            // Abierto: pasa a «Asociado a una orden de trabajo». Uno ya cerrado no se reabre solo.
            $fresh->forceFill(['work_order_id' => $order->id, 'status' => $fresh->isOpen() ? ClientClaim::LINKED : $fresh->status]);
            ClientClaim::withoutEvents(fn () => $fresh->save());
            $this->event($fresh, $actor, 'work_order', null, (string) $order->id);
            if ($from !== $fresh->status) {
                $this->event($fresh, $actor, 'status', $from, $fresh->status, public: true);
            }

            return $order;
        });
    }

    private function text(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['body' => 'Escribí un texto de hasta 2000 caracteres.']);
        }

        return $body;
    }

    private function event(ClientClaim $claim, ?User $user, string $type, ?string $from = null, ?string $to = null, ?string $body = null, bool $public = false): ClientClaimEvent
    {
        $event = new ClientClaimEvent;
        $event->forceFill([
            'company_id' => $claim->company_id, 'client_claim_id' => $claim->id, 'user_id' => $user?->id,
            'type' => $type, 'from' => $from, 'to' => $to, 'body' => $body, 'public' => $public, 'created_at' => now(),
        ])->save();

        return $event;
    }
}
