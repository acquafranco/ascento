<?php

namespace Tests\Feature\Backups;

use App\Filament\Pages\SystemBackups;
use App\Models\Backup;
use App\Models\BackupDownload;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Services\Backups\BackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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

        $this->assertSame('sqlite', $manifest['driver']);
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
        } catch (\RuntimeException $e) {
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
        config(['database.connections.sqlite.driver' => 'unsupported']); // fuerza la falla del volcado

        $backup = $this->backup();

        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('no se pudo generar', $backup->error);
        $this->assertSame([], Storage::disk('local')->allFiles('backups'));
        $this->assertSame('Falló un backup de Ascento', $super->notifications()->sole()->data['title']);
    }
}
