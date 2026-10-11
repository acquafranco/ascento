<?php

namespace App\Services\Backups;

use App\Models\Backup;
use App\Models\User;
use App\Notifications\MailOnlyNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Backups globales restaurables de Ascento.
 *
 * Contenido del ZIP (cifrado AES-256 si hay BACKUP_ARCHIVE_PASSWORD):
 * - database.sql  → volcado completo y consistente (mysqldump
 *                   --single-transaction; SQLite: volcado SQL equivalente).
 * - files/...     → storage/app/private y storage/app/public (sin exports,
 *                   temporales ni backups).
 * - manifest.json → fecha, motor, filas por tabla, archivos, sha256 de la base
 *                   y archivos que la base menciona pero no estaban (control
 *                   de coherencia entre la base y los archivos).
 *
 * La contraseña de la base nunca va en la línea de comandos ni en los logs
 * (archivo temporal de opciones con permisos 0600).
 *
 * Copia externa (BACKUP_OFFSITE_DISK): al terminar, el ZIP cifrado se sube a
 * ese disco y se comprueba el tamaño. El resultado queda en summary.offsite
 * (copiado o el error); si falla se avisa y se reintenta con backup:process.
 */
class BackupManager
{
    private const REFERENCED_FILES = [
        ['report_photos', 'path'], ['report_photos', 'thumb_path'], ['elevator_documents', 'path'],
        ['report_videos', 'path'], ['companies', 'logo'],
    ];

    public function request(string $type, ?User $user = null): Backup
    {
        $backup = new Backup;
        $backup->forceFill(['type' => $type, 'status' => Backup::REQUESTED, 'requested_by' => $user?->id])->save();

        return $backup;
    }

