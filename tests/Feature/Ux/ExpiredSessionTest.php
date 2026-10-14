<?php

namespace Tests\Feature\Ux;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sesión vencida en una acción de Livewire del panel: antes llegaba al
 * componente sin usuario y terminaba en un error 500. La autenticación del
 * panel (AuthenticatePanel) no se volvía a aplicar en /livewire/update porque
 * Livewire solo repite los middlewares marcados como persistentes, y compara
 * el nombre exacto de la clase (Filament marca el suyo, no el nuestro).
 */
class ExpiredSessionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        Subscription::create(['company_id' => $this->a['company']->id, 'provider' => 'mercadopago', 'plan' => 'empresa', 'status' => 'authorized', 'amount' => 1, 'current_period_end' => now()->addMonth()]);
        $this->a['admin']->forceFill(['password' => Hash::make('clave-dueno-123')])->saveQuietly();
    }

    /** Snapshot firmado del componente de la página (el que Livewire manda de vuelta en cada acción). */
    private function snapshotFrom(string $html, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        foreach ($m[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);
            if (str_contains((string) (json_decode($snapshot, true)['memo']['name'] ?? ''), $component)) {
                return $snapshot;
            }
        }
        $this->fail("No encontré el componente {$component} en la página.");
    }

    /** Igual que el navegador: cuerpo JSON y X-Livewire, sin pedir JSON como respuesta (Accept). */
    private function livewireAction(string $snapshot, string $method = '$refresh'): TestResponse
    {
        $body = json_encode(['components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['method' => $method, 'params' => []]]]]]);

        return $this->call('POST', route('default-livewire.update'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LIVEWIRE' => '1',
            'HTTP_REFERER' => url('/admin/work-orders'),
        ], $body);
    }

    /** La sesión venció: el navegador sigue con la página abierta, pero el servidor ya no la reconoce. */
    private function expireSession(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function listSnapshot(User $user): string
    {
        $this->actingAs($user);

        return $this->snapshotFrom($this->get('/admin/work-orders')->assertOk()->getContent(), 'ListWorkOrders');
    }

    public function test_a_valid_session_keeps_working(): void
    {
        $snapshot = $this->listSnapshot($this->a['admin']);

        $this->livewireAction($snapshot)->assertOk()->assertJsonStructure(['components']);
    }

    public function test_an_expired_session_sends_the_livewire_action_to_login_and_the_user_can_sign_in_again(): void
    {
        $snapshot = $this->listSnapshot($this->a['admin']);
        $this->expireSession();

        // Livewire sigue la redirección y lleva la página al ingreso (no un 500 ni un modal de error).
        $this->livewireAction($snapshot)->assertRedirect(route('login'));
        $this->assertGuest();

        // Vuelve a ingresar y entra a su panel; la acción se puede repetir.
        $this->get(route('login'))->assertOk();
        $this->post('/login', ['email' => $this->a['admin']->email, 'password' => 'clave-dueno-123'])->assertRedirect();
        $this->assertAuthenticatedAs($this->a['admin']);
        $this->get('/admin/work-orders')->assertOk();
    }

    public function test_a_logged_in_user_without_panel_access_cannot_run_panel_actions(): void
    {
        $snapshot = $this->listSnapshot($this->a['admin']);
        $this->expireSession();
        $this->actingAs($this->a['technician']);

        $this->livewireAction($snapshot)->assertForbidden();
    }

    public function test_a_super_admin_without_the_second_factor_is_still_stopped(): void
    {
        $super = User::factory()->superAdmin()->create();
        $snapshot = $this->listSnapshot($this->a['admin']);
        $this->expireSession();

        $this->superAdminPassesTwoFactor = false;
        $this->actingAs($super);

        $this->livewireAction($snapshot)->assertForbidden();
    }

    public function test_the_technician_app_and_the_portal_send_an_expired_session_to_login(): void
    {
        $slug = $this->a['company']->slug;

        $this->actingAs($this->a['technician']);
        $this->get("/{$slug}/work-orders")->assertOk();
        $this->expireSession();
        $this->get("/{$slug}/work-orders")->assertRedirect(route('login'));
        $this->patch("/{$slug}/profile", ['name' => 'X'])->assertRedirect(route('login'));

        $this->get('/portal')->assertRedirect();
        $this->assertGuest();
    }
}
