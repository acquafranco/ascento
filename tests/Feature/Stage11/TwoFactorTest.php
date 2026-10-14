<?php

namespace Tests\Feature\Stage11;

use App\Models\Backup;
use App\Models\Company;
use App\Models\User;
use App\Notifications\TwoFactorSecurityNotification;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11: segundo factor obligatorio del SuperAdmin. Se prueba con pedidos
 * HTTP reales (sin el atajo de TestCase): el control vive en cada pedido, no
 * solo en el formulario de ingreso.
 */
class TwoFactorTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected bool $superAdminPassesTwoFactor = false;

    protected User $sa;

    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->sa = User::factory()->superAdmin()->create(['email' => 'sa@ascento.test', 'password' => Hash::make('clave-super-123')]);
        Log::listen(function ($m) {
            $this->logged[] = $m->message.' '.json_encode($m->context);
        });
    }

    private function service(): TwoFactorAuthentication
    {
        return app(TwoFactorAuthentication::class);
    }

    private function login(User $user, string $password = 'clave-super-123', bool $remember = false): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password, 'remember' => $remember ? '1' : null]);
    }

    /** Código vigente de la app (o el de otro momento). */
    private function code(User $user, ?int $at = null): string
    {
        $engine = $this->service()->engine();

        return $engine->oathTotp($user->fresh()->two_factor_secret, intdiv($at ?? now()->getTimestamp(), 30));
    }

    /** SuperAdmin con el 2FA ya configurado (como si lo hubiera hecho antes). */
    private function activated(): array
    {
        $this->actingAs($this->sa);
        $this->post(route('two-factor.start'), ['password' => 'clave-super-123'])->assertRedirect(route('two-factor.setup'));
        $codes = $this->post(route('two-factor.confirm'), ['code' => $this->code($this->sa)])->assertOk()->viewData('codes');
        $this->travel(61)->seconds(); // el código usado no se puede repetir: dos pasos después
        auth()->logout();
        $this->flushSession();

        return $codes;
    }

    private function assertNoSecretsLogged(): void
    {
        $all = implode("\n", $this->logged);
        $secret = (string) $this->sa->fresh()->two_factor_secret;
        if ($secret !== '') {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', $all, 'Ningún código de 6 dígitos en el log.');
    }

    /* ------------------------------------------------------------ ALTA */

    public function test_a_super_admin_without_2fa_can_only_configure_it(): void
    {
        $this->login($this->sa)->assertRedirect();
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.setup'));
        $this->get('/admin/companies')->assertRedirect(route('two-factor.setup'));
        $this->get(route('two-factor.setup'))->assertOk()->assertSee('Configurá el segundo factor');
        // Puede salir.
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_activation_needs_the_password_and_a_valid_code_and_shows_codes_once(): void
    {
        $this->actingAs($this->sa);
        $this->post(route('two-factor.start'), ['password' => 'incorrecta-999'])->assertSessionHasErrors('password');
        $this->assertNull($this->sa->fresh()->getAttributes()['two_factor_secret'] ?? null);

        $this->post(route('two-factor.start'), ['password' => 'clave-super-123'])->assertRedirect(route('two-factor.setup'));
        $page = $this->get(route('two-factor.setup'))->assertOk();
        $page->assertSee('data:image/svg+xml;base64,', false)->assertSee('data-two-factor-setup', false);

        // Código incorrecto: no se activa.
        $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($this->sa->fresh()->two_factor_confirmed_at);
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.setup'));

        // Código válido: activo, códigos de recuperación una sola vez, y entra.
        $response = $this->post(route('two-factor.confirm'), ['code' => $this->code($this->sa)])->assertOk();
        $codes = $response->viewData('codes');
        $this->assertCount(TwoFactorAuthentication::RECOVERY_CODES, $codes);
        $this->assertNotNull($this->sa->fresh()->two_factor_confirmed_at);
        $this->get('/admin/dashboard')->assertOk();
        $this->get(route('two-factor.setup'))->assertRedirect(route('two-factor.manage')); // los códigos no se vuelven a ver

        // En la base: cifrados, los códigos con hash; nunca en arrays/JSON.
        $raw = DB::table('users')->where('id', $this->sa->id)->first();
        $this->assertStringNotContainsString($this->sa->fresh()->two_factor_secret, (string) $raw->two_factor_secret);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), (string) $raw->two_factor_recovery_codes);
            $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($this->sa->fresh()->two_factor_recovery_codes));
        }
        $this->assertArrayNotHasKey('two_factor_secret', $this->sa->fresh()->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $this->sa->fresh()->toArray());
        Notification::assertSentTo($this->sa, TwoFactorSecurityNotification::class, fn ($n) => $n->event === TwoFactorSecurityNotification::ACTIVATED);
        $this->assertNoSecretsLogged();
    }

    public function test_an_unfinished_setup_expires_and_a_lost_session_just_starts_over(): void
    {
        $this->actingAs($this->sa);
        $this->post(route('two-factor.start'), ['password' => 'clave-super-123']);
        $this->travel(TwoFactorAuthentication::SETUP_MINUTES + 1)->minutes();
        $this->post(route('two-factor.confirm'), ['code' => $this->code($this->sa)])->assertRedirect(route('two-factor.setup'));
        $this->assertNull($this->sa->fresh()->two_factor_confirmed_at);

        // Se cortó la sesión a mitad: vuelve a empezar con la contraseña (secreto nuevo).
        $first = $this->sa->fresh()->two_factor_secret;
        $this->flushSession();
        $this->actingAs($this->sa->fresh());
        $this->get(route('two-factor.setup'))->assertOk()->assertSee('Contraseña actual');
        $this->post(route('two-factor.start'), ['password' => 'clave-super-123']);
        $this->assertNotSame($first, $this->sa->fresh()->two_factor_secret);
    }

    /* -------------------------------------------------------- DESAFÍO */

    public function test_login_requires_the_code_on_every_route_including_livewire_backups_and_broadcasting(): void
    {
        $this->activated();
        $this->login($this->sa)->assertRedirect();
        $this->assertAuthenticatedAs($this->sa); // contraseña bien, pero sin el código no hay nada más

        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.challenge'));
        $this->get('/admin/backups')->assertRedirect(route('two-factor.challenge'));
        $backup = new Backup;
        $backup->forceFill(['type' => 'manual', 'path' => 'backups/x.zip', 'status' => 'completed'])->saveQuietly();
        $this->get(route('backups.download', $backup))->assertRedirect(route('two-factor.challenge'));
        $this->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), ['components' => []])->assertForbidden();
        $this->flushHeaders();
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-App.Models.User.'.$this->sa->id])->assertForbidden();
        // Las pantallas de gestión tampoco se abren antes de pasar el código.
        $this->get(route('two-factor.manage'))->assertForbidden();
        $this->post(route('two-factor.reset-other'), ['email' => 'x@y.test', 'password' => 'clave-super-123', 'code' => '000000'])->assertForbidden();
        $this->post('/admin/logout')->assertRedirect(); // salir siempre se puede

        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => $this->code($this->sa)])->assertSessionHasNoErrors();
        $this->get('/admin/dashboard')->assertOk()->assertSee(route('two-factor.manage'), false); // acceso desde el menú
        $this->assertNoSecretsLogged();
    }

    public function test_wrong_old_and_reused_codes_are_rejected(): void
    {
        $this->activated();
        $this->login($this->sa);

        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        // Fuera de la ventana (de hace 2 minutos).
        $this->post(route('two-factor.verify'), ['code' => $this->code($this->sa, now()->getTimestamp() - 120)])->assertSessionHasErrors('code');
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.challenge'));

        // Un desfase chico del reloj del teléfono (30 s) se acepta.
        $code = $this->code($this->sa, now()->getTimestamp() - 30);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasNoErrors();
        $this->get('/admin/dashboard')->assertOk();

        // El mismo código no sirve otra vez (otro ingreso enseguida).
        $this->post('/logout');
        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_recovery_codes_work_once(): void
    {
        $codes = $this->activated();
        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertRedirect();
        $this->get('/admin/dashboard')->assertOk();
        Notification::assertSentTo($this->sa, TwoFactorSecurityNotification::class, fn ($n) => $n->event === TwoFactorSecurityNotification::RECOVERY_USED && $n->data['remaining'] === 7);

        $this->post('/logout');
        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->post(route('two-factor.verify'), ['code' => strtolower(str_replace('-', ' ', $codes[1]))])->assertRedirect(); // sin guion ni mayúsculas
    }

    public function test_repeated_failures_end_the_challenge_and_then_wait_but_never_lock_forever(): void
    {
        $this->activated();
        $this->login($this->sa);
        for ($i = 0; $i < TwoFactorAuthentication::MAX_CHALLENGE_FAILURES - 1; $i++) {
            $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        // El 5.º fallo anula el desafío: hay que volver a poner la contraseña.
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertRedirect(route('login'));
        $this->assertGuest();
        // Aviso por correo, una sola vez por hora.
        Notification::assertSentToTimes($this->sa, TwoFactorSecurityNotification::class, 2); // activación + fallos

        // Otro desafío (sesión nueva): con 10 fallos por cuenta en 15 minutos, hay que esperar.
        $this->login($this->sa);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post('/logout');
        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code'); // 10.º fallo de la cuenta
        // Primero frena el límite de la ruta (10 por minuto)...
        $this->post(route('two-factor.verify'), ['code' => $this->code($this->sa)])->assertStatus(429);
        // ...y pasado ese minuto, el de la cuenta (15 minutos), aunque el código sea correcto.
        $this->travel(61)->seconds();
        $this->post(route('two-factor.verify'), ['code' => $this->code($this->sa)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Esperá', session('errors')->first('code'));
        Notification::assertSentToTimes($this->sa, TwoFactorSecurityNotification::class, 2); // sin avisos de más

        // Nunca un bloqueo permanente: pasada la ventana, entra.
        $this->travel(TwoFactorAuthentication::ACCOUNT_WINDOW_SECONDS + 1)->seconds();
        $this->post(route('two-factor.verify'), ['code' => $this->code($this->sa)])->assertSessionHasNoErrors();
        $this->get('/admin/dashboard')->assertOk();
    }

    /* ------------------------------------- SESIONES Y «RECORDARME» */

    public function test_remember_me_and_existing_sessions_still_need_the_code(): void
    {
        $this->activated();

        // «Recordarme»: la cookie abre una sesión nueva, pero sin el código no entra.
        $response = $this->login($this->sa, remember: true);
        $recaller = Auth::guard('web')->getRecallerName();
        $cookie = $response->getCookie($recaller, false)?->getValue();
        $this->assertNotNull($cookie);
        $this->flushSession();
        Auth::forgetGuards();
        $this->withUnencryptedCookie($recaller, $cookie)->get('/admin/dashboard')->assertRedirect(route('two-factor.challenge'));

        // Sesión que ya estaba abierta (sin la marca del segundo factor): tampoco.
        $this->flushSession();
        Auth::forgetGuards();
        $this->be($this->sa->fresh());
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_activating_in_one_session_closes_the_others(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'otra-sesion-vieja', 'user_id' => $this->sa->id, 'ip_address' => null, 'user_agent' => null, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);
        $remember = $this->sa->fresh()->remember_token;

        $this->activated();

        $this->assertSame(0, DB::table('sessions')->where('id', 'otra-sesion-vieja')->count());
        $this->assertNotSame($remember, $this->sa->fresh()->remember_token, '«Recordarme» de otros dispositivos deja de valer.');
    }

    /* ------------------------------------------------- GESTIÓN Y RESCATE */

    private function passIn(): void
    {
        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => $this->code($this->sa)]);
        $this->travel(31)->seconds();
    }

    public function test_sensitive_changes_need_password_and_code(): void
    {
        $codes = $this->activated();
        $this->passIn();
        $this->get(route('two-factor.manage'))->assertOk()->assertSee('Te quedan 8');

        $this->post(route('two-factor.regenerate'), ['password' => 'clave-super-123', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('two-factor.regenerate'), ['password' => 'incorrecta', 'code' => $this->code($this->sa)])->assertSessionHasErrors('password');
        $new = $this->post(route('two-factor.regenerate'), ['password' => 'clave-super-123', 'code' => $this->code($this->sa)])->assertOk()->viewData('codes');
        $this->assertNotEquals($codes, $new);

        // Los viejos ya no sirven.
        $this->post('/logout');
        $this->login($this->sa);
        $this->post(route('two-factor.verify'), ['code' => $codes[2]])->assertSessionHasErrors('code');
        $this->post(route('two-factor.verify'), ['code' => $new[0]])->assertRedirect();

        // Volver a configurar (teléfono nuevo): pide contraseña y código; después, configurar de nuevo.
        $this->travel(31)->seconds();
        $this->post(route('two-factor.reconfigure'), ['password' => 'clave-super-123', 'code' => $this->code($this->sa)])->assertRedirect(route('two-factor.setup'));
        $this->assertNull($this->sa->fresh()->two_factor_confirmed_at);
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.setup'));
    }

    public function test_resetting_your_own_2fa_keeps_this_session_with_database_sessions(): void
    {
        // Sesiones en la base, como en producción: la actual y otra de otro dispositivo.
        config(['session.driver' => 'database']);
        $this->activated();
        session()->setId(str_repeat('a', 40));
        $row = fn (string $id) => ['id' => $id, 'user_id' => $this->sa->id, 'ip_address' => null, 'user_agent' => null, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()];
        DB::table('sessions')->insert([$row(session()->getId()), $row('otro-dispositivo')]);

        // «Configurar con otro teléfono»: sigue en esta sesión para configurarlo enseguida.
        $this->service()->reset($this->sa->fresh(), $this->sa->fresh(), 'self');
        $this->assertSame(1, DB::table('sessions')->where('id', session()->getId())->count());
        $this->assertSame(0, DB::table('sessions')->where('id', 'otro-dispositivo')->count());

        // Lo restablece otro SuperAdmin o la consola: se cierran todas.
        $this->service()->reset($this->sa->fresh(), User::factory()->superAdmin()->create(), 'superadmin');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->sa->id)->count());
    }

    public function test_another_super_admin_can_reset_a_lost_second_factor(): void
    {
        $this->activated();
        $other = User::factory()->superAdmin()->create(['email' => 'otro-sa@ascento.test', 'password' => Hash::make('clave-otro-123')]);
        $owner = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id, 'email' => 'duenio@ejemplo.test']);

        // El otro SuperAdmin configura y pasa su propio 2FA.
        $this->actingAs($other);
        $this->post(route('two-factor.start'), ['password' => 'clave-otro-123']);
        $this->post(route('two-factor.confirm'), ['code' => $this->code($other)]);
        $this->travel(31)->seconds();

        // No sirve para cuentas que no son de SuperAdmin, ni para sí mismo, ni sin su código.
        $this->post(route('two-factor.reset-other'), ['email' => $owner->email, 'password' => 'clave-otro-123', 'code' => $this->code($other)])->assertSessionHasErrors('email');
        $this->travel(31)->seconds();
        $this->post(route('two-factor.reset-other'), ['email' => $other->email, 'password' => 'clave-otro-123', 'code' => $this->code($other)])->assertSessionHasErrors('email');
        $this->post(route('two-factor.reset-other'), ['email' => $this->sa->email, 'password' => 'clave-otro-123', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNotNull($this->sa->fresh()->two_factor_confirmed_at);

        $this->travel(31)->seconds();
        $this->post(route('two-factor.reset-other'), ['email' => $this->sa->email, 'password' => 'clave-otro-123', 'code' => $this->code($other)])->assertRedirect(route('two-factor.manage'));
        $this->assertNull($this->sa->fresh()->two_factor_confirmed_at);
        Notification::assertSentTo($this->sa, TwoFactorSecurityNotification::class, fn ($n) => $n->event === TwoFactorSecurityNotification::RESET);

        // El afectado entra con su contraseña y lo configura de nuevo.
        $this->post('/logout');
        $this->login($this->sa);
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.setup'));
    }

    public function test_the_console_command_resets_safely_and_repeatably(): void
    {
        $this->activated();
        $owner = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id, 'email' => 'duenio@ejemplo.test']);

        $this->artisan('security:reset-2fa', ['email' => 'no-es-un-correo', '--force' => true])->assertExitCode(2);
        $this->artisan('security:reset-2fa', ['email' => 'nadie@ascento.test', '--force' => true])->assertExitCode(1);
        $this->artisan('security:reset-2fa', ['email' => $owner->email, '--force' => true])->assertExitCode(1);
        $this->assertNotNull($this->sa->fresh()->two_factor_confirmed_at);

        // Sin --force pide confirmación; si se dice que no, no cambia nada.
        $this->artisan('security:reset-2fa', ['email' => 'SA@ascento.test'])->expectsConfirmation('¿Quitar el segundo factor de '.$this->sa->name.' (id '.$this->sa->id.') y cerrar todas sus sesiones?', 'no')->assertExitCode(1);
        $this->assertNotNull($this->sa->fresh()->two_factor_confirmed_at);

        $remember = $this->sa->fresh()->remember_token;
        $this->artisan('security:reset-2fa', ['email' => 'SA@ascento.test', '--force' => true])
            ->expectsOutputToContain('se quitó el segundo factor')->assertExitCode(0);
        $this->assertNull($this->sa->fresh()->two_factor_confirmed_at);
        $this->assertNotSame($remember, $this->sa->fresh()->remember_token);
        // Repetirlo no rompe nada.
        $this->artisan('security:reset-2fa', ['email' => 'sa@ascento.test', '--force' => true])
            ->expectsOutputToContain('no tenía segundo factor activo')->assertExitCode(0);
        $this->assertNoSecretsLogged();
    }

    public function test_an_unreadable_secret_does_not_crash_and_can_be_rescued(): void
    {
        $this->activated();
        // Como si APP_KEY hubiera cambiado: el valor guardado ya no se descifra.
        DB::table('users')->where('id', $this->sa->id)->update(['two_factor_secret' => 'eyJpdiI6ImFiYyJ9-no-descifrable']);

        $this->login($this->sa);
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.challenge'));
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code'); // sin error 500

        $this->artisan('security:reset-2fa', ['email' => 'sa@ascento.test', '--force' => true])->assertExitCode(0);
        $this->assertNull($this->sa->fresh()->two_factor_confirmed_at);
    }

    /* ------------------------------------------------ OTROS USUARIOS */

    public function test_other_users_are_not_affected(): void
    {
        $a = $this->makeTenant();
        $a['admin']->forceFill(['password' => Hash::make('clave-duenio-123')])->save();
        $a['technician']->forceFill(['password' => Hash::make('clave-tecnico-123')])->save();

        $this->post('/login', ['email' => $a['admin']->email, 'password' => 'clave-duenio-123']);
        $this->get('/admin/dashboard')->assertOk();
        $this->get(route('two-factor.setup'))->assertNotFound();
        $this->post('/logout');

        $this->post('/login', ['email' => $a['technician']->email, 'password' => 'clave-tecnico-123']);
        $this->get('/'.$a['company']->slug.'/dashboard')->assertOk();
        $this->get(route('two-factor.challenge'))->assertNotFound();
    }

    public function test_the_emergency_switch_turns_enforcement_off(): void
    {
        config(['app.superadmin_two_factor' => false]);
        $this->login($this->sa);
        $this->get('/admin/dashboard')->assertOk()->assertDontSee(route('two-factor.manage'), false);
    }
}
