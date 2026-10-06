<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_technician_logs_in_to_their_company_dashboard(): void
    {
        $a = $this->makeTenant();

        $response = $this->post('/login', [
            'email' => $a['technician']->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($a['technician']);
        $response->assertRedirect(route('dashboard', ['company' => $a['company']->slug]));
    }

    public function test_company_admin_logs_in_to_the_panel(): void
    {
        $a = $this->makeTenant();

        $this->post('/login', ['email' => $a['admin']->email, 'password' => 'password'])
            ->assertRedirect('/admin');
    }

    public function test_super_admin_without_company_logs_in_without_errors(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->post('/login', ['email' => $superAdmin->email, 'password' => 'password'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($superAdmin);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $a = $this->makeTenant();

        $this->post('/login', ['email' => $a['technician']->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $a = $this->makeTenant();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $a['technician']->email, 'password' => 'wrong']);
        }

        // Ni siquiera la contraseña correcta entra mientras dura el bloqueo.
        $this->post('/login', ['email' => $a['technician']->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_logout_and_lose_access(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        $this->get("/{$a['company']->slug}/dashboard")->assertRedirect('/login');
    }

    public function test_logout_requires_post(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])->get('/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($a['technician']);
    }

    public function test_deactivated_user_loses_access_and_cannot_log_in(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $this->actingAs($a['technician'])->get("/{$slug}/dashboard")->assertOk();

        // El admin lo desactiva (soft delete).
        $this->actingAs($a['admin']);
        $a['technician']->delete();
        $this->assertSoftDeleted($a['technician']);

        // Su sesión vieja ya no lo identifica...
        $this->app['auth']->forgetGuards();
        $this->withSession(['login_web_'.sha1(SessionGuard::class) => $a['technician']->id])
            ->get("/{$slug}/dashboard")
            ->assertRedirect('/login');

        // ...y no puede volver a entrar.
        $this->post('/login', ['email' => $a['technician']->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_authenticated_user_visiting_login_is_sent_home(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])
            ->get('/login')
            ->assertRedirect(route('dashboard', ['company' => $a['company']->slug]));
    }
}
