<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\HomeRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Entrar" nunca termina en un 403: cada cuenta va a la pantalla de su rol,
 * y una sesión sin destino válido se cierra y vuelve al login.
 */
class RoleRedirectTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
    }

    private function dashboard(): string
    {
        return route('dashboard', ['company' => $this->a['company']->slug]);
    }

    public function test_login_sends_each_role_to_its_home(): void
    {
        $super = User::factory()->superAdmin()->create();

        foreach ([
            [$this->a['technician'], $this->dashboard()],
            [$this->a['admin'], url('/admin')],
            [$super, url('/admin')],
        ] as [$user, $home]) {
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($home);
            $this->post('/logout');
        }
    }

    public function test_already_logged_in_user_pressing_entrar_goes_to_their_home(): void
    {
        $this->actingAs($this->a['technician'])->get('/login')->assertRedirect($this->dashboard());
        $this->actingAs($this->a['admin'])->get('/login')->assertRedirect(url('/admin'));
    }

    public function test_home_page_redirects_logged_in_users_by_role(): void
    {
        $this->actingAs($this->a['technician'])->get('/')->assertRedirect($this->dashboard());
        $this->actingAs($this->a['admin'])->get('/')->assertRedirect(url('/admin'));
    }

    public function test_technician_session_on_admin_panel_goes_to_the_technician_app(): void
    {
        $this->actingAs($this->a['technician'])
            ->get('/admin')
            ->assertRedirect($this->dashboard());

        $this->assertAuthenticatedAs($this->a['technician']);
    }

    public function test_guest_on_admin_panel_or_company_app_goes_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get($this->dashboard())->assertRedirect('/login');
    }

    public function test_account_without_company_is_logged_out_with_a_message(): void
    {
        $this->a['company']->delete(); // empresa dada de baja por el SuperAdmin

        foreach ([$this->a['technician'], $this->a['admin']] as $user) {
            $this->actingAs($user->fresh())
                ->get('/login')
                ->assertRedirect(route('login'));

            $this->assertGuest();

            $this->get('/login')->assertOk()->assertSee(HomeRedirect::NO_ACCESS_MESSAGE);
        }
    }

    public function test_account_without_company_cannot_log_in(): void
    {
        $this->a['company']->delete();

        $this->post('/login', ['email' => $this->a['technician']->email, 'password' => 'password'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_admin_of_a_deleted_company_on_the_panel_is_logged_out(): void
    {
        $this->a['company']->delete();

        $this->actingAs($this->a['admin']->fresh())
            ->get('/admin')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
