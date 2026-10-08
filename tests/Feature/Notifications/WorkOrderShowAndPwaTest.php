<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Destino del push (detalle de la orden) y piezas PWA.
 */
class WorkOrderShowAndPwaTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    private function order(array $tenant, array $technicians = [], array $attributes = []): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $tenant['building']->id, 'unit' => 'Ascensor DETALLE', ...$attributes]);
        $workOrder->users()->attach(collect($technicians)->pluck('id'));

        return $workOrder;
    }

    private function showUrl(array $tenant, WorkOrder|int $workOrder): string
    {
        $id = $workOrder instanceof WorkOrder ? $workOrder->id : $workOrder;

        return "/{$tenant['company']->slug}/work-orders/{$id}";
    }

    public function test_assigned_technician_opens_the_order(): void
    {
        $workOrder = $this->order($this->a, [$this->a['technician']]);

        $this->actingAs($this->a['technician'])
            ->get($this->showUrl($this->a, $workOrder))
            ->assertOk()
            ->assertSee('Ascensor DETALLE')
            ->assertSee($this->a['building']->client->name)
            ->assertSee('Tomar trabajo');
    }

    public function test_not_assigned_technician_gets_a_clear_message_without_details(): void
    {
        $workOrder = $this->order($this->a, [], ['notes' => 'DETALLE PRIVADO']);
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->actingAs($other)
            ->get($this->showUrl($this->a, $workOrder))
            ->assertForbidden()
            ->assertSee('Esta orden ya no está asignada a vos')
            ->assertDontSee('DETALLE PRIVADO');
    }

    public function test_cancelled_order_shows_it_was_cancelled(): void
    {
        $workOrder = $this->order($this->a, [$this->a['technician']], ['notes' => 'DETALLE PRIVADO']);
        $workOrder->delete();

        $this->actingAs($this->a['technician'])
            ->get($this->showUrl($this->a, $workOrder))
            ->assertStatus(410)
            ->assertSee('Esta orden fue cancelada')
            ->assertDontSee('DETALLE PRIVADO');
    }

    public function test_orders_of_another_company_are_never_shown(): void
    {
        $theirs = $this->order($this->b, [$this->b['technician']], ['notes' => 'SECRETO EMPRESA B']);

        // Por la URL de su empresa: no existe para él.
        $this->actingAs($this->a['technician'])
            ->get($this->showUrl($this->a, $theirs))
            ->assertNotFound();

        // Por la URL de la otra empresa: rechazo (404 por el scope de empresa).
        $response = $this->actingAs($this->a['technician'])->get($this->showUrl($this->b, $theirs));

        $this->assertContains($response->status(), [302, 404]);
        $response->assertDontSee('SECRETO EMPRESA B');
    }

    public function test_guest_goes_to_login(): void
    {
        $workOrder = $this->order($this->a, [$this->a['technician']]);

        $this->get($this->showUrl($this->a, $workOrder))->assertRedirect('/login');
    }

    public function test_manifest_and_service_worker_are_published(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }

        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
        // No intercepta requests: la app funciona igual que sin service worker.
        $this->assertStringNotContainsString("addEventListener('fetch'", $sw);
        // Solo navega dentro del mismo sitio.
        $this->assertStringContainsString('url.origin === self.location.origin', $sw);
    }

    public function test_push_config_is_only_given_to_technicians_and_never_includes_the_private_key(): void
    {
        config([
            'webpush.vapid.public_key' => 'PUBLIC-VAPID-KEY',
            'webpush.vapid.private_key' => 'PRIVATE-VAPID-KEY',
        ]);

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/dashboard")
            ->assertOk()
            ->assertSee('name="ascento-push"', false)
            ->assertSee('PUBLIC-VAPID-KEY')
            ->assertSee('Activar notificaciones')
            ->assertSee('rel="manifest"', false)
            ->assertDontSee('PRIVATE-VAPID-KEY');

        $this->actingAs($this->a['admin'])
            ->get("/{$this->a['company']->slug}/dashboard")
            ->assertDontSee('name="ascento-push"', false)
            ->assertDontSee('PRIVATE-VAPID-KEY');
    }

    public function test_profile_shows_the_notifications_card_with_ios_instructions(): void
    {
        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/profile")
            ->assertOk()
            ->assertSee('Avisos de órdenes nuevas')
            ->assertSee('Agregar a inicio')
            ->assertSee('bloqueadas');
    }
}
