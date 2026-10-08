<?php

namespace App\Notifications\Concerns;

use NotificationChannels\WebPush\WebPushChannel;

/**
 * Canales de los avisos al administrador: la campanita del panel
 * (database) y, si hay claves VAPID, push a sus dispositivos.
 */
trait SendsAdminPush
{
    public function via(object $notifiable): array
    {
        return filled(config('webpush.vapid.public_key'))
            ? ['database', WebPushChannel::class]
            : ['database'];
    }
}
