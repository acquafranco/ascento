<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\ServiceContactMessage;
use App\Models\Marketplace\ServiceContactRequest;
use App\Models\Marketplace\ServiceProfile;
use App\Models\Marketplace\ServiceProfileReport;
use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Services\Activity\PlatformAudit;
use App\Support\Marketplace\ServiceAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes de contacto a profesionales: la empresa describe lo que
 * necesita, el profesional recibe el aviso y responde; cualquiera de los dos
 * la cierra. Conecta a las partes: sin pagos, contratos ni órdenes de trabajo.
 *
 * - Aislamiento: la empresa solo ve lo que envió; el profesional, solo lo
 *   dirigido a SU perfil; un id o referencia ajena da 404.
 * - Anti-spam: límites por usuario y por empresa, una sola solicitud abierta
 *   por empresa y perfil, y clave de envío (dos clics = una solicitud).
 * - El aviso sale DESPUÉS de guardar: si falla, la solicitud no se duplica.
 * - Nunca se borran (tampoco al suspender u ocultar un perfil).
 */
class ServiceContacts
{
    public const PER_USER_HOUR = 10;

    public const PER_COMPANY_DAY = 30;

    public const MESSAGES_PER_USER_HOUR = 60;

    public const ALREADY_OPEN = 'Ya tenés una solicitud abierta con este profesional: seguí la conversación ahí.';

    public function __construct(private MarketplaceNotifier $notify) {}

