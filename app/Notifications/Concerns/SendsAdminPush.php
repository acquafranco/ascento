<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\TelegramChannel;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Canales de los avisos al administrador: la campanita del panel
 * (database), push a sus dispositivos (si hay claves VAPID) y Telegram (si
 * lo conectó).
 */
trait SendsAdminPush
{
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            filled(config('webpush.vapid.public_key')) ? WebPushChannel::class : null,
            TelegramChannel::enabledFor($notifiable) ? TelegramChannel::class : null,
        ]));
    }
}
