<?php

namespace App\Providers;

use App\Http\Controllers\PushSubscriptionController;
use App\Models\User;
use App\Services\Portal\PortalInvitations;
use Carbon\Carbon;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale('es');

        // Verificación de email (rutas de Breeze): en español y con el diseño de Ascento.
        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Confirmá tu email - Ascento')
            ->greeting('Confirmá tu email')
            ->line('Tocá el botón para confirmar que este email es tuyo.')
            ->action('Confirmar email', $url)
            ->line('Si no creaste una cuenta en Ascento, ignorá este correo.')
            ->salutation('Ascento'));

        // Un usuario del portal que elige su contraseña (invitación o
        // recuperación) queda activado y se avisa a los admins de su empresa.
        Event::listen(PasswordReset::class,
            fn (PasswordReset $event) => $event->user instanceof User
                ? PortalInvitations::markActivated($event->user)
                : null);

        // Al cerrar sesión (app del técnico o panel), ESTE dispositivo deja
        // de recibir los avisos de esa cuenta (celulares compartidos).
        Event::listen(Logout::class, function (Logout $event) {
            $endpoint = request()->hasSession()
                ? request()->session()->get(PushSubscriptionController::SESSION_KEY)
                : null;

            if ($endpoint && $event->user instanceof User) {
                $event->user->deletePushSubscription($endpoint);
            }
        });
        Gate::define('view-user-template', function (User $authUser, User $user) {

            // Nunca entre empresas distintas.
            if ($authUser->company_id === null || $authUser->company_id !== $user->company_id) {
                return false;
            }

            // El admin ve las plantillas de su empresa; el resto, solo la propia.
            return $authUser->isAdmin() || $authUser->id === $user->id;
        });
    }
}
