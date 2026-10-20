<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Notifications\MailOnlyNotification;
use App\Services\Backups\BackupManager;
use App\Services\Reports\ReportVideoService;
use App\Support\Auth\UserEmailConflicts;
use App\Support\Diagnostics\ReverbProbe;
use App\Support\Diagnostics\ServerLimits;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Chequeo de producción, SOLO LECTURA: no cambia configuración, no migra, no
 * borra nada, no procesa colas y nunca muestra contraseñas ni claves (solo
 * si están cargadas).
 *
 *   php artisan ascento:check-production
 *   php artisan ascento:check-production --mail-to=vos@tuempresa.com   (manda 1 correo de prueba)
 *
 * Niveles:
 *   ✗ crítico      algo no funciona o hay riesgo de perder datos (sale con error)
 *   ! advertencia  conviene corregirlo; Ascento funciona igual
 *   ? no verificable desde la consola: dice dónde comprobarlo (Forge, panel)
 *   · informativo
 */
class CheckProduction extends Command
{
    protected $signature = 'ascento:check-production {--mail-to= : Envía un correo de prueba a esta dirección}';

    protected $description = 'Verifica (sin modificar nada) lo que Ascento necesita en producción';

    private int $critical = 0;

    private int $warnings = 0;

    private int $unverified = 0;

