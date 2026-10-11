<?php

namespace App\Providers;

use App\Http\Controllers\PushSubscriptionController;
use App\Listeners\NotifyNewCompanyRegistration;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Portal\PortalInvitations;
use App\Support\Auth\PasswordPolicy;
use App\Support\Realtime;
use App\Support\RealtimeCounters;
use Carbon\Carbon;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale('es');

        // Una sola política para crear o cambiar contraseñas (ver PasswordPolicy).
        Password::defaults(fn () => PasswordPolicy::defaults());

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
        $workOrderTouched = fn (WorkOrder $order) => RealtimeCounters::touchWorkOrder($order);
        WorkOrder::created($workOrderTouched);
        WorkOrder::updated(function (WorkOrder $order) use ($workOrderTouched) {
            if ($order->wasChanged(['status', 'finished_at', 'started_at'])) {
                $workOrderTouched($order);
            }
        });
        WorkOrder::deleted($workOrderTouched);
        WorkOrder::restored($workOrderTouched);
        DeliveryNote::created(fn (DeliveryNote $note) => RealtimeCounters::touch($note->company_id, [$note->user_id]));
        BuildingVisit::created(fn (BuildingVisit $visit) => RealtimeCounters::touch($visit->company_id, [$visit->user_id]));
        Quote::created(fn (Quote $quote) => RealtimeCounters::touch($quote->company_id));
        Quote::updated(function (Quote $quote) {
            if ($quote->wasChanged(['status', 'valid_until'])) {
                RealtimeCounters::touch($quote->company_id);
            }
        });
        Quote::deleted(fn (Quote $quote) => RealtimeCounters::touch($quote->company_id));

        // Verificación de email (rutas de Breeze): en español y con el diseño de Ascento.
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
            ->subject('Confirmá tu email - Ascento')
            ->greeting('Confirmá tu email')
            ->line('Tocá el botón para confirmar que este email es tuyo.')
            ->action('Confirmar email', $url)
            ->line('El enlace vence en '.config('auth.verification.expire', 60).' minutos. Si venció, pedí otro desde Ascento.')
            ->line('Si no creaste una cuenta en Ascento, ignorá este correo.')
            ->salutation('Ascento'));

        // Empresa nueva: aviso al equipo de Ascento (correo configurable y
        // campanita/push/Telegram de los SuperAdmin), una sola vez.
        Event::listen(Registered::class, [NotifyNewCompanyRegistration::class, 'handle']);

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
