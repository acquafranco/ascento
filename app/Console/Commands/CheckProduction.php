<?php

namespace App\Console\Commands;

use App\Notifications\MailOnlyNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Chequeo de producción, SOLO LECTURA: no cambia configuración, no migra, no
 * borra nada y nunca muestra contraseñas ni claves (solo si están cargadas).
 *
 *   php artisan ascento:check-production
 *   php artisan ascento:check-production --mail-to=vos@tuempresa.com   (manda 1 correo de prueba)
 */
class CheckProduction extends Command
{
    protected $signature = 'ascento:check-production {--mail-to= : Envía un correo de prueba a esta dirección}';

    protected $description = 'Verifica (sin modificar nada) lo que Ascento necesita en producción';

    private int $problems = 0;

    public function handle(Migrator $migrator): int
    {
        $this->line('<options=bold>App</>');
        $this->check(app()->environment('production'), 'APP_ENV=production', 'APP_ENV es "'.app()->environment().'"');
        $this->check(! config('app.debug'), 'APP_DEBUG=false', 'APP_DEBUG está activado: muestra detalles internos ante un error. Poné APP_DEBUG=false');
        $this->check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL usa https ('.config('app.url').')', 'APP_URL debe ser la URL pública con https: los enlaces de los correos salen de ahí');

        $this->line('<options=bold>Correo (invitaciones, recuperación de contraseña, avisos)</>');
        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER={$mailer}", "MAIL_MAILER={$mailer}: los correos NO salen (se escriben en el log). Configurá smtp/ses/postmark/resend");
        if ($mailer === 'smtp') {
            $smtp = config('mail.mailers.smtp');
            $this->check(filled($smtp['host'] ?? null) && ! in_array($smtp['host'], ['127.0.0.1', 'localhost', 'mailpit'], true), 'MAIL_HOST cargado', 'MAIL_HOST vacío o local');
            $this->check(filled($smtp['username'] ?? null) && filled($smtp['password'] ?? null), 'MAIL_USERNAME y MAIL_PASSWORD cargados (no se muestran)', 'Faltan MAIL_USERNAME / MAIL_PASSWORD');
        }
        $from = (string) config('mail.from.address');
        $this->check(filled($from) && ! str_ends_with($from, 'example.com'), "MAIL_FROM_ADDRESS={$from}", 'MAIL_FROM_ADDRESS es el de ejemplo: usá una dirección de un dominio verificado en tu proveedor');

        $this->line('<options=bold>Scheduler (exportaciones, recordatorios, cobranzas)</>');
        try {
            $beat = Cache::get('scheduler:heartbeat');
        } catch (Throwable) {
            $beat = null;
        }
        $age = $beat ? Carbon::parse($beat)->diffInMinutes(now()) : null;
        $this->check($age !== null && $age <= 5, 'schedule:run activo (última corrida hace '.(int) $age.' min)',
            $beat ? 'El scheduler no corre hace '.(int) $age.' min' : 'No hay señal del scheduler. En Forge: Scheduler → "php artisan schedule:run" cada minuto');

        $this->line('<options=bold>Colas</>');
        $this->info('  · Los avisos y correos NO usan colas (se envían al terminar cada request o en el scheduler). QUEUE_CONNECTION='.config('queue.default'));
        if (config('queue.default') === 'database') {
            try {
                $this->info('  · Jobs en cola sin procesar: '.DB::table('jobs')->count().' · fallidos: '.DB::table('failed_jobs')->count());
            } catch (Throwable) {
            }
        }

        $this->line('<options=bold>Archivos</>');
        $private = storage_path('app/private');
        $this->check(is_dir($private) && is_writable($private), "Escritura en {$private}", "No se puede escribir en {$private}");
        $free = @disk_free_space($private ?: storage_path());
        $this->check($free !== false && $free > 2 * 1024 ** 3, 'Espacio libre: '.round(($free ?: 0) / 1024 ** 3, 1).' GB', 'Poco espacio libre: '.round(($free ?: 0) / 1024 ** 3, 1).' GB (las exportaciones con fotos pueden pesar)');

        $this->line('<options=bold>Push y Telegram (opcionales)</>');
        $this->info('  · Push (VAPID): '.(filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key')) ? 'configurado' : 'NO configurado (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY)'));
        $this->info('  · Telegram: '.(filled(config('services.telegram.bot_token')) ? 'configurado' : 'no configurado'));

        $this->line('<options=bold>Base de datos</>');
        try {
            $ran = $migrator->getRepository()->getRan();
            $pending = collect($migrator->getMigrationFiles(database_path('migrations')))->keys()->diff($ran)->values();
            $this->check($pending->isEmpty(), 'Migraciones al día', 'Migraciones pendientes: '.$pending->implode(', '));
        } catch (Throwable $e) {
            $this->check(false, '', 'No se pudo leer el estado de las migraciones ('.$e::class.')');
        }

        if ($to = $this->option('mail-to')) {
            $this->line('<options=bold>Correo de prueba</>');
            try {
                Notification::route('mail', $to)->notifyNow(new MailOnlyNotification((new MailMessage)
                    ->subject('Prueba de correo - Ascento')->line('Si recibiste este correo, el envío desde Ascento funciona.')));
                $this->info("  ✓ El proveedor aceptó el correo a {$to}. Confirmá que llegó (y que no está en spam).");
            } catch (Throwable $e) {
                $this->check(false, '', 'El proveedor rechazó el correo ('.$e::class.'). Revisá MAIL_*');
            }
        }

        $this->newLine();
        $this->problems === 0
            ? $this->info('Sin problemas detectados.')
            : $this->error("Problemas detectados: {$this->problems}");

        return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $ok, string $good, string $bad): void
    {
        if ($ok) {
            $this->info("  ✓ {$good}");
        } else {
            $this->problems++;
            $this->error("  ✗ {$bad}");
        }
    }
}