    public function handle(Migrator $migrator): int
    {
        $https = str_starts_with((string) config('app.url'), 'https://');

        $this->section('App');
        $this->check(app()->environment('production'), 'APP_ENV=production', 'APP_ENV es "'.app()->environment().'": poné APP_ENV=production');
        $this->check(! config('app.debug'), 'APP_DEBUG=false', 'APP_DEBUG está activado: muestra detalles internos ante un error. Poné APP_DEBUG=false');
        $this->check($https, 'APP_URL usa https ('.config('app.url').')', 'APP_URL debe ser la URL pública con https: los enlaces de los correos salen de ahí');
        if ($https) {
            $this->check((bool) config('session.secure'), 'Cookie de sesión solo por https (session.secure activo)', 'SESSION_SECURE_COOKIE está en false: con https la cookie de sesión debería viajar solo cifrada. Quitá la variable (se activa sola con APP_URL https) o poné SESSION_SECURE_COOKIE=true; después php artisan optimize', critical: false);
        }
        $this->check(config('app.name') !== 'Laravel', 'APP_NAME='.config('app.name'), 'APP_NAME es "Laravel": aparece en el título de los correos. Poné APP_NAME=Ascento junto con SESSION_COOKIE y CACHE_PREFIX con los valores de antes, para no cerrar la sesión de todos (docs/operacion-produccion.md)', critical: false);
        $this->info('  · Configuración '.(app()->configurationIsCached() ? 'cacheada: después de cambiar el .env corré php artisan optimize (si no, este chequeo y la app siguen viendo los valores viejos)' : 'sin cachear (en producción conviene php artisan optimize)'));
        $this->checkBuild();

        $this->section('Correo (invitaciones, recuperación de contraseña, avisos)');
        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER={$mailer}", "MAIL_MAILER={$mailer}: los correos NO salen (se escriben en el log). Configurá smtp/ses/postmark/resend");
        if ($mailer === 'smtp') {
            $smtp = config('mail.mailers.smtp');
            $this->check(filled($smtp['host'] ?? null) && ! in_array($smtp['host'], ['127.0.0.1', 'localhost', 'mailpit'], true), 'MAIL_HOST cargado', 'MAIL_HOST vacío o local');
            $this->check(filled($smtp['username'] ?? null) && filled($smtp['password'] ?? null), 'MAIL_USERNAME y MAIL_PASSWORD cargados (no se muestran)', 'Faltan MAIL_USERNAME / MAIL_PASSWORD');
        }
        $this->check(! in_array(config('mail.from.name'), ['Laravel', 'Example', null, ''], true), 'MAIL_FROM_NAME='.config('mail.from.name'), 'MAIL_FROM_NAME es "'.config('mail.from.name').'": los correos llegan con ese remitente. Poné MAIL_FROM_NAME=Ascento', critical: false);
        $from = (string) config('mail.from.address');
        $this->check(filled($from) && ! str_ends_with($from, 'example.com'), "MAIL_FROM_ADDRESS={$from}", 'MAIL_FROM_ADDRESS es el de ejemplo: usá una dirección de un dominio verificado en tu proveedor');
        $this->check(filled(config('app.admin_notification_email')), 'Aviso de empresas nuevas a ASCENTO_ADMIN_NOTIFICATION_EMAIL (no se muestra)', 'ASCENTO_ADMIN_NOTIFICATION_EMAIL vacío: las empresas nuevas solo se avisan en la campanita/push del SuperAdmin, no por correo', critical: false);

        $this->section('Scheduler (backups, videos, exportaciones, recordatorios)');
        try {
            $beat = Cache::get('scheduler:heartbeat');
        } catch (Throwable) {
            $beat = null;
        }
        $age = $beat ? Carbon::parse($beat)->diffInMinutes(now()) : null;
        $this->check($age !== null && $age <= 5, 'schedule:run activo (última corrida hace '.(int) $age.' min)',
            $beat ? 'El scheduler no corre hace '.(int) $age.' min: sin él no hay backups automáticos ni videos comprimidos' : 'No hay señal del scheduler. En Forge: Scheduler → "php artisan schedule:run" cada minuto (sin él no hay backups automáticos)');

        $this->checkQueue();

        $this->section('Archivos');
        $private = storage_path('app/private');
        $this->check(is_dir($private) && is_writable($private), 'Escritura en storage/app/private', 'No se puede escribir en storage/app/private (permisos del usuario forge)');
        $free = @disk_free_space($private ?: storage_path());
        $this->check($free !== false && $free > 2 * 1024 ** 3, 'Espacio libre: '.round(($free ?: 0) / 1024 ** 3, 1).' GB', 'Poco espacio libre: '.round(($free ?: 0) / 1024 ** 3, 1).' GB (backups y exportaciones con fotos pueden pesar)', critical: $free !== false && $free < 512 * 1024 ** 2);

        $this->checkRealtime();
        $this->checkUploads();
        $this->checkBackups();

        $this->section('Push y Telegram (opcionales)');
        $this->info('  · Push (VAPID): '.(filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key')) ? 'configurado' : 'NO configurado (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY)'));
        $this->info('  · Telegram: '.(filled(config('services.telegram.bot_token')) ? 'configurado' : 'no configurado'));

        $this->section('Base de datos');
        try {
            $ran = $migrator->getRepository()->getRan();
            $pending = collect($migrator->getMigrationFiles(database_path('migrations')))->keys()->diff($ran)->values();
            $this->check($pending->isEmpty(), 'Migraciones al día', 'Migraciones pendientes: '.$pending->implode(', '));
        } catch (Throwable $e) {
            $this->check(false, '', 'No se pudo leer el estado de las migraciones ('.$e::class.')');
        }
        try {
            $this->check(UserEmailConflicts::enforced(), 'Un correo = una cuenta: índice único de correos activo',
                'El índice único de correos no está activo (hay cuentas con el mismo correo). Revisalas con php artisan users:email-conflicts');
        } catch (Throwable $e) {
            $this->check(false, '', 'No se pudo revisar el índice de correos ('.$e::class.')');
        }

        if ($to = $this->option('mail-to')) {
            $this->section('Correo de prueba');
            try {
                Notification::route('mail', $to)->notifyNow(new MailOnlyNotification((new MailMessage)
                    ->subject('Prueba de correo - Ascento')->line('Si recibiste este correo, el envío desde Ascento funciona.')));
                $this->info("  ✓ El proveedor aceptó el correo a {$to}. Confirmá que llegó (y que no está en spam).");
            } catch (Throwable $e) {
                $this->check(false, '', 'El proveedor rechazó el correo ('.$e::class.'). Revisá MAIL_*');
            }
        }

        $this->newLine();
        $summary = "Críticos: {$this->critical} · Advertencias: {$this->warnings} · No verificables desde la consola: {$this->unverified}";
        $this->critical === 0 ? $this->info($summary) : $this->error($summary);
        if ($this->unverified) {
            $this->line('  Los "?" no son errores: hay que mirarlos en Forge o en el panel (docs/operacion-produccion.md).');
        }

        return $this->critical === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Assets compilados (sin build, la app del técnico y las páginas públicas se ven sin estilos). */
    private function checkBuild(): void
    {
        $manifest = public_path('build/manifest.json');
        $entries = is_readable($manifest) ? array_keys((array) json_decode((string) file_get_contents($manifest), true)) : [];
        $missing = array_diff(['resources/css/app.css', 'resources/js/app.js', 'resources/css/public.css', 'resources/js/public.js', 'resources/js/remito-pad.js'], $entries);

        $this->check($missing === [], 'Assets compilados (public/build)', $entries === []
            ? 'No hay public/build/manifest.json: falta npm ci && npm run build en el deploy (las páginas se ven sin estilos)'
            : 'Al build le faltan entradas ('.implode(', ', $missing).'): corré npm ci && npm run build');
    }

    private function checkQueue(): void
    {
        $this->section('Colas');
        $connection = (string) config('queue.default');
        $this->info("  · QUEUE_CONNECTION={$connection}. Ninguna función de Ascento necesita un worker: los avisos, correos y trabajos pesados corren al terminar cada request o en el scheduler.");

        if ($connection !== 'database') {
            return;
        }

        try {
            $jobs = DB::table('jobs')->select('queue', 'payload', 'attempts', 'created_at')->orderBy('id')->limit(200)->get();
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            $this->unverifiable('No se pudo leer la tabla jobs (¿falta migrar?)');

            return;
        }

        if ($jobs->isEmpty()) {
            $this->info('  ✓ Sin trabajos en cola · fallidos: '.$failed);

            return;
        }

        // Solo el nombre de la clase y la antigüedad (nunca el contenido del trabajo).
        $groups = $jobs->groupBy(fn ($job) => (string) (json_decode((string) $job->payload, true)['displayName'] ?? 'desconocido'));
        $oldest = Carbon::createFromTimestamp((int) $jobs->min('created_at'));
        foreach ($groups as $name => $items) {
            $this->line('    - '.$items->count().' × '.$name.' (el más viejo: '.Carbon::createFromTimestamp((int) $items->min('created_at'))->diffForHumans().')');
        }

        $stale = $oldest->lt(now()->subMinutes(10));
        $legacy = $groups->has('Filament\\Notifications\\DatabaseNotification');
        $this->check(! $stale, 'Trabajos en cola recientes: '.$jobs->count(),
            $jobs->count().' trabajo(s) esperando desde '.$oldest->diffForHumans().' y nadie los procesa (no hay worker).'
            .($legacy ? ' Los "DatabaseNotification" son avisos de campanita (p. ej. "Falló un backup") que la versión anterior dejaba en cola: esta versión ya no encola. Si querés entregarlos: php artisan queue:work --stop-when-empty (opcional).' : ' No vienen de funciones actuales de Ascento: revisá su origen antes de procesarlos o borrarlos.'),
            critical: false);
        $failed && $this->check(false, '', "Trabajos fallidos: {$failed} (php artisan queue:failed para ver cuáles)", critical: false);
    }

    private function checkRealtime(): void
    {
        $this->section('Tiempo real (Reverb)');
        if (config('broadcasting.default') !== 'reverb') {
            $this->check(false, '', 'BROADCAST_CONNECTION='.config('broadcasting.default').': los avisos no llegan en tiempo real (solo al recargar o cada 2 min). Configurá Reverb (ver docs/operacion-produccion.md)', critical: false);

            return;
        }

        $this->check(filled(config('broadcasting.connections.reverb.key')) && filled(config('broadcasting.connections.reverb.secret')), 'REVERB_APP_KEY y REVERB_APP_SECRET cargados (no se muestran)', 'Faltan REVERB_APP_KEY / REVERB_APP_SECRET', critical: false);
        $this->check((config('broadcasting.connections.reverb.options.scheme') ?? 'https') === 'https', 'REVERB_SCHEME=https', 'REVERB_SCHEME no es https: el navegador no puede abrir wss desde una página https', critical: false);
        $port = (int) config('reverb.servers.reverb.port', 8080);
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        $this->check((bool) $socket, "Proceso de Reverb escuchando en el puerto {$port}", "No hay un proceso de Reverb en el puerto {$port}. En Forge: Application → Laravel Reverb (daemon)", critical: false);
        $socket && fclose($socket);
        // Publica de verdad (como un aviso). Antes se usaba getChannels(): con
        // ningún canal ocupado Reverb responde {"channels":[]} y la librería
        // de Pusher tiraba TypeError aunque la conexión estuviera bien.
        $target = ReverbProbe::target();
        $probe = ReverbProbe::publish();
        $this->check($probe['ok'], "La app publicó un mensaje de prueba en Reverb ({$target})", match ($probe['kind']) {
            'config' => 'La app no pudo armar el cliente de Reverb (configuración, no red)',
            'connection' => "La app no pudo conectarse a Reverb en {$target}",
            'http' => "Reverb o el proxy en {$target} respondió con error",
            default => 'Error de la app al publicar en Reverb',
        }.": {$probe['detail']}. {$probe['hint']}", critical: false);
        $origins = (array) config('reverb.apps.apps.0.allowed_origins');
        $this->check($origins !== ['*'], 'REVERB_ALLOWED_ORIGINS='.implode(',', $origins), 'REVERB_ALLOWED_ORIGINS=* (cualquier sitio puede conectarse). Poné REVERB_ALLOWED_ORIGINS=ascento.online', critical: false);
    }

    /**
     * Límites de subida: la CLI (lo que ve este comando) es solo informativa;
     * lo que importa es PHP-FPM (la web) y Nginx. Se leen sus archivos si se
     * puede; si no, se dice dónde verlo, sin afirmar nada.
     */
    private function checkUploads(): void
    {
        $this->section('Fotos y videos');
        $video = (int) config('media.video_max_mb');
        $need = $video + 10;
        $mb = fn (?float $v) => ServerLimits::label($v);

        $cli = ServerLimits::cli();
        $this->info("  · PHP CLI (este comando, no la web): {$mb($cli['upload'])} por archivo / {$mb($cli['post'])} por envío.");

        $fpm = ServerLimits::fpm(config('ascento-diagnostics.fpm_dir', ServerLimits::fpmDir()));
        if ($fpm === null) {
            $this->unverifiable("No se pudieron leer los archivos de PHP-FPM. Mirá el valor real en el panel (Inicio del SuperAdmin → Subida de archivos) o en Forge → PHP: upload_max_filesize y post_max_size ≥ {$need}M");
        } else {
            $ok = $fpm['upload'] >= $need && $fpm['post'] >= $need;
            $this->check($ok, "PHP-FPM (web), configurado: {$mb($fpm['upload'])} por archivo / {$mb($fpm['post'])} por envío",
                "PHP-FPM (web) acepta {$mb($fpm['upload'])} por archivo / {$mb($fpm['post'])} por envío: los videos de hasta {$video} MB fallan. En Forge: PHP → Max File Upload Size ≥ {$need} (cambia PHP-FPM, la CLI y Nginx).");
            $this->info('  · Leído de los archivos de configuración de PHP-FPM. Si los cambiaste hace poco, el valor en uso se ve en el panel (Inicio del SuperAdmin).');
            if ($fpm['memory'] !== null && $fpm['memory'] < 128) {
                $this->check(false, '', "memory_limit de PHP-FPM es {$mb($fpm['memory'])}: las fotos grandes pueden fallar al procesarse. Recomendado ≥ 256M (Forge → PHP)", critical: false);
            }
        }

        $nginx = ServerLimits::nginx(config('ascento-diagnostics.nginx_files'));
        if ($nginx === null) {
            $this->unverifiable("No se pudo leer la configuración de Nginx. En Forge: sitio → Nginx → client_max_body_size ≥ {$need}M (Forge lo ajusta con PHP → Max File Upload Size)");
        } else {
            $where = $nginx['found'] ? ' (en '.basename((string) $nginx['at']).')' : ' (no está definido: Nginx usa 1 MB)';
            $this->check($nginx['mb'] >= $need, "Nginx client_max_body_size: {$mb($nginx['mb'])}{$where}",
                "Nginx acepta {$mb($nginx['mb'])} por envío{$where}: las subidas más grandes fallan antes de llegar a PHP. En Forge: PHP → Max File Upload Size ≥ {$need}, o client_max_body_size {$need}M; en el Nginx del sitio");
        }

        $ffmpeg = ReportVideoService::ffmpegAvailable();
        $this->check($ffmpeg && ReportVideoService::ffprobeAvailable(), 'FFmpeg y ffprobe disponibles (compresión y duración real de los videos)',
            ($ffmpeg ? 'Falta ffprobe' : 'FFmpeg no está instalado').': los videos se guardan sin comprimir y la duración solo se valida por el encabezado del archivo. Opcional y gratuito: sudo apt install ffmpeg', critical: false);
    }

    private function checkBackups(): void
    {
        $this->section('Backups');

        if (! class_exists(ZipArchive::class)) {
            $this->check(false, '', 'PHP no tiene la extensión zip: no se pueden generar backups (Forge → PHP → extensiones)');

            return;
        }
        $password = filled(config('backup.password'));
        $this->check($password, 'Backups cifrados (BACKUP_ARCHIVE_PASSWORD cargada, no se muestra)', 'BACKUP_ARCHIVE_PASSWORD vacía: los backups quedan SIN cifrar (y no se pueden copiar afuera). Cargala y guardala también fuera del servidor', critical: false);
        if ($password) {
            $this->check(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256), 'Este PHP puede cifrar ZIP con AES-256', 'Este PHP (libzip) no puede cifrar ZIP con AES-256: los backups van a fallar mientras haya contraseña');
        }
        if (in_array(config('database.connections.'.config('database.default').'.driver'), ['mysql', 'mariadb'], true)) {
            $dump = new Process([(string) config('backup.mysqldump'), '--version']);
            $dump->run();
            $this->check($dump->isSuccessful(), 'mysqldump disponible', 'No se encontró mysqldump (BACKUP_MYSQLDUMP): sin él no hay backup de la base');
        }

        try {
            $lastOk = Backup::where('status', Backup::COMPLETED)->latest('completed_at')->first();
            $lastFailed = Backup::where('status', Backup::FAILED)->latest('id')->first();
            $recentFailures = Backup::where('status', Backup::FAILED)->where('created_at', '>', now()->subDays(2))->count();
        } catch (Throwable) {
            $this->check(false, '', 'No se pudo leer la tabla de backups (¿falta migrar?)');

            return;
        }

        $failureDetail = $lastFailed
            ? ' Último intento fallido: '.$lastFailed->created_at->format('d/m/Y H:i').' — '.($lastFailed->error ?: 'sin detalle').' Detalle técnico: grep -i backup storage/logs/laravel.log | tail -20'
            : '';

        if (! $lastOk) {
            $this->check(false, '', 'No hay ningún backup completo.'.$failureDetail.' Después de corregir la causa: php artisan backup:run');
        } else {
            $this->check($lastOk->completed_at->gt(now()->subHours(26)), 'Último backup completo: '.$lastOk->completed_at->format('d/m/Y H:i').' ('.$lastOk->sizeLabel().')',
                'El último backup completo es del '.$lastOk->completed_at->format('d/m/Y H:i').': el automático (03:15) no está corriendo o falla.'.$failureDetail);
            if ($recentFailures && $lastFailed && $lastFailed->created_at->gt($lastOk->completed_at)) {
                $this->check(false, '', "Backups fallidos en los últimos 2 días: {$recentFailures}, después del último completo.".$failureDetail, critical: false);
            } elseif ($recentFailures) {
                $this->info("  · {$recentFailures} backup(s) fallido(s) en los últimos 2 días, pero hubo uno completo después.");
            }
        }

        if ($offsite = BackupManager::offsiteDisk()) {
            $copied = ! empty($lastOk?->summary['offsite']['copied_at']);
            $this->check($copied, "Copia externa (disco {$offsite}): el último backup está copiado",
                'El último backup no tiene copia externa'.(($error = $lastOk?->summary['offsite']['error'] ?? null) ? ": {$error}" : ' todavía'), critical: false);
        } else {
            $this->check(false, '', 'Todos los backups están en este mismo servidor: si se pierde el servidor o el disco, se pierden también. Es opcional pero recomendado: descargá uno por semana desde el panel (Backups) o configurá BACKUP_OFFSITE_DISK (docs/backups.md)', critical: false);
        }
    }

    private function section(string $title): void
    {
        $this->line("<options=bold>{$title}</>");
    }

    private function check(bool $ok, string $good, string $bad, bool $critical = true): void
    {
        if ($ok) {
            $this->info("  ✓ {$good}");
        } elseif ($critical) {
            $this->critical++;
            $this->error("  ✗ {$bad}");
        } else {
            $this->warnings++;
            $this->warn("  ! {$bad}");
        }
    }

    private function unverifiable(string $message): void
    {
        $this->unverified++;
        $this->line("  <fg=cyan>? {$message}</>");
    }
}
