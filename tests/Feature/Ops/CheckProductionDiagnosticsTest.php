<?php

namespace Tests\Feature\Ops;

use App\Models\Backup;
use App\Support\Diagnostics\ServerLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ascento:check-production: niveles (crítico / advertencia / no verificable),
 * límites de PHP-FPM y Nginx leídos de sus archivos (sin confundirlos con la
 * CLI), colas, backups y copia externa. Nunca muestra secretos ni contenido.
 */
class CheckProductionDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/ascento-diag-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/fpm/conf.d');
        File::ensureDirectoryExists($this->dir.'/fpm/pool.d');
        File::ensureDirectoryExists($this->dir.'/nginx');
        config(['media.video_max_mb' => 50]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function fpm(string $ini, string $pool = ''): void
    {
        file_put_contents($this->dir.'/fpm/php.ini', $ini);
        $pool && file_put_contents($this->dir.'/fpm/pool.d/www.conf', $pool);
        config(['ascento-diagnostics.fpm_dir' => $this->dir.'/fpm']);
    }

    private function nginx(string $conf): void
    {
        file_put_contents($this->dir.'/nginx/site.conf', $conf);
        config(['ascento-diagnostics.nginx_files' => [$this->dir.'/nginx/*']]);
    }

    public function test_sizes_are_parsed_like_php_does(): void
    {
        $this->assertSame(64.0, ServerLimits::toMb('64M'));
        $this->assertSame(1024.0, ServerLimits::toMb('1G'));
        $this->assertSame(0.5, ServerLimits::toMb('512K'));
        $this->assertSame(INF, ServerLimits::toMb('0'));
        $this->assertNull(ServerLimits::toMb('mucho'));
        $this->assertSame('/etc/php/8.4/fpm', ServerLimits::fpmDir('/etc/php/8.4/cli/php.ini') ?? '/etc/php/8.4/fpm'); // si existe
        $this->assertNull(ServerLimits::fpmDir('/opt/homebrew/etc/php/8.4/php.ini'));
    }

    public function test_low_php_fpm_and_nginx_limits_are_reported_from_their_own_files(): void
    {
        $this->fpm("upload_max_filesize = 10M\npost_max_size = 10M\nmemory_limit = 64M\n");
        $this->nginx("server {\n  listen 443;\n}\n");

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('PHP CLI (este comando, no la web)')
            ->expectsOutputToContain('PHP-FPM (web) acepta 10 MB por archivo / 10 MB por envío: los videos de hasta 50 MB fallan. En Forge: PHP → Max File Upload Size ≥ 60')
            ->expectsOutputToContain('memory_limit de PHP-FPM es 64 MB')
            ->expectsOutputToContain('no está definido: Nginx usa 1 MB')
            ->assertFailed();
    }

    public function test_pool_overrides_win_and_good_limits_pass(): void
    {
        $this->fpm("upload_max_filesize = 10M\npost_max_size = 10M\n", "[www]\nphp_admin_value[upload_max_filesize] = 64M\nphp_admin_value[post_max_size] = 64M\n");
        $this->nginx("http {\n  client_max_body_size 64m;\n}\n");

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('PHP-FPM (web), configurado: 64 MB por archivo / 64 MB por envío')
            ->expectsOutputToContain('Nginx client_max_body_size: 64 MB (en site.conf)')
            ->doesntExpectOutputToContain('PHP-FPM (web) acepta');
    }

    public function test_unreadable_server_config_is_marked_unverifiable_not_ok(): void
    {
        config(['ascento-diagnostics.fpm_dir' => $this->dir.'/no-existe', 'ascento-diagnostics.nginx_files' => [$this->dir.'/no-existe/*']]);

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('? No se pudieron leer los archivos de PHP-FPM')
            ->expectsOutputToContain('? No se pudo leer la configuración de Nginx')
            ->doesntExpectOutputToContain('PHP-FPM (web), configurado')
            ->expectsOutputToContain('No verificables desde la consola: 2');
    }

    public function test_stale_queued_jobs_are_explained_without_printing_their_content(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp,
            'payload' => json_encode(['displayName' => 'Filament\\Notifications\\DatabaseNotification', 'data' => ['command' => 'DATO-PRIVADO-DEL-JOB']]),
        ]);

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('1 × Filament\\Notifications\\DatabaseNotification')
            ->expectsOutputToContain('nadie los procesa (no hay worker). Los "DatabaseNotification" son avisos de campanita (p. ej. "Falló un backup") que la versión anterior dejaba en cola: esta versión ya no encola')
            ->doesntExpectOutputToContain('DATO-PRIVADO-DEL-JOB');
    }

    public function test_backups_explain_the_last_failure_and_the_same_server_risk(): void
    {
        $failed = new Backup;
        $failed->forceFill(['type' => 'scheduled', 'status' => Backup::FAILED, 'error' => 'El backup no se pudo generar: mysqldump falló (código 2): MySQL rechazó el usuario o la contraseña (revisá DB_USERNAME / DB_PASSWORD).', 'completed_at' => now()])->save();
        config(['backup.offsite_disk' => null, 'backup.password' => 'CLAVE-BACKUP-SECRETA']);

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('✗ No hay ningún backup completo. Último intento fallido: ')
            ->expectsOutputToContain('! Todos los backups están en este mismo servidor: si se pierde el servidor o el disco, se pierden también. Es opcional pero recomendado')
            ->doesntExpectOutputToContain('CLAVE-BACKUP-SECRETA')
            ->assertFailed();

        // La misma línea trae la causa y dónde ver el detalle técnico.
        Artisan::call('ascento:check-production');
        $line = collect(explode("\n", Artisan::output()))->first(fn ($l) => str_contains($l, 'No hay ningún backup completo'));
        $this->assertStringContainsString('MySQL rechazó el usuario o la contraseña', $line);
        $this->assertStringContainsString('grep -i backup storage/logs/laravel.log', $line);
        $this->assertStringContainsString('php artisan backup:run', $line);
    }

    public function test_an_old_failure_after_a_good_backup_is_only_informative(): void
    {
        $failed = new Backup;
        $failed->forceFill(['type' => 'scheduled', 'status' => Backup::FAILED, 'error' => 'x', 'created_at' => now()->subDay(), 'completed_at' => now()->subDay()])->save();
        $ok = new Backup;
        $ok->forceFill(['type' => 'scheduled', 'status' => Backup::COMPLETED, 'path' => 'backups/a.zip', 'size' => 10, 'completed_at' => now()->subHour()])->save();

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('✓ Último backup completo')
            ->expectsOutputToContain('pero hubo uno completo después')
            ->doesntExpectOutputToContain('No hay ningún backup completo');
    }

    public function test_insecure_session_cookie_with_https_is_a_warning_and_the_default_follows_app_url(): void
    {
        config(['app.url' => 'https://ascento.test', 'session.secure' => false]);
        $this->artisan('ascento:check-production')->expectsOutputToContain('! SESSION_SECURE_COOKIE está en false');

        $read = function (array $env) {
            $backup = [];
            foreach ($env as $key => $value) {
                $backup[$key] = getenv($key);
                $value === null ? putenv($key) : putenv("{$key}={$value}");
                if ($value === null) {
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                }
            }
            $secure = (require config_path('session.php'))['secure'];
            foreach ($backup as $key => $value) {
                $value === false ? putenv($key) : putenv("{$key}={$value}");
                unset($_ENV[$key], $_SERVER[$key]);
            }

            return $secure;
        };

        $this->assertTrue($read(['APP_URL' => 'https://ascento.online', 'SESSION_SECURE_COOKIE' => null]));
        $this->assertNull($read(['APP_URL' => 'http://localhost', 'SESSION_SECURE_COOKIE' => null]));
        $this->assertFalse($read(['APP_URL' => 'https://ascento.online', 'SESSION_SECURE_COOKIE' => 'false']));
    }

    public function test_app_name_laravel_is_a_warning_with_the_safe_way_to_change_it(): void
    {
        config(['app.name' => 'Laravel']);

        $this->artisan('ascento:check-production')->expectsOutputToContain('! APP_NAME es "Laravel": aparece en el título de los correos. Poné APP_NAME=Ascento junto con SESSION_COOKIE y CACHE_PREFIX');
    }

    public function test_a_missing_build_is_critical(): void
    {
        $this->app->usePublicPath($this->dir);

        $this->artisan('ascento:check-production')->expectsOutputToContain('No hay public/build/manifest.json')->assertFailed();
    }
}
