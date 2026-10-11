<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use App\Support\Auth\RegistrationChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Protección del registro de empresas: campo trampa, tiempo mínimo y desafío
 * del ascensor (o su pregunta alternativa). Todo se decide en el servidor.
 */
class RegistrationChallengeTest extends TestCase
{
    use RefreshDatabase;

    private const WORDS = ['cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce'];

    private function data(array $overrides = []): array
    {
        return ['company_name' => 'Ascensores del Oeste', 'name' => 'Laura Díaz', 'email' => 'laura@oeste.test', 'cuit' => '30-71234567-1',
            'phone' => '11 4567-8900', 'province' => 'Buenos Aires', 'locality' => 'Morón', 'terms' => '1',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', ...$overrides];
    }

    /** Abre el formulario y devuelve el desafío que quedó en la sesión del servidor. */
    private function openForm(bool $wait = true): array
    {
        $this->get('/register')->assertOk();
        $wait && $this->travel(RegistrationChallenge::MIN_SECONDS + 1)->seconds();

        return session('register.challenge');
    }

    private function rightToken(array $challenge): string
    {
        return collect($challenge['buttons'])->firstWhere('digit', $challenge['target'])['token'];
    }

    private function wrongToken(array $challenge): string
    {
        return collect($challenge['buttons'])->firstWhere(fn ($b) => $b['digit'] !== $challenge['target'])['token'];
    }

    private function textAnswer(array $challenge): int
    {
        preg_match('/piso (\w+) y (sube|baja) (un piso|\w+)/', $challenge['question'], $m);
        $moves = $m[3] === 'un piso' ? 1 : array_search($m[3], self::WORDS, true);

        return array_search($m[1], self::WORDS, true) + ($m[2] === 'sube' ? $moves : -$moves);
    }

    public function test_a_person_who_solves_the_challenge_registers(): void
    {
        $challenge = $this->openForm();

        $this->post('/register', [...$this->data(), 'challenge' => $this->rightToken($challenge)])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertAuthenticated();
        $this->assertSame(1, Company::count());
    }

    public function test_the_accessible_question_works_with_digits_or_words(): void
    {
        $challenge = $this->openForm();
        $this->post('/register', [...$this->data(), 'challenge_text' => (string) $this->textAnswer($challenge)])->assertSessionHasNoErrors();
        $this->assertSame(1, Company::count());

        auth()->logout();
        $this->flushSession();
        $challenge = $this->openForm();
        $this->post('/register', [...$this->data(['email' => 'otra@oeste.test', 'cuit' => '30-71234569-8']), 'challenge_text' => ' '.ucfirst(self::WORDS[$this->textAnswer($challenge)]).' '])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Company::count());
    }

    public function test_the_honeypot_blocks_bots(): void
    {
        $challenge = $this->openForm();

        $this->post('/register', [...$this->data(), 'challenge' => $this->rightToken($challenge), RegistrationChallenge::HONEYPOT => 'https://spam.test'])
            ->assertSessionHasErrors('form');

        $this->assertGuest();
        $this->assertSame(0, Company::count());
    }

    public function test_submitting_too_fast_or_without_opening_the_form_is_rejected(): void
    {
        $challenge = $this->openForm(wait: false);
        $this->post('/register', [...$this->data(), 'challenge' => $this->rightToken($challenge)])->assertSessionHasErrors('form');

        // Directo, sin haber abierto el formulario (sin sesión).
        $this->flushSession();
        $this->post('/register', [...$this->data(), 'challenge' => 'cualquiera', 'challenge_text' => '5'])->assertSessionHasErrors('form');

        $this->assertSame(0, Company::count());
    }

    public function test_a_missing_or_wrong_answer_is_rejected(): void
    {
        $challenge = $this->openForm();

        $this->post('/register', $this->data())->assertSessionHasErrors('challenge');
        $this->post('/register', [...$this->data(), 'challenge' => $this->wrongToken($challenge)])->assertSessionHasErrors('challenge');
        $this->post('/register', [...$this->data(), 'challenge' => 'inventado-desde-el-navegador'])->assertSessionHasErrors('challenge');
        $this->post('/register', [...$this->data(), 'challenge_text' => (string) ($this->textAnswer($challenge) + 1)])->assertSessionHasErrors('challenge');

        $this->assertSame(0, Company::count());
    }

