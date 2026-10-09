<?php

namespace App\Services\Notifications;

use App\Enums\PlanFeature;
use App\Models\Company;
use App\Models\PortalMembership;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\MailOnlyNotification;
use App\Support\Portal\PortalAccess;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Único punto de entrega de los avisos internos (AppNotification).
 *
 * Orden y garantías:
 * 1. ¿Le corresponde? Misma empresa, cuenta activa (no desactivada), no
 *    SuperAdmin, empresa con acceso vigente. Clientes: plan con portal y
 *    edificio autorizado AHORA (no al momento del evento).
 * 2. Se guarda el aviso interno. La clave del evento es única por
 *    destinatario: repetir el evento no duplica (ni con dos procesos a la vez).
 * 3. Push y correo, después y aparte: si fallan, el aviso interno queda. El
 *    correo se marca enviado solo si el proveedor lo aceptó; si falla, queda
 *    `mail_failed_at` y un log sin datos personales.
 *
 * Sin colas: el correo sale después de responder (dispatchAfterResponse), igual
 * que los push existentes. No depende de un worker.
 */
class Notifier
{
    /**
     * @param  iterable<User>  $users
     * @return list<DatabaseNotification> los avisos creados (sin los ya enviados ni los no permitidos)
     */
    public function send(iterable $users, AppNotification $notification, int $companyId, bool $mail = true): array
    {
        $created = [];

        foreach ($users as $user) {
            if ($row = $this->sendTo($user, $notification, $companyId, $mail)) {
                $created[] = $row;
            }
        }

        return $created;
    }

    public function sendTo(User $user, AppNotification $notification, int $companyId, bool $mail = true): ?DatabaseNotification
    {
        // Estado actual (no el que tenía el objeto al dispararse el evento).
        $user = User::find($user->id);

        if (! $user || ! $this->mayReceive($user, $notification, $companyId)) {
            return null;
        }

        $row = $this->store($user, $notification, $companyId);

        if (! $row) {
            return null; // ya avisado
        }

        // En pantalla al instante (campanita / bandeja), si hay tiempo real.
        Realtime::notificationsChanged($user, $row);

        if ($notification->wantsPush() && $user->canReceivePush() && filled(config('webpush.vapid.public_key'))) {
            try {
                NotificationFacade::sendNow($user, $notification, [WebPushChannel::class]);
            } catch (Throwable $e) {
                Log::warning('No se pudo enviar el push', ['notification_id' => $row->id, 'user_id' => $user->id, 'exception' => $e::class]);
            }
        }

        if ($mail && ($message = $notification->toMail($user))) {
            $this->mail($user, [$row->id], $message);
        }

        return $row;
    }

    public function mayReceive(User $user, AppNotification $notification, int $companyId): bool
    {
        if ($user->trashed() || $user->isSuperAdmin()) {
            return false;
        }

        // Personal: de esa empresa. Cliente del portal: con acceso activo en esa empresa.
        $belongs = $user->isClientUser()
            ? PortalMembership::where('user_id', $user->id)->where('company_id', $companyId)->active()->exists()
            : (int) $user->company_id === $companyId;

        if (! $belongs) {
            return false;
        }

        $company = Company::find($companyId);

        if (! $company || ! $company->hasActiveAccess()) {
            return false;
        }

        if ($user->isClientUser()) {
            $buildingId = $notification->buildingId();

            return $buildingId !== null
                && $company->plan()->allows(PlanFeature::ClientPortal)
                && PortalAccess::buildingIds($user, $companyId)->contains($buildingId);
        }

        return true;
    }

    private function store(User $user, AppNotification $notification, int $companyId): ?DatabaseNotification
    {
        $key = $notification->dedupeKey();

        if ($key !== null && $user->notifications()->where('dedupe_key', $key)->exists()) {
            return null;
        }

        try {
            $row = new DatabaseNotification;
            $row->forceFill([
                'id' => (string) Str::uuid(),
                'type' => $notification::class,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'data' => $notification->toDatabase($user),
                'company_id' => $companyId,
                'dedupe_key' => $key,
            ])->save();

            return $row;
        } catch (UniqueConstraintViolationException) {
            return null; // otro proceso lo avisó al mismo tiempo
        }
    }

    /**
     * Envía un correo y deja constancia en los avisos internos que cubre.
     *
     * @param  list<string>  $notificationIds
     */
    public function mail(User $user, array $notificationIds, MailMessage $message): void
    {
        $send = function () use ($user, $notificationIds, $message) {
            if (blank($user->email)) {
                return;
            }

            try {
                NotificationFacade::sendNow($user, new MailOnlyNotification($message), ['mail']);
                DatabaseNotification::whereIn('id', $notificationIds)->update(['mailed_at' => now(), 'mail_failed_at' => null]);
            } catch (Throwable $e) {
                DatabaseNotification::whereIn('id', $notificationIds)->update(['mail_failed_at' => now()]);
                Log::warning('No se pudo enviar el correo de un aviso', ['notification_ids' => $notificationIds, 'user_id' => $user->id, 'exception' => $e::class]);
            }
        };

        // Desde la web, después de responder (no demora al usuario).
        app()->runningInConsole() ? $send() : dispatch($send)->afterResponse();
    }
}