    /** Genera un backup (lo corre la CLI / el scheduler; nunca un request web). */
    public function run(Backup $backup): Backup
    {
        $taken = Backup::whereKey($backup->id)->where('status', Backup::REQUESTED)
            ->update(['status' => Backup::RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        if (! $taken) {
            return $backup->fresh();
        }

        $work = storage_path('app/backups-tmp/'.$backup->id.'-'.Str::random(8));
        File::ensureDirectoryExists($work, 0700);
        $relative = config('backup.path').'/ascento-backup-'.now()->format('Ymd-His').'-'.$backup->id.'.zip';
        $zipPath = Storage::disk('local')->path($relative);
        File::ensureDirectoryExists(dirname($zipPath), 0700);
        $step = 'volcado de la base';

        try {
            $sql = $work.'/database.sql';
            $this->dumpDatabase($sql);

            $step = 'conteo de filas';

            $manifest = [
                'app' => 'Ascento',
                'created_at' => now()->toIso8601String(),
                'backup_id' => $backup->id,
                'driver' => DB::connection()->getDriverName(),
                'database_sha256' => hash_file('sha256', $sql),
                'tables' => $this->tableCounts(),
                'files' => 0,
                'files_bytes' => 0,
                'missing_referenced_files' => [],
            ];

            $step = 'armado del ZIP';
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo del backup.');
            }

            $password = (string) config('backup.password');
            // Si este PHP (libzip) no puede cifrar, se corta: nunca un backup
            // marcado "cifrado" que en realidad no lo está.
            $encrypt = function (string $name) use ($zip, $password) {
                if ($password !== '' && ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) {
                    throw new RuntimeException('Este PHP no puede cifrar el ZIP con AES-256 (libzip sin cifrado). Con BACKUP_ARCHIVE_PASSWORD cargada no se genera un backup sin cifrar.');
                }
            };

            $zip->addFile($sql, 'database.sql');
            $encrypt('database.sql');

            $inArchive = [];
            foreach ($this->files() as [$absolute, $name]) {
                $zip->addFile($absolute, 'files/'.$name);
                $encrypt('files/'.$name);
                $inArchive[$name] = true;
                $manifest['files']++;
                $manifest['files_bytes'] += (int) filesize($absolute);
            }

            $manifest['missing_referenced_files'] = $this->missingReferences($inArchive);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $encrypt('manifest.json');

            if (! $zip->close()) {
                throw new RuntimeException('No se pudo cerrar el archivo del backup.');
            }

            $step = 'registro del backup completo';
            $backup->forceFill([
                'status' => Backup::COMPLETED,
                'path' => $relative,
                'size' => filesize($zipPath),
                'checksum' => hash_file('sha256', $zipPath),
                'encrypted' => $password !== '',
                'summary' => [
                    'tables' => count($manifest['tables']),
                    'rows' => array_sum($manifest['tables']),
                    'files' => $manifest['files'],
                    'files_bytes' => $manifest['files_bytes'],
                    'missing_referenced_files' => count($manifest['missing_referenced_files']),
                ],
                'completed_at' => now(),
            ])->save();

            $step = 'copia externa';
            $this->copyOffsite($backup);
        } catch (Throwable $e) {
            @unlink($zipPath);
            $this->recordFailure($backup, $e, $step);
        } finally {
            File::deleteDirectory($work);
        }

        try {
            return $backup->fresh() ?? $backup;
        } catch (Throwable) {
            return $backup; // la base no responde: se devuelve lo que se sabe
        }
    }

    /**
     * Registra un fallo sin perder el error original: primero el log (con el
     * paso y el detalle técnico sin secretos), después el estado en la tabla.
     * Si guardar el estado también falla, se registra aparte y los avisos
     * igual salen con el error original.
     */
    private function recordFailure(Backup $backup, Throwable $e, string $step): void
    {
        Log::error('Falló un backup', ['backup_id' => $backup->id, 'step' => $step] + $this->diagnose($e));

        // La columna `error` es de 255 caracteres: un texto más largo hacía
        // fallar el guardado y tapaba el error original.
        $error = Str::limit('El backup no se pudo generar ('.$step.'): '.$this->describeFailure($e), 250);

        try {
            $backup->forceFill(['status' => Backup::FAILED, 'error' => $error, 'completed_at' => now()])->save();
        } catch (Throwable $saveError) {
            Log::critical('No se pudo registrar el fallo del backup', [
                'backup_id' => $backup->id,
                'original' => ['step' => $step] + $this->diagnose($e),
                'al_guardar' => $this->diagnose($saveError),
            ]);
            $backup->forceFill(['status' => Backup::FAILED, 'error' => $error]); // para el aviso
        }

        $this->notifyFailure($backup);
    }

    /**
     * Detalle técnico para el log, sin secretos: de una QueryException solo el
     * SQLSTATE, el código del motor, la consulta con "?" (sin valores) y el
     * mensaje del motor con los valores entre comillas tapados.
     *
     * @return array<string, mixed>
     */
    private function diagnose(Throwable $e): array
    {
        $appFrame = collect($e->getTrace())->first(fn ($f) => str_starts_with($f['file'] ?? '', app_path()));
        $info = [
            'exception' => $e::class,
            'at' => $appFrame ? str_replace(base_path().'/', '', $appFrame['file']).':'.$appFrame['line'] : basename($e->getFile()).':'.$e->getLine(),
        ];

        if ($e instanceof QueryException) {
            return $info + [
                'sqlstate' => (string) $e->getCode(),
                'driver_code' => $e->errorInfo[1] ?? null,
                'driver_message' => $this->safeDriverMessage($e),
                'sql' => Str::limit($e->getSql(), 300),
            ];
        }

        return $info + ['message' => $this->redact($e->getMessage())];
    }

    /** Mensaje del motor de base sin valores: 'persona@correo' → '***'; los nombres de tabla o columna quedan. */
    private function safeDriverMessage(QueryException $e): string
    {
        $message = (string) ($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? '');
        $message = preg_replace('/\s*\(Connection: .*$/s', '', $message); // host, base y SQL con valores
        $message = preg_replace_callback("/'([^']*)'/", fn ($m) => preg_match('/^[A-Za-z0-9_.$`-]{1,128}$/', $m[1]) ? $m[0] : "'***'", (string) $message);

        return $this->redact((string) $message);
    }

    public function processPending(): int
    {
        // Los que quedaron colgados (proceso cortado) se marcan como fallidos.
        Backup::where('status', Backup::RUNNING)->where('started_at', '<', now()->subHours(3))->get()
            ->each(function (Backup $b) {
                $b->forceFill(['status' => Backup::FAILED, 'error' => 'El backup se interrumpió.', 'completed_at' => now()])->save();
                $this->notifyFailure($b);
            });

        $done = 0;
        Backup::where('status', Backup::REQUESTED)->orderBy('id')->limit(1)->get()->each(function (Backup $b) use (&$done) {
            $this->run($b);
            $done++;
        });

        // Copia externa que falló: se reintenta el último backup (cada 30 min).
        if (static::offsiteDisk() && ! $done) {
            $last = Backup::where('status', Backup::COMPLETED)->whereNull('file_deleted_at')->latest('completed_at')->first();
            $offsite = $last?->summary['offsite'] ?? [];
            if ($last && empty($offsite['copied_at']) && (empty($offsite['attempted_at']) || now()->subMinutes(30)->gt($offsite['attempted_at']))) {
                $this->copyOffsite($last);
            }
        }

        return $done;
    }

    /**
     * Verifica que el ZIP se abre (con la contraseña), que el checksum coincide
     * y que el contenido coincide con el manifiesto.
     *
     * @return array{ok: bool, problems: list<string>, manifest: ?array}
     */
    public function verify(Backup $backup): array
    {
        $problems = [];
        $manifest = null;
        $path = $backup->hasSafePath() ? Storage::disk('local')->path($backup->path) : null;

        if (! $path || ! is_file($path)) {
            return ['ok' => false, 'problems' => ['El archivo del backup no existe.'], 'manifest' => null];
        }

        if (hash_file('sha256', $path) !== $backup->checksum) {
            $problems[] = 'El checksum del archivo no coincide (archivo dañado o modificado).';
        }

        $zip = $this->openArchive($path);

        if (! $zip) {
            return ['ok' => false, 'problems' => [...$problems, 'No se pudo abrir el ZIP.'], 'manifest' => null];
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $sql = $zip->getFromName('database.sql');

        if (! is_array($manifest)) {
            $problems[] = 'No se pudo leer el manifiesto (¿contraseña incorrecta?).';
        } else {
            if ($sql === false || hash('sha256', $sql) !== $manifest['database_sha256']) {
                $problems[] = 'El volcado de la base no coincide con el manifiesto.';
            }
            $files = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $files += str_starts_with((string) $zip->getNameIndex($i), 'files/') ? 1 : 0;
            }
            if ($files !== (int) $manifest['files']) {
                $problems[] = "Faltan archivos: el manifiesto dice {$manifest['files']} y hay {$files}.";
            }
        }

        $zip->close();

        if ($problems === []) {
            $backup->forceFill(['verified_at' => now()])->save();
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'manifest' => $manifest];
    }

    /**
     * Restaura un backup en una base y carpeta AISLADAS (para probarlo o para
     * recuperar en un servidor nuevo). Nunca sobre la base que usa la app.
     *
     * @return array{tables: array<string, array{expected: int, restored: int}>, files: int}
     */
    public function restore(Backup $backup, string $database, string $filesPath): array
    {
        $current = (string) config('database.connections.'.config('database.default').'.database');

        if ($database === '' || $database === $current || basename($database) === basename($current)) {
            throw new RuntimeException('No se restaura sobre la base que usa la app. Indicá otra base.');
        }

        $check = $this->verify($backup);
        if (! $check['ok']) {
            throw new RuntimeException('El backup no pasó la verificación: '.implode(' ', $check['problems']));
        }

        $zip = $this->openArchive(Storage::disk('local')->path($backup->path));
        $work = storage_path('app/backups-tmp/restore-'.$backup->id.'-'.Str::random(6));
        File::ensureDirectoryExists($work, 0700);

        try {
            file_put_contents($work.'/database.sql', $zip->getFromName('database.sql'));
            $this->loadDatabase($work.'/database.sql', $database, $check['manifest']['driver']);

            File::ensureDirectoryExists($filesPath, 0700);
            $files = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! str_starts_with($name, 'files/') || str_contains($name, '..')) {
                    continue;
                }
                $target = rtrim($filesPath, '/').'/'.substr($name, 6);
                File::ensureDirectoryExists(dirname($target));
                file_put_contents($target, $zip->getFromIndex($i));
                $files++;
            }
            $zip->close();

            $tables = [];
            $connection = $this->restoreConnection($database, $check['manifest']['driver']);
            foreach ($check['manifest']['tables'] as $table => $expected) {
                $tables[$table] = ['expected' => (int) $expected, 'restored' => (int) DB::connection($connection)->table($table)->count()];
            }
            DB::purge($connection);

            return ['tables' => $tables, 'files' => $files];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Retención: borra archivos viejos y conserva los registros. */
    public function prune(): int
    {
        $completed = Backup::where('status', Backup::COMPLETED)->whereNull('file_deleted_at')->orderByDesc('completed_at')->get();
        $latest = $completed->first();
        $keep = collect();

        $keep = $keep->merge($completed->where('type', 'scheduled')->take((int) config('backup.keep_daily'))->pluck('id'));
        $keep = $keep->merge($completed->where('type', 'scheduled')->groupBy(fn ($b) => $b->completed_at->format('o-W'))->take((int) config('backup.keep_weekly'))->map(fn ($g) => $g->first()->id)->values());
        $keep = $keep->merge($completed->where('type', 'manual')->filter(fn ($b) => $b->completed_at->gt(now()->subDays((int) config('backup.keep_manual_days'))))->pluck('id'));
        $latest && $keep->push($latest->id);

        $deleted = 0;
        foreach ($completed->whereNotIn('id', $keep->unique()->all()) as $backup) {
            if ($backup->hasSafePath()) {
                Storage::disk('local')->delete($backup->path);
            }
            $this->deleteOffsite($backup);
            $backup->forceFill(['file_deleted_at' => now(), 'path' => null])->save();
            $deleted++;
        }

        File::deleteDirectory(storage_path('app/backups-tmp'), true);

        return $deleted;
    }

    /** Disco de la copia externa (null = no configurada). */
    public static function offsiteDisk(): ?string
    {
        $disk = trim((string) config('backup.offsite_disk'));

        return $disk !== '' && $disk !== 'local' && $disk !== 'public' ? $disk : null;
    }

    /**
     * Sube el ZIP al disco externo y comprueba que llegó entero (tamaño).
     * Nunca sube un backup sin cifrar. Devuelve true si quedó copiado.
     */
    public function copyOffsite(Backup $backup): bool
    {
        $disk = static::offsiteDisk();
        if (! $disk || $backup->status !== Backup::COMPLETED || ! $backup->isDownloadable()) {
            return false;
        }

        $remote = trim((string) config('backup.offsite_path'), '/').'/'.basename((string) $backup->path);
        $record = fn (array $offsite) => $backup->forceFill(['summary' => [...($backup->summary ?? []), 'offsite' => $offsite]])->save();

        if (! $backup->encrypted) {
            $record(['disk' => $disk, 'attempted_at' => now()->toIso8601String(), 'error' => 'No se copia afuera un backup sin cifrar (falta BACKUP_ARCHIVE_PASSWORD).']);

            return false;
        }

        $stream = null;
        try {
            $stream = fopen(Storage::disk('local')->path($backup->path), 'rb');
            if (! Storage::disk($disk)->writeStream($remote, $stream) || Storage::disk($disk)->size($remote) !== (int) $backup->size) {
                throw new RuntimeException('La copia externa no quedó completa.');
            }
            $record(['disk' => $disk, 'path' => $remote, 'copied_at' => now()->toIso8601String()]);

            return true;
        } catch (Throwable $e) {
            Log::warning('No se pudo copiar el backup afuera', ['backup_id' => $backup->id, 'disk' => $disk, 'exception' => $e::class]);
            $first = empty($backup->summary['offsite']['error']);
            $record(['disk' => $disk, 'attempted_at' => now()->toIso8601String(), 'error' => 'No se pudo copiar afuera ('.class_basename($e).'). Se reintenta solo; revisá las credenciales del disco.']);
            if ($first) {
                $backup->error = 'El backup se generó, pero la copia externa falló ('.class_basename($e).'). Se reintenta cada 30 minutos.';
                $this->notifyFailure($backup);
                $backup->error = null;
            }

            return false;
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    private function deleteOffsite(Backup $backup): void
    {
        $offsite = $backup->summary['offsite'] ?? [];
        if (empty($offsite['path']) || empty($offsite['disk']) || $offsite['disk'] !== static::offsiteDisk()) {
            return;
        }

        try {
            Storage::disk($offsite['disk'])->delete($offsite['path']);
        } catch (Throwable $e) {
            Log::warning('No se pudo borrar la copia externa de un backup viejo', ['backup_id' => $backup->id, 'exception' => $e::class]);
        }
    }

    // ---------------------------------------------------------------------

    private function openArchive(string $path): ?ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        if (($password = (string) config('backup.password')) !== '') {
            $zip->setPassword($password);
        }

        return $zip;
    }

    private function dumpDatabase(string $target): void
    {
        $connection = config('database.connections.'.config('database.default'));

        if ($connection['driver'] === 'sqlite') {
            $this->dumpSqlite($target);

            return;
        }

        if (! in_array($connection['driver'], ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Motor de base no soportado para backups: '.$connection['driver']);
        }

        $options = $this->mysqlOptionsFile($connection);

        try {
            $command = fn (bool $gtid) => array_values(array_filter([
                (string) config('backup.mysqldump'), '--defaults-extra-file='.$options,
                '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--hex-blob',
                $gtid ? '--set-gtid-purged=OFF' : null, '--result-file='.$target, $connection['database'],
            ]));

            $process = new Process($command(true));
            $process->setTimeout(1800)->run();

            // MariaDB / versiones viejas no conocen --set-gtid-purged: se reintenta sin esa opción.
            if (! $process->isSuccessful() && str_contains($process->getErrorOutput(), 'set-gtid-purged')) {
                $process = new Process($command(false));
                $process->setTimeout(1800)->run();
            }

            if (! $process->isSuccessful()) {
                Log::error('mysqldump falló', ['exit' => $process->getExitCode(), 'stderr' => $this->redact($process->getErrorOutput())]);

                throw new RuntimeException('mysqldump falló (código '.$process->getExitCode().'): '.$this->mysqldumpCause($process).'.');
            }
        } finally {
            @unlink($options);
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('El volcado de la base quedó vacío.');
        }
    }

    /**
     * Volcado SQL de SQLite (estructura + datos), sin VACUUM: funciona aunque
     * haya una transacción abierta y queda en texto, como el de MySQL.
     */
    private function dumpSqlite(string $target): void
    {
        $pdo = DB::connection()->getPdo();
        $out = fopen($target, 'wb');
        fwrite($out, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");

        $objects = DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");

        foreach ($objects as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            fwrite($out, $object->sql.";\n");
            foreach (DB::table($object->name)->cursor() as $row) {
                $values = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), (array) $row);
                fwrite($out, 'INSERT INTO "'.$object->name.'" ("'.implode('","', array_keys((array) $row)).'") VALUES ('.implode(',', $values).");\n");
            }
        }

        foreach ($objects as $object) {
            if ($object->type !== 'table') {
                fwrite($out, $object->sql.";\n");
            }
        }

        fwrite($out, "COMMIT;\n");
        fclose($out);
    }

    private function loadDatabase(string $sql, string $database, string $driver): void
    {
        if ($driver === 'sqlite') {
            File::ensureDirectoryExists(dirname($database));
            @unlink($database);
            $pdo = new \PDO('sqlite:'.$database);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec((string) file_get_contents($sql));

            return;
        }

        $connection = config('database.connections.'.config('database.default'));
        $options = $this->mysqlOptionsFile($connection);

        try {
            $create = new Process([(string) config('backup.mysql'), '--defaults-extra-file='.$options, '-e', 'CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '', $database).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci']);
            $create->setTimeout(60)->run();

            $load = Process::fromShellCommandline('"$MYSQL" --defaults-extra-file="$OPTIONS" "$DB" < "$SQL"', null, [
                'MYSQL' => (string) config('backup.mysql'), 'OPTIONS' => $options, 'DB' => $database, 'SQL' => $sql,
            ]);
            $load->setTimeout(3600)->run();

            if (! $load->isSuccessful()) {
                // El mensaje de MySQL (sin la contraseña: va en el archivo de opciones).
                throw new RuntimeException('No se pudo cargar el volcado en la base "'.$database.'": '.Str::limit(trim($create->getErrorOutput().' '.$load->getErrorOutput()), 300));
            }
        } finally {
            @unlink($options);
        }
    }

    private function restoreConnection(string $database, string $driver): string
    {
        $base = config('database.connections.'.config('database.default'));
        config(['database.connections.backup_restore' => $driver === 'sqlite' ? ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''] : [...$base, 'database' => $database]]);
        DB::purge('backup_restore');

        return 'backup_restore';
    }

    /** Usuario y contraseña de MySQL en un archivo temporal 0600 (nunca en la línea de comandos). */
    private function mysqlOptionsFile(array $connection): string
    {
        $file = tempnam(sys_get_temp_dir(), 'asc-my');
        chmod($file, 0600);
        $quote = fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
        file_put_contents($file, "[client]\nuser={$quote($connection['username'])}\npassword={$quote($connection['password'] ?? '')}\nhost={$quote($connection['host'] ?? '127.0.0.1')}\nport=".(int) ($connection['port'] ?? 3306)."\n"
            .(! empty($connection['unix_socket']) ? "socket={$quote($connection['unix_socket'])}\n" : ''));

        return $file;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        // Solo las tablas de la base de la app: sin esquema, Schema::getTables()
        // lista también las de las otras bases que el usuario de MySQL puede
        // ver, y contarlas en esta base da "Table doesn't exist".
        $tables = collect(Schema::getTables(Schema::getCurrentSchemaName()))->pluck('name')
            ->reject(fn ($t) => in_array($t, ['sqlite_sequence', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'], true))
            ->sort()->values();

        return $tables->mapWithKeys(fn ($t) => [$t => (int) DB::table($t)->count()])->all();
    }

    /** @return \Generator<array{0: string, 1: string}> [ruta absoluta, nombre en el ZIP] */
    private function files(): \Generator
    {
        $root = rtrim((string) (config('backup.files_root') ?: storage_path('app')), '/');
        $exclude = array_map(fn ($d) => $root.'/'.trim($d, '/'), (array) config('backup.exclude'));

        foreach ((array) config('backup.include') as $dir) {
            $base = $root.'/'.trim($dir, '/');
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (! $file->isFile() || $file->isLink() || collect($exclude)->contains(fn ($e) => str_starts_with($path, $e.'/'))
                    || basename($path) === '.gitignore') {
                    continue;
                }
                yield [$path, substr($path, strlen($root) + 1)];
            }
        }
    }

    /** Archivos que la base menciona y no entraron al backup (coherencia). */
    private function missingReferences(array $inArchive): array
    {
        $missing = [];
        foreach (self::REFERENCED_FILES as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')->select('id', $column)
                ->chunk(1000, function ($rows) use ($table, $column, $inArchive, &$missing) {
                    foreach ($rows as $row) {
                        $path = ltrim((string) $row->{$column}, '/');
                        $candidates = $table === 'companies' ? ['public/'.$path] : ['private/'.$path, 'public/'.$path];
                        if (! collect($candidates)->contains(fn ($c) => isset($inArchive[$c]))) {
                            $missing[] = "{$table}#{$row->id}";
                        }
                    }
                });
        }

        return array_slice($missing, 0, 500);
    }

    /**
     * Avisa a los SuperAdmin (campanita) y a BACKUP_NOTIFY_EMAIL. Todo en el
     * momento (notifyNow): la notificación de Filament es ShouldQueue y, sin
     * worker de colas, quedaba en la tabla jobs y no llegaba nunca. Cada
     * destino por separado: si falla uno, los demás igual se avisan.
     */
    private function notifyFailure(Backup $backup): void
    {
        $text = (string) $backup->error;

        foreach (User::where('is_super_admin', true)->get() as $admin) {
            try {
                $admin->notifyNow(FilamentNotification::make()->title('Falló un backup de Ascento')->body($text)->danger()->toDatabase());
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar la falla del backup a un SuperAdmin', ['backup_id' => $backup->id, 'exception' => $e::class]);
            }
        }

        if ($email = config('backup.notify_email')) {
            try {
                Notification::route('mail', $email)->notifyNow(new MailOnlyNotification((new MailMessage)
                    ->subject('Falló un backup de Ascento')->error()->line('Backup #'.$backup->id.':')->line($text)
                    ->line('Detalle técnico: storage/logs/laravel.log (buscá "backup").')));
            } catch (Throwable $e) {
                Log::warning('No se pudo mandar el correo de falla del backup', ['backup_id' => $backup->id, 'exception' => $e::class]);
            }
        }
    }

    /**
     * Qué pasó, en palabras y sin secretos (va al panel y al correo). Los
     * mensajes propios (RuntimeException de este archivo) ya son seguros.
     */
    private function describeFailure(Throwable $e): string
    {
        return match (true) {
            $e instanceof ProcessTimedOutException => 'El volcado de la base tardó demasiado y se cortó.',
            $e instanceof QueryException => 'error de la base de datos (SQLSTATE '.$e->getCode().'): '.$this->safeDriverMessage($e),
            $e instanceof RuntimeException => $this->redact($e->getMessage()),
            default => 'Error inesperado ('.class_basename($e).').',
        };
    }

    /** Causa probable de un error de mysqldump (su salida va solo al log, sin contraseñas). */
    private function mysqldumpCause(Process $process): string
    {
        $out = $process->getErrorOutput();

        return match (true) {
            $process->getExitCode() === 127 || str_contains($out, 'not found') => 'no se encontró el programa mysqldump (BACKUP_MYSQLDUMP)',
            (bool) preg_match('/need \(at least one of\) the ([A-Z ,]+) privilege/i', $out, $m) => 'el usuario de la base no tiene el permiso '.trim($m[1]).' que pide mysqldump',
            str_contains($out, 'Access denied') => 'MySQL rechazó el usuario o la contraseña (revisá DB_USERNAME / DB_PASSWORD)',
            str_contains($out, 'Unknown database') => 'la base de DB_DATABASE no existe',
            str_contains($out, "Can't connect") || str_contains($out, 'Lost connection') => 'no se pudo conectar a MySQL (revisá DB_HOST / DB_PORT)',
            str_contains($out, 'Errcode: 28') || str_contains($out, 'No space left') => 'no hay espacio en disco',
            str_contains($out, 'Errcode: 13') || str_contains($out, 'Permission denied') => 'no hay permiso para escribir el archivo temporal',
            default => 'causa en el log del servidor',
        };
    }

    /** Texto para el log sin la contraseña de la base ni la de los backups. */
    private function redact(string $text): string
    {
        $secrets = array_filter([
            (string) config('database.connections.'.config('database.default').'.password'),
            (string) config('backup.password'),
        ], fn ($v) => strlen($v) >= 3);

        return Str::limit(str_replace($secrets, '***', $text), 800);
    }
}
