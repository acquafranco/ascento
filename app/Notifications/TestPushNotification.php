<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Push de prueba que el técnico se manda a sí mismo desde "Notificaciones".
 */
class TestPushNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('✅ Notificaciones funcionando')
            ->body($notifiable->isAdmin()
                ? 'Así te vamos a avisar cuando terminen un trabajo o carguen un reporte.'
                : 'Así te vamos a avisar cuando te asignen una orden de trabajo.')
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            ->tag('ascento-test')
            ->data(['url' => $notifiable->isAdmin() ? '/admin' : route('dashboard', ['company' => $notifiable->company->slug], absolute: false)])
            ->options(['TTL' => 300]);
    }
}
