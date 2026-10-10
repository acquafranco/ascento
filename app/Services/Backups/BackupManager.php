<?php

namespace App\Services\Backups;

use App\Models\Backup;
use App\Models\User;
use App\Notifications\MailOnlyNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Backups globales restaurables de Ascento.
 *
 * Contenido del ZIP (cifrado AES-256 si hay BACKUP_ARCHIVE_PASSWORD):
 * - database.sql  → volcado completo y consistente (mysqldump
 *                   --single-transaction; SQLite: copia con VACUUM INTO).
 * - files/...     → storage/app/private y storage/app/public (sin exports,
 *                   temporales ni backups).
 * - manifest.json → fecha, motor, filas por tabla, archivos, sha256 de la base
 *                   y archivos que la base menciona pero no estaban (control
 *                   de coherencia entre la base y los archivos).
 *
 * La contraseña de la base nunca va en la línea de comandos ni en los logs
 * (archivo temporal de opciones con permisos 0600).
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

        try {
            $sql = $work.'/database.sql';
            $this->dumpDatabase($sql);

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

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo del backup.');
            }

            $password = (string) config('backup.password');
            $encrypt = function (string $name) use ($zip, $password) {
                if ($password !== '') {
                    $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
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
        } catch (Throwable $e) {
            @unlink($zipPath);
            Log::error('Falló un backup', ['backup_id' => $backup->id, 'exception' => $e::class, 'at' => basename($e->getFile()).':'.$e->getLine()]);
            $backup->forceFill(['status' => Backup::FAILED, 'error' => 'El backup no se pudo generar ('.class_basename($e).'). Revisá el log del servidor.', 'completed_at' => now()])->save();
            $this->notifyFailure($backup);
        } finally {
            File::deleteDirectory($work);
        }

        return $backup->fresh();
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
            $backup->forceFill(['file_deleted_at' => now(), 'path' => null])->save();
            $deleted++;
        }

        File::deleteDirectory(storage_path('app/backups-tmp'), true);

        return $deleted;
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
                throw new RuntimeException('mysqldump falló (código '.$process->getExitCode().').');
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
        $tables = collect(Schema::getTables())->pluck('name')
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

    private function notifyFailure(Backup $backup): void
    {
        try {
            User::where('is_super_admin', true)->get()->each(fn (User $admin) => FilamentNotification::make()
                ->title('Falló un backup de Ascento')->body((string) $backup->error)->danger()->sendToDatabase($admin));

            if ($email = config('backup.notify_email')) {
                Notification::route('mail', $email)->notifyNow(new MailOnlyNotification((new MailMessage)
                    ->subject('Falló un backup de Ascento')->error()->line('El backup #'.$backup->id.' no se pudo generar.')->line((string) $backup->error)));
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar la falla del backup', ['backup_id' => $backup->id, 'exception' => $e::class]);
        }
    }
}
