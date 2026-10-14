<?php

namespace Tests\Feature\Stage11;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Notifications\LoginAttemptsNotification;
use App\Services\Auth\AccountAttackGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11 (R6): intentos repetidos contra una cuenta desde muchas IP. Se
 * frena con un desafío humano, nunca con un bloqueo de la cuenta.
 */
class AccountAttackGuardTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected bool $superAdminPassesTwoFactor = false;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $a = $this->makeTenant();
        $this->tech = $a['technician'];
        $this->tech->forceFill(['email' => 'victima@empresa.test', 'password' => Hash::make('clave-correcta-123')])->saveQuietly();
    }

    private function attempt(string $email, string $password, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->from('/login')->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** Botnet: cada fallo desde una IP distinta (no salta el límite por IP ni el de correo + IP). */
    private function attackFromManyIps(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->attempt($email, 'incorrecta-'.$i, '10.0.'.intdiv($i, 250).'.'.($i % 250 + 1));
        }
    }

    /** Resuelve el desafío que la página de ingreso acaba de mostrar. */
    private function solveChallenge(): array
    {
        $this->get('/login')->assertOk()->assertSee('data-challenge-input', false);
        $challenge = session('register.challenge');

        return ['challenge' => collect($challenge['buttons'])->firstWhere('digit', $challenge['target'])['token']];
    }

    public function test_many_ips_against_one_account_get_a_human_check_but_the_owner_still_gets_in(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);

        // Ni siquiera con la contraseña correcta se puede seguir probando sin el desafío...
        $this->attempt('victima@empresa.test', 'clave-correcta-123', '192.0.2.50')->assertSessionHasErrors('challenge');
        $this->assertGuest();
        $this->get('/login')->assertSee('Hubo muchos intentos fallidos con este correo');

        // ...pero la persona real lo resuelve y entra: la cuenta no se bloquea.
        $proof = $this->solveChallenge();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->post('/login', ['email' => 'victima@empresa.test', 'password' => 'clave-correcta-123'] + $proof);
        $this->assertAuthenticatedAs($this->tech);

        // El ingreso correcto pone el contador en cero.
        $this->assertFalse(app(AccountAttackGuard::class)->underAttack('victima@empresa.test'));
    }

    public function test_a_wrong_challenge_answer_does_not_let_the_attacker_keep_trying(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);
        $this->get('/login');
        $this->attempt('victima@empresa.test', 'otra-mas', '203.0.113.9')->assertSessionHasErrors('challenge');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/login', ['email' => 'victima@empresa.test', 'password' => 'otra-mas', 'challenge' => 'token-inventado'])
            ->assertSessionHasErrors('challenge');
        $this->assertGuest();
    }

    public function test_one_solved_challenge_does_not_open_a_window_for_more_guesses(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);

        // Resuelve el desafío una vez, con una contraseña incorrecta...
        $proof = $this->solveChallenge();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->post('/login', ['email' => 'victima@empresa.test', 'password' => 'otra-1'] + $proof)
            ->assertSessionHasErrors('email');

        // ...y con la misma sesión (que se puede usar desde muchas IP) sigue probando sin resolverlo.
        foreach (['198.51.100.21', '198.51.100.22', '198.51.100.23'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => 'victima@empresa.test', 'password' => 'clave-correcta-123'])
                ->assertSessionHasErrors('challenge');
        }
        $this->assertGuest();
    }

    public function test_a_legitimate_user_who_mistypes_a_few_times_sees_nothing_new(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('victima@empresa.test', 'me-equivoque', '198.51.100.7')->assertSessionHasErrors('email');
        }
        $this->get('/login')->assertDontSee('data-challenge-input', false);
        $this->attempt('victima@empresa.test', 'clave-correcta-123', '198.51.100.7');
        $this->assertAuthenticatedAs($this->tech);
        Notification::assertNothingSent();
    }

    public function test_existing_and_unknown_accounts_get_the_same_answers(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);
        $this->attackFromManyIps('nadie@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);

        $this->attempt('victima@empresa.test', 'x', '192.0.2.77')->assertSessionHasErrors('challenge')->assertSessionDoesntHaveErrors('email');
        $this->attempt('nadie@empresa.test', 'x', '192.0.2.78')->assertSessionHasErrors('challenge')->assertSessionDoesntHaveErrors('email');

        // Antes del umbral, el mismo mensaje para los dos.
        $this->attempt('otro-real@empresa.test', 'x', '192.0.2.80')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->attempt('victima@empresa.test', 'x', '192.0.2.81')->assertSessionHasErrors('challenge'); // la víctima sigue con desafío
        $this->travel(AccountAttackGuard::ACCOUNT_WINDOW_SECONDS + 1)->seconds();
        $this->attempt('victima@empresa.test', 'x', '192.0.2.82')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->attempt('nadie-tampoco@empresa.test', 'x', '192.0.2.83')->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    public function test_the_owner_alert_is_limited_and_unknown_accounts_never_trigger_mail(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD * 3);
        Notification::assertSentToTimes($this->tech, LoginAttemptsNotification::class, 1);

        $this->attackFromManyIps('nadie@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD * 2);
        Notification::assertSentTimes(LoginAttemptsNotification::class, 1);

        // Pasado el plazo, puede volver a avisar (una vez).
        $this->travel(AccountAttackGuard::ALERT_HOURS)->hours();
        $this->travel(1)->minutes();
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);
        Notification::assertSentToTimes($this->tech, LoginAttemptsNotification::class, 2);
    }

    public function test_a_platform_wide_spike_alerts_the_super_admin_once_an_hour(): void
    {
        $sa = User::factory()->superAdmin()->create();
        for ($i = 0; $i < AccountAttackGuard::SPIKE_THRESHOLD + 50; $i++) {
            $this->attempt("persona{$i}@ejemplo.test", 'x', '10.9.'.intdiv($i, 250).'.'.($i % 250 + 1));
        }
        Notification::assertSentToTimes($sa, LoginAttemptsNotification::class, 1);
    }

    public function test_there_is_no_permanent_block(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);
        $this->travel(AccountAttackGuard::ACCOUNT_WINDOW_SECONDS + 1)->seconds();

        $this->attempt('victima@empresa.test', 'clave-correcta-123', '192.0.2.99');
        $this->assertAuthenticatedAs($this->tech);
    }

    public function test_one_ip_against_many_accounts_is_still_limited(): void
    {
        for ($i = 0; $i < LoginRequest::IP_MAX_FAILURES; $i++) {
            $this->attempt("persona{$i}@ejemplo.test", 'x', '203.0.113.200');
        }
        $this->attempt('victima@empresa.test', 'clave-correcta-123', '203.0.113.200')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_portal_login_also_shows_the_challenge(): void
    {
        $this->attackFromManyIps('victima@empresa.test', AccountAttackGuard::ACCOUNT_THRESHOLD);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->from('/portal/ingresar')->post('/login', ['email' => 'victima@empresa.test', 'password' => 'x']);
        $this->get('/portal/ingresar')->assertOk()->assertSee('data-challenge-input', false);
    }

    public function test_with_2fa_the_super_admin_still_needs_the_challenge_then_the_code(): void
    {
        $sa = User::factory()->superAdmin()->create(['email' => 'sa@ascento.test', 'password' => Hash::make('clave-super-123')]);
        $this->attackFromManyIps('sa@ascento.test', AccountAttackGuard::ACCOUNT_THRESHOLD);

        $proof = $this->solveChallenge();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.60'])->post('/login', ['email' => 'sa@ascento.test', 'password' => 'clave-super-123'] + $proof);
        $this->assertAuthenticatedAs($sa);
        // La contraseña y el desafío no alcanzan: falta el segundo factor.
        $this->get('/admin/dashboard')->assertRedirect(route('two-factor.setup'));
    }
}
