<?php

namespace App\Providers;

use App\Http\Controllers\PushSubscriptionController;
use App\Models\User;
use App\Services\Portal\PortalInvitations;
use App\Support\Realtime;
use Carbon\Carbon;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Subidas temporales de Livewire (panel): alcanza para el video de un
        // reporte (por defecto Livewire corta en 12 MB).
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.max(12288, (int) config('media.video_max_mb') * 1024)]]);

        // Tiempo real: la campanita de Filament usa Echo con la configuración
        // de Reverb, y los avisos guardados por el canal "database" (los que no
        // pasan por Notifier) también se emiten al instante.
        if (Realtime::enabled()) {
            config(['filament.broadcasting.echo' => Realtime::echoConfig()]);
        }
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel === 'database' && $event->notifiable instanceof User) {
                Realtime::notificationsChanged($event->notifiable, $event->response instanceof DatabaseNotification ? $event->response : null);
            }
        });

        // Contadores en tiempo real (inicio del admin y del técnico): cuando
        // cambia algo que se cuenta, se avisa a los admins de la empresa y a
        // los técnicos involucrados (una vez por request; ver RealtimeCounters).
        $workOrderTouched = fn (\App\Models\WorkOrder $order) => \App\Support\RealtimeCounters::touchWorkOrder($order);
        \App\Models\WorkOrder::created($workOrderTouched);
        \App\Models\WorkOrder::updated(function (\App\Models\WorkOrder $order) use ($workOrderTouched) {
            if ($order->wasChanged(['status', 'finished_at', 'started_at'])) {
                $workOrderTouched($order);
            }
        });
        \App\Models\WorkOrder::deleted($workOrderTouched);
        \App\Models\WorkOrder::restored($workOrderTouched);
        \App\Models\DeliveryNote::created(fn (\App\Models\DeliveryNote $note) => \App\Support\RealtimeCounters::touch($note->company_id, [$note->user_id]));
        \App\Models\BuildingVisit::created(fn (\App\Models\BuildingVisit $visit) => \App\Support\RealtimeCounters::touch($visit->company_id, [$visit->user_id]));
        \App\Models\Quote::created(fn (\App\Models\Quote $quote) => \App\Support\RealtimeCounters::touch($quote->company_id));
        \App\Models\Quote::updated(function (\App\Models\Quote $quote) {
            if ($quote->wasChanged(['status', 'valid_until'])) {
                \App\Support\RealtimeCounters::touch($quote->company_id);
            }
        });
        \App\Models\Quote::deleted(fn (\App\Models\Quote $quote) => \App\Support\RealtimeCounters::touch($quote->company_id));

        // Verificación de email (rutas de Breeze): en español y con el diseño de Ascento.
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
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
