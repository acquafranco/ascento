<?php

namespace App\Listeners;

use App\Models\Company;
use App\Models\User;
use App\Notifications\NewCompanyRegisteredNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Empresa nueva → aviso al equipo de Ascento:
 * - correo a ASCENTO_ADMIN_NOTIFICATION_EMAIL (si está configurado);
 * - campanita, push y Telegram de los SuperAdmin (si los activaron).
 *
 * Una sola vez por empresa (aunque el evento se repita) y después de
 * responder: si el correo falla, el registro igual queda hecho.
 */
class NotifyNewCompanyRegistration
{
    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || ! $user->isAdmin() || ! $user->company_id) {
            return;
        }

        dispatch(fn () => static::notify($user->id))->afterResponse();
    }

    public static function notify(int $userId): void
    {
        $user = User::find($userId);
        $company = $user ? Company::find($user->company_id) : null;
        if (! $company || ! Cache::add('company-registered-notified:'.$company->id, true, now()->addDays(90))) {
            return; // ya avisado (o datos borrados)
        }

        $notification = new NewCompanyRegisteredNotification($company, $user);

        $email = trim((string) config('app.admin_notification_email'));
        if ($email !== '') {
            try {
                // sendNow con el canal explícito: AnonymousNotifiable::notifyNow() no recibe canales.
                Notification::sendNow(Notification::route('mail', $email), $notification, ['mail']);
            } catch (Throwable $e) {
                Log::warning('No se pudo enviar el correo de empresa nueva', ['company_id' => $company->id, 'exception' => $e::class]);
            }
        } else {
            Log::notice('Empresa nueva sin correo de aviso: ASCENTO_ADMIN_NOTIFICATION_EMAIL no está configurado', ['company_id' => $company->id]);
        }

        // Canal por canal: si falla el push, igual llegan la campanita y Telegram.
        User::where('is_super_admin', true)->get()->each(function (User $super) use ($notification, $company) {
            foreach ($notification->via($super) as $channel) {
                try {
                    $super->notifyNow($notification, [$channel]);
                } catch (Throwable $e) {
                    Log::warning('No se pudo avisar a un SuperAdmin de la empresa nueva', ['company_id' => $company->id, 'channel' => class_basename($channel), 'exception' => $e::class]);
                }
            }
        });
    }
}
