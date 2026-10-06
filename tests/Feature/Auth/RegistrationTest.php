<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
