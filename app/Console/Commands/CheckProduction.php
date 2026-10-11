<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Notifications\MailOnlyNotification;
use App\Services\Reports\ReportVideoService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;
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

        $this->check((bool) config('session.secure') || ! str_starts_with((string) config('app.url'), 'https://'), 'Cookie de sesión solo por https (SESSION_SECURE_COOKIE=true)', 'SESSION_SECURE_COOKIE no está en true: con https la cookie de sesión debería viajar solo cifrada');

        $this->line('<options=bold>Correo (invitaciones, recuperación de contraseña, avisos)</>');
        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER={$mailer}", "MAIL_MAILER={$mailer}: los correos NO salen (se escriben en el log). Configurá smtp/ses/postmark/resend");
        if ($mailer === 'smtp') {
            $smtp = config('mail.mailers.smtp');
            $this->check(filled($smtp['host'] ?? null) && ! in_array($smtp['host'], ['127.0.0.1', 'localhost', 'mailpit'], true), 'MAIL_HOST cargado', 'MAIL_HOST vacío o local');
            $this->check(filled($smtp['username'] ?? null) && filled($smtp['password'] ?? null), 'MAIL_USERNAME y MAIL_PASSWORD cargados (no se muestran)', 'Faltan MAIL_USERNAME / MAIL_PASSWORD');
        }
        $this->check(config('app.name') !== 'Laravel', 'APP_NAME='.config('app.name'), 'APP_NAME es "Laravel": poné APP_NAME=Ascento (aparece en títulos y como remitente)');
        $this->check(! in_array(config('mail.from.name'), ['Laravel', 'Example', null, ''], true), 'MAIL_FROM_NAME='.config('mail.from.name'), 'MAIL_FROM_NAME es "'.config('mail.from.name').'": los correos llegan con ese remitente. Poné MAIL_FROM_NAME=Ascento');
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

        $this->line('<options=bold>Tiempo real (Reverb)</>');
        if (config('broadcasting.default') !== 'reverb') {
            $this->check(false, '', 'BROADCAST_CONNECTION='.config('broadcasting.default').': los avisos no llegan en tiempo real (solo al recargar o cada 2 min). Configurá Reverb (ver docs/operacion-produccion.md)');
        } else {
            $this->check(filled(config('broadcasting.connections.reverb.key')) && filled(config('broadcasting.connections.reverb.secret')), 'REVERB_APP_KEY y REVERB_APP_SECRET cargados (no se muestran)', 'Faltan REVERB_APP_KEY / REVERB_APP_SECRET');
            $this->check((config('broadcasting.connections.reverb.options.scheme') ?? 'https') === 'https', 'REVERB_SCHEME=https', 'REVERB_SCHEME no es https: el navegador no puede abrir wss desde una página https');
            $port = (int) config('reverb.servers.reverb.port', 8080);
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
            $this->check((bool) $socket, "Proceso de Reverb escuchando en el puerto {$port}", "No hay un proceso de Reverb en el puerto {$port}. En Forge: Application → Laravel Reverb (daemon)");
            $socket && fclose($socket);
            try {
                Broadcast::connection('reverb')->getPusher()->getChannels();
                $this->info('  ✓ La app puede publicar en Reverb por '.config('broadcasting.connections.reverb.options.host'));
            } catch (Throwable $e) {
                $this->check(false, '', 'La app no puede publicar en Reverb ('.$e::class.'). Revisá REVERB_HOST/REVERB_PORT y el proxy de Nginx');
            }
            $origins = (array) config('reverb.apps.apps.0.allowed_origins');
            $this->check($origins !== ['*'], 'REVERB_ALLOWED_ORIGINS='.implode(',', $origins), 'REVERB_ALLOWED_ORIGINS=* (cualquier sitio puede conectarse). Poné REVERB_ALLOWED_ORIGINS=ascento.online');
        }

        $this->line('<options=bold>Fotos y videos</>');
        $toMb = fn (string $v) => (int) $v * (str_ends_with(strtoupper($v), 'G') ? 1024 : (str_ends_with(strtoupper($v), 'K') ? 1 / 1024 : 1));
        $upload = $toMb((string) ini_get('upload_max_filesize'));
        $post = $toMb((string) ini_get('post_max_size'));
        $need = (int) config('media.video_max_mb') + 10;
        $this->check($upload >= $need && $post >= $need, "Límite de subida de PHP: {$upload} MB (post {$post} MB)",
            "PHP acepta {$upload} MB por archivo / {$post} MB por envío: los videos de hasta ".config('media.video_max_mb')." MB fallan. En Forge: PHP → upload_max_filesize y post_max_size ≥ {$need}M (y client_max_body_size en Nginx). Este chequeo lee la configuración de la CLI; confirmá también la de PHP-FPM");
        $this->info('  · FFmpeg: '.(ReportVideoService::ffmpegAvailable() ? 'disponible (los videos se comprimen)' : 'no disponible (los videos se guardan sin comprimir; opcional: apt install ffmpeg)'));

        $this->line('<options=bold>Backups</>');
        try {
            $last = Backup::where('status', Backup::COMPLETED)->latest('completed_at')->first();
            $this->check($last && $last->completed_at->gt(now()->subHours(26)), 'Último backup completo: '.($last?->completed_at?->format('d/m/Y H:i') ?? '—').' ('.($last?->sizeLabel() ?? '').')',
                $last ? 'El último backup completo es del '.$last->completed_at->format('d/m/Y H:i').': el automático no está corriendo' : 'No hay ningún backup completo. Corré: php artisan backup:run');
            $failed = Backup::where('status', Backup::FAILED)->where('created_at', '>', now()->subDays(2))->count();
            $failed && $this->check(false, '', "Backups fallidos en los últimos 2 días: {$failed} (ver el panel de Backups)");
        } catch (Throwable) {
            $this->check(false, '', 'No se pudo leer la tabla de backups (¿falta migrar?)');
        }
        $this->check(filled(config('backup.password')), 'Backups cifrados (BACKUP_ARCHIVE_PASSWORD cargada, no se muestra)', 'BACKUP_ARCHIVE_PASSWORD vacía: los backups quedan SIN cifrar. Cargala y guardala también fuera del servidor');
        if (in_array(config('database.connections.'.config('database.default').'.driver'), ['mysql', 'mariadb'], true)) {
            $dump = new Process([(string) config('backup.mysqldump'), '--version']);
            $dump->run();
            $this->check($dump->isSuccessful(), 'mysqldump disponible', 'No se encontró mysqldump (BACKUP_MYSQLDUMP): sin él no hay backup de la base');
        }
        $this->info('  · Los backups quedan en este servidor (storage/app/private/backups): copialos afuera periódicamente (docs/backups.md).');

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
