<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

/**
 * Diagnóstico de las notificaciones push (VAPID, URL, dispositivos) y envío
 * de una prueba a un técnico: php artisan push:check --send=tecnico@mail.com
 */
class CheckPushNotifications extends Command
{
    protected $signature = 'push:check {--send= : Email de un técnico al que mandarle un push de prueba}';

    protected $description = 'Revisa la configuración de las notificaciones push y explica qué falta.';

    public function handle(): int
    {
        $problems = 0;
        $public = (string) config('webpush.vapid.public_key');
        $private = (string) config('webpush.vapid.private_key');

        if (app()->configurationIsCached()) {
            $this->warn('• La configuración está cacheada: si cambiaste el .env, corré "php artisan config:clear".');
        }

        if ($public === '' || $private === '') {
            $this->error('✗ Faltan VAPID_PUBLIC_KEY y/o VAPID_PRIVATE_KEY. Sin ellas los técnicos NO ven el botón "Activar notificaciones".');
            $this->line('  Generalas con: php artisan webpush:vapid --show   y pegalas en el .env.');

            return self::FAILURE;
        }

        try {
            VAPID::validate(['subject' => config('webpush.vapid.subject') ?: config('app.url'), 'publicKey' => $public, 'privateKey' => $private]);
            $this->info('• Claves VAPID: válidas');
        } catch (Throwable $e) {
            $this->error('✗ Las claves VAPID no son válidas ('.$e->getMessage().'). Volvé a generarlas juntas con php artisan webpush:vapid --show.');
            $problems++;
        }

        if (! str_starts_with((string) config('webpush.vapid.subject'), 'mailto:') && ! str_starts_with((string) config('webpush.vapid.subject'), 'https://')) {
            $this->warn('• VAPID_SUBJECT debería ser "mailto:tu@email.com".');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->error('✗ APP_URL no es https: los navegadores solo permiten notificaciones en sitios https.');
            $problems++;
        }

        $this->line('• Dispositivos registrados: '.PushSubscription::count());

        if ($email = $this->option('send')) {
            $user = User::where('email', $email)->first();

            if (! $user || ! $user->canReceiveWorkOrderPush()) {
                $this->error("✗ {$email} no es un técnico activo.");

                return self::FAILURE;
            }

            $devices = $user->pushSubscriptions()->count();

            if ($devices === 0) {
                $this->error("✗ {$email} no activó las notificaciones en ningún dispositivo (Inicio o Perfil → Activar notificaciones).");

                return self::FAILURE;
            }

            $user->notify(new TestPushNotification);
            $this->info("• Push de prueba enviado a {$devices} dispositivo(s) de {$email}. Los que estaban vencidos se borraron solos: quedan ".$user->pushSubscriptions()->count().'.');
        }

        if ($problems === 0) {
            $this->info('Todo en orden.');
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
