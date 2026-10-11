<?php

namespace Tests\Feature\Auth;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Rules\NotCommonPassword;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * Una sola política para crear o cambiar contraseñas, en todos los puntos de
 * entrada. Las contraseñas actuales siguen sirviendo para ingresar.
 */
class PasswordPolicyTest extends TestCase
{
    use InteractsWithTenants, PassesRegistrationChallenge, RefreshDatabase;

    private const STRONG = 'Kiosco-Verde-8241';

    private function passes(string $password, array $personal = []): bool
    {
        return Validator::make(['password' => $password, 'password_confirmation' => $password], ['password' => PasswordPolicy::rules($personal)])->passes();
    }

    public function test_weak_or_common_passwords_are_rejected_and_reasonable_ones_pass(): void
    {
        foreach ([
            'corta1',              // menos de 10
            'solamenteletras',     // sin números
            '12345678901',         // sin letras
            'password123',         // común + números al final
            'P4ssw0rd2024!',       // común con reemplazos
            'Contraseña2026',      // común en castellano
            'bocajuniors1',        // equipo de fútbol
            'qwertyuiop12',        // teclado
            'abcdefghij9',         // abecedario
            '1234567890a',         // números seguidos y una letra
            'aaaaaa111111',        // repetidos
            'ascensores2026',      // del rubro
        ] as $weak) {
            $this->assertFalse($this->passes($weak), "debería rechazar «{$weak}»");
        }

        foreach ([self::STRONG, 'mate con 3 bizcochitos', 'Tornillo7Puerta', 'una-clave-larga-1'] as $ok) {
            $this->assertTrue($this->passes($ok), "debería aceptar «{$ok}»");
        }

        $this->assertFalse($this->passes(str_repeat('a1', 37)), 'más de 72 caracteres (límite de bcrypt)');
    }

    public function test_passwords_with_the_person_name_or_email_are_rejected(): void
    {
        $this->assertFalse($this->passes('marcela2026x', ['Marcela Gómez', 'marcela.gomez@empresa.test']));
        $this->assertFalse($this->passes('gomez-taller-99', ['Marcela Gómez']));
        $this->assertFalse($this->passes('ventas.sur2026', [null, 'ventas.sur@empresa.test']));
        $this->assertTrue($this->passes(self::STRONG, ['Marcela Gómez', 'marcela.gomez@empresa.test']));
    }

    public function test_the_rule_explains_why(): void
    {
        $common = Validator::make(['p' => 'password2024'], ['p' => [new NotCommonPassword]]);
        $personal = Validator::make(['p' => 'marcela-77-taller'], ['p' => [new NotCommonPassword(['Marcela'])]]);

        $this->assertSame(NotCommonPassword::MESSAGE, $common->errors()->first('p'));
        $this->assertSame(NotCommonPassword::PERSONAL_MESSAGE, $personal->errors()->first('p'));
    }

    public function test_existing_weak_passwords_still_log_in(): void
    {
        $tenant = $this->makeTenant(); // la factory usa "password"

        $this->post('/login', ['email' => $tenant['technician']->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($tenant['technician']);
    }

    // --- Puntos de entrada ----------------------------------------------------

    public function test_company_registration_uses_the_policy(): void
    {
        $data = ['company_name' => 'Ascensores del Oeste', 'name' => 'Laura Díaz', 'email' => 'laura@oeste.test', 'cuit' => '30-71234567-1',
            'phone' => '11 4567-8900', 'province' => 'Buenos Aires', 'locality' => 'Morón', 'terms' => '1'];

        $this->post('/register', [...$this->registrationProof(), ...$data, 'password' => 'oeste12345', 'password_confirmation' => 'oeste12345'])
            ->assertSessionHasErrors('password');
        $this->post('/register', [...$this->registrationProof(), ...$data, 'password' => 'laura-2026-ok', 'password_confirmation' => 'laura-2026-ok'])
            ->assertSessionHasErrors('password'); // contiene el nombre
        $this->assertGuest();

        $this->post('/register', [...$this->registrationProof(), ...$data, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::STRONG, User::where('email', 'laura@oeste.test')->sole()->password));
    }

    public function test_password_reset_uses_the_policy(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = (fn () => $this->token)->call($n);

            return true;
        });

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'password1234', 'password_confirmation' => 'password1234'])
            ->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => self::STRONG, 'password_confirmation' => 'Otra-Distinta-99'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password), 'no cambió');
    }

    public function test_profile_password_change_uses_the_policy(): void
    {
        $tech = $this->makeTenant()['technician'];

        $this->actingAs($tech)->from('/profile')->put('/password', ['current_password' => 'password', 'password' => 'qwerty12345', 'password_confirmation' => 'qwerty12345'])
            ->assertSessionHasErrorsIn('updatePassword', 'password');
        $this->put('/password', ['current_password' => 'password', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::STRONG, $tech->fresh()->password));
    }

    public function test_panel_user_form_requires_a_strong_confirmed_password_on_create(): void
    {
        $this->actingInPanel($this->makeTenant()['admin']);
        $base = ['name' => 'Raúl Técnico', 'email' => 'raul@empresa.test', 'phone' => '1122334455'];

        Livewire::test(CreateUser::class)->fillForm([...$base, 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])
            ->call('create')->assertHasFormErrors(['password']);
        Livewire::test(CreateUser::class)->fillForm([...$base, 'password' => self::STRONG, 'password_confirmation' => 'Otra-Distinta-99'])
            ->call('create')->assertHasFormErrors(['password']);
        Livewire::test(CreateUser::class)->fillForm([...$base, 'password' => self::STRONG])
            ->call('create')->assertHasFormErrors(['password_confirmation']);
        $this->assertFalse(User::where('email', 'raul@empresa.test')->exists());

        Livewire::test(CreateUser::class)->fillForm([...$base, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check(self::STRONG, User::where('email', 'raul@empresa.test')->sole()->password));
    }

    public function test_panel_user_edit_keeps_the_password_when_left_empty_and_validates_a_new_one(): void
    {
        $tenant = $this->makeTenant();
        $tech = $tenant['technician'];
        $tech->update(['phone' => '1122334455']);
        $this->actingInPanel($tenant['admin']);

        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])->fillForm(['name' => 'Nombre nuevo'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('password', $tech->fresh()->password), 'vacía = no se cambia');

        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])->fillForm(['password' => '1234567890a', 'password_confirmation' => '1234567890a'])
            ->call('save')->assertHasFormErrors(['password']);
        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])->fillForm(['password' => self::STRONG])
            ->call('save')->assertHasFormErrors(['password_confirmation']);

        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])->fillForm(['password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check(self::STRONG, $tech->fresh()->password));
    }

    public function test_password_fields_have_a_show_hide_control(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('data-password-toggle aria-controls="password"', false)
            ->assertSee('data-password-toggle aria-controls="password_confirmation"', false)
            ->assertSee(PasswordPolicy::HINT);
        $this->get('/login')->assertSee('data-password-toggle aria-controls="password"', false);

        $this->actingInPanel($this->makeTenant()['admin']);
        Livewire::test(CreateUser::class)->assertFormFieldExists('password_confirmation');
    }
}