    /** @param array{contact_name: string, contact_email: string, contact_phone?: ?string, subject: string, need: string, modality?: ?string, location?: ?string} $data */
    public function create(User $buyer, ServiceProfile $profile, array $data, string $key): ServiceContactRequest
    {
        abort_unless(ServiceAccess::canRequest($buyer), 403);
        $companyId = (int) $buyer->company_id;
        $idempotency = hash('sha256', 'svc|'.$companyId.'|'.$key);

        if ($existing = ServiceContactRequest::where('idempotency_key', $idempotency)->first()) {
            return $existing; // reenvío del mismo formulario
        }
        if (! $profile->isVisible()) {
            throw ValidationException::withMessages(['solicitud' => 'Este perfil no está disponible para recibir solicitudes.']);
        }
        if ((int) $profile->company_id === $companyId) {
            throw ValidationException::withMessages(['solicitud' => 'No podés enviarle una solicitud al perfil de tu propia empresa.']);
        }
        if ($this->hasOpen($companyId, $profile->id)) {
            throw ValidationException::withMessages(['solicitud' => self::ALREADY_OPEN]);
        }
        $this->limit('svc-request:user:'.$buyer->id, self::PER_USER_HOUR, 3600, 'Enviaste muchas solicitudes seguidas. Probá de nuevo en un rato.');
        $this->limit('svc-request:company:'.$companyId, self::PER_COMPANY_DAY, 86400, 'Tu empresa llegó al máximo de solicitudes por hoy. Probá de nuevo mañana.');

        try {
            $request = DB::transaction(function () use ($buyer, $companyId, $profile, $data, $idempotency) {
                // Con el perfil bloqueado: dos envíos simultáneos no abren dos solicitudes.
                ServiceProfile::whereKey($profile->id)->lockForUpdate()->first();
                if ($this->hasOpen($companyId, $profile->id)) {
                    throw ValidationException::withMessages(['solicitud' => self::ALREADY_OPEN]);
                }

                return ServiceContactRequest::create([
                    'reference' => $this->reference(),
                    'service_profile_id' => $profile->id,
                    'company_id' => $companyId,
                    'created_by' => $buyer->id,
                    'buyer_name' => (string) $buyer->company?->name,
                    'profile_name' => $profile->public_name,
                    'contact_name' => trim($data['contact_name']),
                    'contact_email' => mb_strtolower(trim($data['contact_email'])),
                    'contact_phone' => filled($data['contact_phone'] ?? null) ? trim($data['contact_phone']) : null,
                    'subject' => trim($data['subject']),
                    'need' => trim($data['need']),
                    'modality' => in_array($data['modality'] ?? null, array_keys(ServiceProfile::MODALITIES), true) ? $data['modality'] : null,
                    'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
                    'status' => ServiceContactRequest::OPEN,
                    'last_message_at' => now(),
                    'idempotency_key' => $idempotency,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return ServiceContactRequest::where('idempotency_key', $idempotency)->firstOrFail();
        }

        $this->notify->serviceOwner($profile, 'Solicitud de contacto nueva', $request->buyerLabel().' te escribió: «'.Str::limit($request->subject, 80).'». Respondé desde Ascento.', 'new:'.$request->id, true, $request);

        return $request;
    }

    /** Respuesta de una de las partes (la que corresponde al usuario). */
    public function reply(User $user, ServiceContactRequest $request, string $body): ServiceContactMessage
    {
        $side = $this->sideOf($user, $request);
        abort_if($side === null, 404);
        $body = trim($body);
        if (mb_strlen($body) < 2 || mb_strlen($body) > 3000) {
            throw ValidationException::withMessages(['mensaje' => 'Escribí un mensaje (hasta 3.000 caracteres).']);
        }
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['mensaje' => 'Esta solicitud está cerrada.']);
        }
        if ($side === ServiceContactMessage::PROFESSIONAL) {
            $this->ensureProfessionalCanAct($user, $request->profile);
        }
        $this->limit('svc-message:user:'.$user->id, self::MESSAGES_PER_USER_HOUR, 3600, 'Enviaste muchos mensajes seguidos. Probá de nuevo en un rato.', 'mensaje');

        $message = DB::transaction(function () use ($user, $request, $side, $body) {
            $row = ServiceContactRequest::whereKey($request->id)->lockForUpdate()->first();
            if (! $row->isOpen()) {
                throw ValidationException::withMessages(['mensaje' => 'Esta solicitud está cerrada.']);
            }
            $message = $row->messages()->create(['side' => $side, 'user_id' => $user->id, 'author_name' => $user->name, 'body' => $body]);
            $row->forceFill([
                'status' => $side === ServiceContactMessage::PROFESSIONAL ? ServiceContactRequest::ANSWERED : ServiceContactRequest::OPEN,
                'last_message_at' => now(),
            ])->save();

            return $message;
        });

        $request->refresh();
        $key = 'msg:'.$message->id;
        $side === ServiceContactMessage::PROFESSIONAL
            ? $this->notify->serviceBuyer($request, 'Te respondió '.$request->profile_name, 'Respuesta a tu solicitud «'.Str::limit($request->subject, 80).'».', $key)
            : $this->notify->serviceOwner($request->profile, 'Mensaje nuevo de '.$request->buyerLabel(), 'En la solicitud «'.Str::limit($request->subject, 80).'».', $key, true, $request);

        return $message;
    }

    /** Cerrar la solicitud (cualquiera de las dos partes). Queda en el historial. */
    public function close(User $user, ServiceContactRequest $request): void
    {
        $side = $this->sideOf($user, $request);
        abort_if($side === null, 404);
        $changed = ServiceContactRequest::whereKey($request->id)->where('status', '!=', ServiceContactRequest::CLOSED)
            ->update(['status' => ServiceContactRequest::CLOSED, 'closed_by' => $side, 'closed_at' => now(), 'updated_at' => now()]);
        if (! $changed) {
            return;
        }
        $request->refresh();
        $side === ServiceContactMessage::PROFESSIONAL
            ? $this->notify->serviceBuyer($request, 'Solicitud cerrada', $request->profile_name.' cerró la solicitud «'.Str::limit($request->subject, 80).'».', 'closed', mail: false)
            : $this->notify->serviceOwner($request->profile, 'Solicitud cerrada', $request->buyerLabel().' cerró la solicitud «'.Str::limit($request->subject, 80).'».', 'closed:'.$request->id, false, $request);
    }

    /** Reporte de abuso de un perfil (una empresa; uno abierto por empresa y perfil). */
    public function report(User $user, ServiceProfile $profile, string $reason, ?string $details): ServiceProfileReport
    {
        abort_unless(ServiceAccess::canSeeSent($user), 403);
        abort_unless(array_key_exists($reason, ServiceProfileReport::REASONS), 422);
        $open = ServiceProfileReport::where('service_profile_id', $profile->id)->where('company_id', $user->company_id)->where('status', 'open')->first();
        if ($open) {
            return $open;
        }
        $this->limit('svc-report:user:'.$user->id, 5, 3600, 'Enviaste muchos reportes seguidos. Probá de nuevo en un rato.', 'reporte');
        $report = ServiceProfileReport::create([
            'service_profile_id' => $profile->id, 'company_id' => $user->company_id, 'user_id' => $user->id,
            'reason' => $reason, 'details' => filled($details) ? Str::limit(trim($details), 2000, '') : null, 'status' => 'open',
        ]);
        $this->notify->team('Reporte de un perfil profesional', '«'.$profile->public_name.'»: '.$report->reasonLabel().'.', '/admin/marketplace/profesionales/'.$profile->id, 'svc-report:'.$report->id, mail: true);

        return $report;
    }

    /**
     * SuperAdmin: abrir el contenido de una solicitud SOLO con un motivo
     * administrativo, que queda en la auditoría (quién, cuándo y por qué).
     */
    public function adminOpen(User $admin, ServiceContactRequest $request, string $reason): ServiceContactRequest
    {
        abort_unless($admin->isSuperAdmin(), 403);
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Explicá el motivo administrativo (al menos 10 caracteres).']);
        }
        PlatformAudit::record('marketplace.service_request.viewed', $request, ['reason' => trim($reason)], $request->reference);

        return $request->load('messages');
    }

    /** De qué lado está el usuario en esta solicitud (null: de ninguno). */
    public function sideOf(?User $user, ServiceContactRequest $request): ?string
    {
        if ($user === null || $user->isSuperAdmin()) {
            return null;
        }
        if ($request->company_id !== null && (int) $user->company_id === (int) $request->company_id && ! $user->isSupplierUser() && ServiceAccess::canSeeSent($user)) {
            return ServiceContactMessage::COMPANY;
        }
        if ($request->profile && ServiceAccess::owns($user, $request->profile)) {
            return ServiceContactMessage::PROFESSIONAL;
        }

        return null;
    }

    private function ensureProfessionalCanAct(User $user, ServiceProfile $profile): void
    {
        $supplier = $user->isSupplierUser() ? $user->supplier : null;
        if ($profile->moderation === ServiceProfile::SUSPENDED
            || ($supplier && ($supplier->closed_at !== null || in_array($supplier->status, [Supplier::REJECTED, Supplier::SUSPENDED], true)))) {
            throw ValidationException::withMessages(['mensaje' => 'Tu perfil o tu cuenta están suspendidos: no podés responder por ahora.']);
        }
    }

    private function hasOpen(int $companyId, int $profileId): bool
    {
        return ServiceContactRequest::sentBy($companyId)->forProfile($profileId)->where('status', '!=', ServiceContactRequest::CLOSED)->exists();
    }

    private function limit(string $key, int $max, int $seconds, string $message, string $field = 'solicitud'): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([$field => $message]);
        }
        RateLimiter::hit($key, $seconds);
    }

    private function reference(): string
    {
        do {
            $ref = 'P-'.Str::upper(Str::random(8));
        } while (ServiceContactRequest::where('reference', $ref)->exists());

        return $ref;
    }
}