    public function test_after_too_many_attempts_the_old_answer_no_longer_works(): void
    {
        $challenge = $this->openForm();

        for ($i = 0; $i < RegistrationChallenge::MAX_ATTEMPTS; $i++) {
            $this->post('/register', [...$this->data(), 'challenge' => $this->wrongToken($challenge)])->assertSessionHasErrors('challenge');
        }

        $this->post('/register', [...$this->data(), 'challenge' => $this->rightToken($challenge)])->assertSessionHasErrors('challenge');
        $this->assertNotEquals($challenge['visual'], session('register.challenge')['visual'], 'hay un desafío nuevo');
        $this->assertSame(0, Company::count());
    }

    public function test_an_expired_challenge_is_rejected(): void
    {
        $challenge = $this->openForm();
        $this->travel(RegistrationChallenge::TTL_MINUTES + 1)->minutes();

        $this->post('/register', [...$this->data(), 'challenge' => $this->rightToken($challenge)])->assertSessionHasErrors('challenge');
        $this->assertSame(0, Company::count());
    }

    public function test_a_solved_challenge_is_single_use(): void
    {
        $challenge = $this->openForm();
        $token = $this->rightToken($challenge);
        $this->post('/register', [...$this->data(), 'challenge' => $token])->assertSessionHasNoErrors();

        // Misma sesión, mismo token, otra empresa: ya se consumió.
        auth()->logout();
        $this->post('/register', [...$this->data(['email' => 'otra@oeste.test', 'cuit' => '30-71234569-8']), 'challenge' => $token])->assertSessionHasErrors();
        $this->assertSame(1, Company::count());
    }

    public function test_a_token_from_another_session_is_useless(): void
    {
        $theirs = $this->openForm();
        $token = $this->rightToken($theirs);

        $this->flushSession();
        $this->openForm();
        $this->post('/register', [...$this->data(), 'challenge' => $token])->assertSessionHasErrors('challenge');
        $this->assertSame(0, Company::count());
    }

    public function test_once_solved_other_fields_can_be_fixed_without_solving_again(): void
    {
        $challenge = $this->openForm();
        $this->post('/register', [...$this->data(['cuit' => '123']), 'challenge' => $this->rightToken($challenge)])
            ->assertSessionHasErrors('cuit')->assertSessionDoesntHaveErrors('challenge');

        $this->get('/register')->assertSee('Comprobación completada');
        $this->travel(RegistrationChallenge::MIN_SECONDS + 1)->seconds();
        $this->post('/register', $this->data())->assertSessionHasNoErrors();
        $this->assertSame(1, Company::count());
    }

    public function test_the_page_never_reveals_the_answer(): void
    {
        $this->get('/register')->assertOk();
        $challenge = session('register.challenge');
        $html = $this->get('/register')->getContent();

        $block = substr($html, strpos($html, '<fieldset'), strpos($html, '</fieldset>') - strpos($html, '<fieldset'));
        $this->assertDoesNotMatchRegularExpression('/<text[\s>]/', $block, 'los dígitos son dibujos, no texto');
        $this->assertStringNotContainsString($challenge['visual'], $html);
        $this->assertStringNotContainsString($challenge['text'], $html);
        $this->assertStringContainsString('aria-label="Opción 1"', $html); // los botones no dicen el número
        $this->assertStringNotContainsString('aria-label="Piso', $html);
        foreach ($challenge['buttons'] as $button) {
            $this->assertStringContainsString($button['token'], $html);
        }
        // En la sesión solo están los HMAC, no las respuestas.
        $this->assertSame(64, strlen($challenge['visual']));
        $this->assertNotSame((string) $this->textAnswer($challenge), $challenge['text']);
    }

    public function test_every_visitor_gets_a_different_challenge(): void
    {
        $first = $this->openForm(wait: false);
        $this->flushSession();
        $second = $this->openForm(wait: false);

        $this->assertNotSame(array_column($first['buttons'], 'token'), array_column($second['buttons'], 'token'));
    }

    public function test_registration_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', []);
        }

        $this->post('/register', [])->assertStatus(429);
        $this->assertSame(0, User::count());
    }
}
