<?php

namespace App\Support;

use App\Events\UserNotificationsChanged;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos en tiempo real (Laravel Reverb + Echo).
 *
 * - Un solo canal privado por usuario: "App.Models.User.{id}" (autorizado en
 *   routes/channels.php: solo el propio usuario). Es el canal que ya escucha
 *   la campanita de Filament, así que el mismo evento sirve para admins,
 *   técnicos y clientes del portal.
 * - Se emite al instante (ShouldBroadcastNow): no depende de un worker de colas.
 * - Si Reverb no está configurado o no responde, el aviso igual queda guardado
 *   y aparece al recargar o con el respaldo periódico. Nunca rompe la acción.
 */
class Realtime
{
    public static function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key'));
    }

    /** Configuración de Echo para el navegador (sin secretos: solo la clave pública). */
    public static function echoConfig(): ?array
    {
        if (! static::enabled()) {
            return null;
        }

        $options = config('broadcasting.connections.reverb.options');
        $tls = ($options['scheme'] ?? 'https') === 'https';
        $port = (int) ($options['port'] ?? ($tls ? 443 : 80));

        return [
            'broadcaster' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'wsHost' => $options['host'] ?? request()->getHost(),
            'wsPort' => $port,
            'wssPort' => $port,
            'forceTLS' => $tls,
            'enabledTransports' => ['ws', 'wss'],
            'authEndpoint' => '/broadcasting/auth',
        ];
    }

    /** Avisa al navegador del usuario que cambió su bandeja (aviso nuevo o leído). */
    public static function notificationsChanged(User $user, ?DatabaseNotification $latest = null): void
    {
        if (! static::enabled()) {
            return;
        }

        try {
            broadcast(new UserNotificationsChanged($user, $latest));
        } catch (Throwable $e) {
            Log::warning('No se pudo emitir el aviso en tiempo real', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }
}
