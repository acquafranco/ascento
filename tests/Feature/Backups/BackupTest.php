<?php

namespace Tests\Feature\Backups;

use App\Filament\Pages\SystemBackups;
use App\Models\Backup;
use App\Models\BackupDownload;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Services\Backups\BackupManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Backups globales: contenido, cifrado, verificación, restauración aislada,
 * retención, permisos y fallas. (En pruebas la base es SQLite; la
 * restauración real con MySQL se probó aparte: ver docs/backups.md.)
 */
class BackupTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();

        // Archivos "del servidor" en una carpeta temporal (nunca los reales).
        $this->root = sys_get_temp_dir().'/ascento-backup-files-'.uniqid();
        File::ensureDirectoryExists($this->root.'/private/reports/'.$this->a['company']->id);
        File::ensureDirectoryExists($this->root.'/private/exports');
        File::ensureDirectoryExists($this->root.'/public/logos');
        file_put_contents($this->root.'/private/reports/'.$this->a['company']->id.'/foto.jpg', 'FOTO');
        file_put_contents($this->root.'/private/exports/viejo.zip', 'NO DEBE ENTRAR');
        file_put_contents($this->root.'/public/logos/logo.png', 'LOGO');
        config(['backup.files_root' => $this->root, 'backup.password' => 'clave-de-prueba']);

        auth()->logout();
        $report = Report::factory()->create(['building_id' => $this->a['building']->id]);
        $photo = new ReportPhoto(['report_id' => $report->id, 'path' => 'reports/'.$this->a['company']->id.'/foto.jpg', 'position' => 0]);
        $photo->company_id = $this->a['company']->id;
        $photo->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function backup(): Backup
    {
        $manager = app(BackupManager::class);

        return $manager->run($manager->request('scheduled'));
    }

    public function test_a_backup_contains_the_database_and_files_encrypted_with_a_manifest(): void
    {
        $backup = $this->backup();

        $this->assertSame(Backup::COMPLETED, $backup->status);
        $this->assertTrue($backup->encrypted);
        $this->assertSame(64, strlen($backup->checksum));
        $this->assertStringStartsWith('backups/', $backup->path);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($backup->path));
        $this->assertFalse($zip->getFromName('manifest.json')); // cifrado: sin contraseña no se lee
        $zip->setPassword('clave-de-prueba');
        $manifest = json_decode($zip->getFromName('manifest.json'), true);

        $this->assertSame(config('database.connections.'.config('database.default').'.driver'), $manifest['driver']);
        $this->assertSame(1, $manifest['tables']['report_photos']);
        $this->assertSame(1, $manifest['tables']['companies']);
        $this->assertSame([], $manifest['missing_referenced_files']);
        $this->assertSame('FOTO', $zip->getFromName('files/private/reports/'.$this->a['company']->id.'/foto.jpg'));
        $this->assertSame('LOGO', $zip->getFromName('files/public/logos/logo.png'));
        $this->assertFalse($zip->getFromName('files/private/exports/viejo.zip')); // exportaciones excluidas
        $this->assertSame($manifest['database_sha256'], hash('sha256', $zip->getFromName('database.sql')));
    }

    public function test_verification_detects_tampering_and_restore_recovers_everything_elsewhere(): void
    {
        if (config('database.default') !== 'sqlite') {
            // Con MySQL, mysqldump usa su propia conexión y no ve los datos sin
            // confirmar de la transacción del test. La restauración con MySQL
            // real se probó aparte con datos demo (docs/backups.md).
            $this->markTestSkipped('Restauración comparada: se prueba con SQLite en la suite y con MySQL a mano.');
        }

        $backup = $this->backup();
        $this->assertTrue(app(BackupManager::class)->verify($backup)['ok']);
        $this->assertNotNull($backup->fresh()->verified_at);

        $target = sys_get_temp_dir().'/ascento-restore-'.uniqid();
        $result = app(BackupManager::class)->restore($backup, $target.'/db.sqlite', $target.'/files');

        foreach ($result['tables'] as $table => $counts) {
            $this->assertSame($counts['expected'], $counts['restored'], "La tabla {$table} no coincide");
        }
        $this->assertSame('FOTO', file_get_contents($target.'/files/private/reports/'.$this->a['company']->id.'/foto.jpg'));
        File::deleteDirectory($target);

        // Nunca sobre la base que usa la app.
        try {
            app(BackupManager::class)->restore($backup, (string) config('database.connections.sqlite.database'), $target);
            $this->fail('Se restauró sobre la base de la app.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se restaura sobre la base que usa la app', $e->getMessage());
        }

        // Archivo alterado: la verificación lo detecta.
        file_put_contents(Storage::disk('local')->path($backup->path), 'x', FILE_APPEND);
        $this->assertFalse(app(BackupManager::class)->verify($backup->fresh())['ok']);

        // Contraseña equivocada: no se puede leer.
        $other = $this->backup();
        config(['backup.password' => 'otra']);
        $this->assertFalse(app(BackupManager::class)->verify($other)['ok']);
    }

    public function test_missing_referenced_files_are_reported(): void
    {
        unlink($this->root.'/private/reports/'.$this->a['company']->id.'/foto.jpg');

        $backup = $this->backup();

        $this->assertSame(Backup::COMPLETED, $backup->status);
        $this->assertSame(1, $backup->summary['missing_referenced_files']);
    }

    public function test_retention_keeps_recent_weekly_manual_and_the_latest_and_keeps_records(): void
    {
        config(['backup.keep_daily' => 2, 'backup.keep_weekly' => 2, 'backup.keep_manual_days' => 30]);
        $manager = app(BackupManager::class);
        $ids = [];
        $today = now()->startOfDay();
        foreach (range(30, 0, -1) as $daysAgo) {
            $this->travelTo($today->copy()->subDays($daysAgo)->setTime(3, 15));
            $ids[$daysAgo] = $manager->run($manager->request('scheduled'))->id;
        }
        $manual = $manager->run($manager->request('manual'));
        $this->travelBack();

        $manager->prune();

        $alive = Backup::whereNull('file_deleted_at')->pluck('id');
        $this->assertTrue($alive->contains($ids[0]) && $alive->contains($ids[1])); // 2 diarios
        $this->assertTrue($alive->contains($manual->id));
        $this->assertLessThanOrEqual(5, $alive->count());
        $this->assertSame(32, Backup::count()); // los registros quedan
        $gone = Backup::whereNotNull('file_deleted_at')->first();
        $this->assertNull($gone->path);
    }

    public function test_only_the_superadmin_sees_requests_and_downloads_backups_and_downloads_are_logged(): void
    {
        $backup = $this->backup();
        $super = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($this->a['admin'])->get(route('backups.download', $backup))->assertNotFound();
        $this->actingAs($this->a['technician'])->get(route('backups.download', $backup))->assertNotFound();
        auth()->logout();
        $this->get(route('backups.download', $backup))->assertRedirect('/login');
        $this->get('/storage/'.$backup->path)->assertNotFound();
        $this->assertSame(0, BackupDownload::count());

        $this->actingAs($super)->get(route('backups.download', $backup))->assertOk()->assertDownload(basename($backup->path));
        $this->assertSame($super->id, BackupDownload::sole()->user_id);

        // Página: el admin de una empresa no entra; el SuperAdmin pide uno (de a uno).
        $this->actingInPanel($this->a['admin'])->get(SystemBackups::getUrl())->assertForbidden();
        $this->actingInPanel($super);
        Livewire::test(SystemBackups::class)->call('requestBackup')->call('requestBackup');
        $this->assertSame(1, Backup::where('status', Backup::REQUESTED)->count());
        $this->artisan('backup:process')->assertSuccessful();
        $this->assertSame(Backup::COMPLETED, Backup::latest('id')->first()->status);
    }

    public function test_failures_are_recorded_without_secrets_and_notify_superadmins(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        // Fuerza la falla del volcado (motor no soportado / mysqldump inexistente).
        config(['database.connections.sqlite.driver' => 'unsupported', 'backup.mysqldump' => '/no/existe/mysqldump']);

        $backup = $this->backup();

        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('no se pudo generar', $backup->error);
        $this->assertSame([], Storage::disk('local')->allFiles('backups'));
        $this->assertSame('Falló un backup de Ascento', $super->notifications()->sole()->data['title']);
    }

    public function test_without_an_offsite_disk_nothing_is_copied(): void
    {
        $backup = $this->backup();

        $this->assertSame(Backup::COMPLETED, $backup->status);
        $this->assertArrayNotHasKey('offsite', $backup->summary);
    }

    public function test_each_encrypted_backup_is_copied_offsite_and_pruned_there_too(): void
    {
        Storage::fake('offsite');
        config(['backup.offsite_disk' => 'offsite', 'backup.offsite_path' => 'ascento-backups']);

        $backup = $this->backup();

        $remote = $backup->summary['offsite']['path'];
        $this->assertSame('ascento-backups/'.basename($backup->path), $remote);
        $this->assertNotEmpty($backup->summary['offsite']['copied_at']);
        $this->assertSame($backup->size, Storage::disk('offsite')->size($remote));
        $this->assertSame($backup->checksum, hash('sha256', Storage::disk('offsite')->get($remote)));

        // La retención también borra la copia externa (los registros quedan).
        config(['backup.keep_daily' => 0, 'backup.keep_weekly' => 0]);
        $this->travel(1)->days();
        $newer = $this->backup();
        app(BackupManager::class)->prune();
        Storage::disk('offsite')->assertMissing($remote);
        Storage::disk('offsite')->assertExists($newer->fresh()->summary['offsite']['path']);
    }

    public function test_an_offsite_failure_is_recorded_notified_once_and_retried(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        config(['filesystems.disks.roto' => ['driver' => 'local', 'root' => '/dev/null/no-existe', 'throw' => true], 'backup.offsite_disk' => 'roto']);

        $backup = $this->backup();

        $this->assertSame(Backup::COMPLETED, $backup->status); // el backup local igual sirve
        $this->assertStringContainsString('No se pudo copiar afuera', $backup->summary['offsite']['error']);
        $this->assertStringNotContainsString('clave-de-prueba', json_encode($backup->summary));
        $this->assertStringContainsString('copia externa falló', $super->notifications()->sole()->data['body']);

        // Reintento: antes de 30 minutos no; después, con el disco andando, sí.
        Storage::fake('offsite');
        config(['backup.offsite_disk' => 'offsite']);
        app(BackupManager::class)->processPending();
        $this->assertArrayNotHasKey('copied_at', $backup->fresh()->summary['offsite']);
        $this->travel(31)->minutes();
        app(BackupManager::class)->processPending();
        $this->assertNotEmpty($backup->fresh()->summary['offsite']['copied_at']);
        $this->assertSame(1, $super->notifications()->count()); // un solo aviso
    }

    public function test_an_unencrypted_backup_never_leaves_the_server(): void
    {
        Storage::fake('offsite');
        config(['backup.offsite_disk' => 'offsite', 'backup.password' => null]);

        $backup = $this->backup();

        $this->assertSame([], Storage::disk('offsite')->allFiles());
        $this->assertStringContainsString('sin cifrar', $backup->summary['offsite']['error']);
    }

    /** Hace fallar el volcado con cualquier motor de pruebas (la conexión abierta sigue andando). */
    private function breakDatabaseDump(): void
    {
        config(['database.connections.'.config('database.default').'.driver' => 'unsupported']);
    }

    public function test_failure_notices_are_delivered_even_with_a_database_queue_and_no_worker(): void
    {
        // Producción usa QUEUE_CONNECTION=database sin worker: el aviso de
        // Filament (ShouldQueue) quedaba en la tabla jobs y nunca llegaba.
        config(['queue.default' => 'database']);
        $super = User::factory()->create(['is_super_admin' => true]);
        $this->breakDatabaseDump();

        $this->backup();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('Falló un backup de Ascento', $super->notifications()->sole()->data['title']);
    }

    public function test_a_mysqldump_failure_explains_the_cause_without_leaking_passwords(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $script = $this->root.'/mysqldump-falso';
        file_put_contents($script, "#!/bin/sh\necho \"mysqldump: Got error: 1045: Access denied for user 'forge'@'localhost' (using password: YES) clave-db-secreta\" >&2\nexit 2\n");
        chmod($script, 0755);
        $c = 'database.connections.'.config('database.default');
        config(["{$c}.driver" => 'mysql', "{$c}.username" => 'forge', "{$c}.password" => 'clave-db-secreta', "{$c}.database" => 'ascento', 'backup.mysqldump' => $script]);
        Log::spy();

        $backup = $this->backup();

        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('rechazó el usuario o la contraseña', $backup->error);
        $this->assertStringNotContainsString('clave-db-secreta', $backup->error);
        $this->assertStringNotContainsString('clave-de-prueba', $backup->error);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => $message === 'mysqldump falló'
            && str_contains($context['stderr'], 'Access denied') && ! str_contains($context['stderr'], 'clave-db-secreta'));
        $this->assertStringContainsString('rechazó el usuario', $super->notifications()->sole()->data['body']);
    }

    public function test_a_failing_notice_channel_does_not_stop_the_others(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        config(['backup.notify_email' => 'avisos@ascento.test', 'mail.default' => 'roto', 'mail.mailers.roto' => ['transport' => 'no-existe']]);
        $this->breakDatabaseDump();

        $backup = $this->backup();

        $this->assertSame(Backup::FAILED, $backup->status); // el registro queda igual
        $this->assertSame(1, $super->notifications()->count());
    }

    /**
     * Simula otra base en el mismo servidor de MySQL (en Forge, por ejemplo,
     * la base "forge" u otro sitio): Schema::getTables() sin esquema la lista.
     */
    private function anotherDatabaseOnTheServer(bool $alsoInTheAppSchema = false): void
    {
        $real = Schema::getFacadeRoot();
        $foreign = ['name' => 'tabla_de_otra_base', 'schema' => 'otra_base'];
        $mock = Mockery::mock($real);
        $mock->shouldReceive('getTables')->andReturnUsing(fn ($schema = null) => $schema === null || $alsoInTheAppSchema
            ? [...$real->getTables($schema), $foreign]
            : $real->getTables($schema));
        $this->app->instance('db.schema', $mock);
    }

    // Producción 11/10/2026 03:15: QueryException en backup:run.
    public function test_tables_of_other_databases_on_the_same_server_do_not_break_the_backup(): void
    {
        $this->anotherDatabaseOnTheServer();
        Log::spy();

        $backup = $this->backup();

        $this->assertSame(Backup::COMPLETED, $backup->status);
        $this->assertNull($backup->error);
        $this->assertNotNull($backup->checksum);
        $this->assertTrue(app(BackupManager::class)->verify($backup)['ok']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_database_error_during_generation_is_recorded_with_its_step_and_cause(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $this->anotherDatabaseOnTheServer(alsoInTheAppSchema: true); // tabla listada que no existe
        Log::spy();

        $backup = $this->backup();

        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('(conteo de filas)', $backup->error);
        $this->assertStringContainsString('error de la base de datos (SQLSTATE', $backup->error);
        $this->assertStringContainsString('tabla_de_otra_base', $backup->error);
        $this->assertLessThanOrEqual(255, mb_strlen($backup->error), 'entra en la columna error');
        $this->assertSame([], Storage::disk('local')->allFiles('backups'));
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => $message === 'Falló un backup'
            && $context['step'] === 'conteo de filas'
            && $context['exception'] === QueryException::class
            && str_contains($context['sql'], 'tabla_de_otra_base')
            && str_starts_with($context['at'], 'app/Services/Backups/BackupManager.php:'));
        $this->assertStringContainsString('conteo de filas', $super->notifications()->sole()->data['body']);
    }

    public function test_if_saving_the_failure_also_fails_the_original_error_is_kept_and_notified(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $this->breakDatabaseDump();
        Backup::saving(function (Backup $b) {
            if ($b->status === Backup::FAILED) {
                throw new RuntimeException('la base dejó de responder');
            }
        });
        Log::spy();

        $backup = $this->backup(); // no explota

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => $message === 'Falló un backup' && $context['step'] === 'volcado de la base');
        Log::shouldHaveReceived('critical')->withArgs(fn ($message, $context = []) => $message === 'No se pudo registrar el fallo del backup'
            && $context['original']['step'] === 'volcado de la base'
            && $context['original']['exception'] === RuntimeException::class
            && str_contains($context['original']['message'], 'Motor de base no soportado')
            && str_contains($context['al_guardar']['message'], 'la base dejó de responder'));
        $this->assertSame(Backup::RUNNING, Backup::find($backup->id)->status, 'no se inventa un estado que no se pudo guardar');
        $this->assertStringContainsString('Motor de base no soportado', $super->notifications()->sole()->data['body']);
    }

    public function test_database_errors_never_leak_values_or_passwords(): void
    {
        // "clave-de-prueba" es BACKUP_ARCHIVE_PASSWORD (se tapa igual que la de la base;
        // no se cambia la de la base para no romper el mysqldump real con MySQL).
        $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'persona@correo.test' for key 'users_email_unique'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'persona@correo.test' for key 'users_email_unique' clave-de-prueba"];
        Backup::saving(function (Backup $b) use ($pdo) {
            if ($b->status === Backup::COMPLETED) {
                throw new QueryException('mysql', 'update users set email = ?, password = ? where id = ?', ['persona@correo.test', 'clave-de-prueba', 1], $pdo);
            }
        });
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });

        $backup = $this->backup();

        $everything = $backup->error.' '.implode(' ', $logged);
        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('registro del backup completo', $backup->error);
        $this->assertStringContainsString('users_email_unique', $everything);
        $this->assertStringContainsString('update users set email = ?, password = ?', $everything);
        foreach (['persona@correo.test', 'clave-de-prueba'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('backups'), 'no queda un ZIP de un backup fallido');
        $fresh = Backup::find($backup->id);
        $this->assertNull($fresh->path, 'no apunta a un ZIP borrado');
        $this->assertNull($fresh->checksum);
        $this->assertNull($fresh->summary);
    }

    public function test_an_unexpected_offsite_error_never_ruins_a_completed_backup(): void
    {
        Storage::fake('offsite');
        config(['backup.offsite_disk' => 'offsite', 'backup.offsite_path' => 'ascento-backups']);
        Backup::saving(function (Backup $b) {
            if (isset($b->summary['offsite'])) {
                throw new RuntimeException('la base dejó de responder');
            }
        });
        Log::spy();

        $backup = $this->backup();

        $this->assertSame(Backup::COMPLETED, $backup->status);
        $this->assertNull($backup->error);
        Storage::disk('local')->assertExists($backup->path);
        $this->assertTrue(app(BackupManager::class)->verify($backup)['ok']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'No se pudo registrar la copia externa del backup');
        Log::shouldNotHaveReceived('error');
    }

    public function test_an_empty_database_dump_is_never_a_completed_backup(): void
    {
        $script = $this->root.'/mysqldump-vacio';
        file_put_contents($script, "#!/bin/sh\nexit 0\n"); // "termina bien" sin escribir nada
        chmod($script, 0755);
        $c = 'database.connections.'.config('database.default');
        config(["{$c}.driver" => 'mysql', "{$c}.username" => 'forge', "{$c}.database" => 'ascento', 'backup.mysqldump' => $script]);

        $backup = $this->backup();

        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('(volcado de la base)', $backup->error);
        $this->assertStringContainsString('quedó vacío', $backup->error);
        $this->assertSame([], Storage::disk('local')->allFiles('backups'));
    }
}
