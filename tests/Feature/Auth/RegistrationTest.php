<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_registration_creates_company_with_admin_and_trial(): void
    {
        $response = $this->post('/register', [
            'company_name' => 'Ascensores Test',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$this->required(),
        ]);

        $user = User::where('email', 'test@example.com')->sole();
        $company = $user->company;

        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin', $user->role);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('Ascensores Test', $company->name);
        $this->assertTrue($company->onTrial());
        $response->assertRedirect(route('dashboard', ['company' => $company->slug]));
    }

    public function test_registration_ignores_injected_privilege_fields(): void
    {
        $existing = $this->makeTenant();

        $this->post('/register', [
            'company_name' => 'Atacante SA',
            'name' => 'Atacante',
            'email' => 'attacker@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$this->required(['cuit' => '20-12345678-6']),
            'company_id' => $existing['company']->id,
            'is_super_admin' => 1,
            'role' => 'superadmin',
            'slug' => $existing['company']->slug,
            'trial_ends_at' => now()->addYears(10)->toDateTimeString(),
        ]);

        $user = User::where('email', 'attacker@example.com')->sole();

        $this->assertNotSame($existing['company']->id, $user->company_id);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('admin', $user->role);
        $this->assertNotSame($existing['company']->slug, $user->company->slug);
        $this->assertTrue($user->company->trial_ends_at->lt(now()->addDays(31)));
        $this->assertSame(2, Company::count());
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', []);
        }

        $this->post('/register', [])->assertStatus(429);
    }

    /** Datos mínimos obligatorios del registro (además de nombre, email y contraseña). */
    private function required(array $overrides = []): array
    {
        return [
            'cuit' => '30-71234567-1',
            'phone' => '11 4567-8900',
            'province' => 'Buenos Aires',
            'locality' => 'Lomas de Zamora',
            'terms' => '1',
            ...$overrides,
        ];
    }

    private function register(array $overrides = [])
    {
        return $this->post('/register', [
            'company_name' => 'Ascensores del Sur',
            'name' => 'Dueña',
            'email' => 'duena@sur.test',
            'password' => 'clave-segura-123',
            'password_confirmation' => 'clave-segura-123',
            ...$this->required(),
            ...$overrides,
        ]);
    }

    public function test_registration_saves_the_minimal_company_data(): void
    {
        $this->register(['cuit' => '30712345671'])->assertSessionHasNoErrors();

        $company = User::where('email', 'duena@sur.test')->sole()->company;
        $this->assertSame('30-71234567-1', $company->cuit);           // se guarda con formato
        $this->assertSame('11 4567-8900', $company->phone);           // antes se perdía
        $this->assertSame('Buenos Aires', $company->province);
        $this->assertSame('Lomas de Zamora', $company->city);
        $this->assertSame('duena@sur.test', $company->email);
        $this->assertNull($company->business_name);                    // opcional: después
    }

    public function test_each_required_field_is_enforced(): void
    {
        // El registro tiene un límite de 6 intentos por minuto: acá se prueban 9 campos.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['company_name', 'name', 'email', 'password', 'cuit', 'phone', 'province', 'locality', 'terms'] as $field) {
            $this->register([$field => ''])->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Company::count());
    }

    public function test_invalid_cuit_province_or_phone_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->register(['cuit' => '30-71234567-2'])->assertSessionHasErrors('cuit');   // dígito verificador
        $this->register(['cuit' => '123'])->assertSessionHasErrors('cuit');
        $this->register(['province' => 'Narnia'])->assertSessionHasErrors('province');
        $this->register(['phone' => 'llamame'])->assertSessionHasErrors('phone');

        $this->assertSame(0, Company::count());
    }

    public function test_the_same_cuit_cannot_register_twice_to_get_another_trial(): void
    {
        $this->register()->assertSessionHasNoErrors();
        auth()->logout();

        $this->register(['email' => 'otra@sur.test', 'cuit' => '30712345671'])->assertSessionHasErrors('cuit');

        $this->assertSame(1, Company::count());
    }
}
