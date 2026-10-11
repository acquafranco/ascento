<?php

namespace Tests\Feature\Technicians;

use App\Models\Building;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Technicians\TechnicianWorkload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * App del técnico: contadores (mismas reglas que los listados, sin mezclar
 * empresas ni técnicos), inicio, búsqueda de edificios y selector del reporte.
 */
class TechnicianExperienceTest extends TestCase
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
        auth()->logout();
    }

    private function order(array $tenant, User $tech, string $status, array $extra = []): WorkOrder
    {
        $order = WorkOrder::factory()->create(['company_id' => $tenant['company']->id, 'building_id' => $tenant['building']->id, 'status' => $status, ...$extra]);
        $order->users()->attach($tech->id);

        return $order;
    }

    public function test_counters_follow_assignment_status_and_company(): void
    {
        $tech = $this->a['technician'];
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->order($this->a, $tech, 'pending');
        $this->order($this->a, $tech, 'pending');
        $this->order($this->a, $tech, 'in_progress');
        $done = $this->order($this->a, $tech, 'completed', ['finished_at' => now()]);
        $done->participants()->attach($tech->id, ['role' => 'creator']);
        $this->order($this->a, $tech, 'completed', ['finished_at' => now()->subDays(2)]); // otro día
        $this->order($this->a, $tech, 'failed', ['finished_at' => now()]);                // no realizado: no es completado
        $cancelled = $this->order($this->a, $tech, 'pending');
        $cancelled->delete();                                                          // cancelada: no cuenta
        $this->order($this->a, $other, 'pending');                                      // de otro técnico
        $this->order($this->b, $this->b['technician'], 'pending');                      // de otra empresa

        $this->assertSame(['pending' => 2, 'in_progress' => 1, 'completed_today' => 1], app(TechnicianWorkload::class)->counts($tech));

        $this->actingAs($tech)->getJson(route('technician.counters', ['company' => $this->a['company']->slug]))
            ->assertOk()->assertExactJson(['pending' => 2, 'in_progress' => 1, 'completed_today' => 1])
            ->assertHeader('Cache-Control', 'no-store, private');

        // Lo mismo que muestra el listado de pendientes.
        $this->get(route('work-orders.index', ['company' => $this->a['company']->slug, 'status' => 'pending']))
            ->assertOk()->assertViewHas('workOrders', fn ($p) => $p->total() === 2);
    }

    public function test_counters_endpoint_is_only_for_technicians_of_that_company(): void
    {
        $this->actingAs($this->a['admin'])->getJson(route('technician.counters', ['company' => $this->a['company']->slug]))->assertNotFound();
        $this->actingAs($this->b['technician'])->getJson(route('technician.counters', ['company' => $this->a['company']->slug]))->assertForbidden();
        auth()->logout();
        $this->getJson(route('technician.counters', ['company' => $this->a['company']->slug]))->assertUnauthorized();
    }

    public function test_dashboard_shows_next_work_and_the_month_visits(): void
    {
        $tech = $this->a['technician'];
        $this->a['building']->update(['name' => 'Torre del Plata']);
        $this->order($this->a, $tech, 'pending', ['priority' => 'low', 'notes' => 'Cambiar lámpara']);
        $this->order($this->a, $tech, 'pending', ['priority' => 'urgent']);

        $next = app(TechnicianWorkload::class)->nextWorkOrders($tech);
        $this->assertSame(['urgent', 'low'], $next->pluck('priority')->all());

        $this->actingAs($tech)->get(route('dashboard', ['company' => $this->a['company']->slug]))
            ->assertOk()
            ->assertSee('Próximos trabajos')
            ->assertSee('Torre del Plata')
            ->assertSee('Hacer remito')          // mantenimiento del mes pendiente
            ->assertSee('data-counts-url', false)
            ->assertDontSee($this->b['building']->name);
    }

    public function test_all_buildings_search_runs_on_the_server_and_stays_in_the_company(): void
    {
        Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Edificio Mirador', 'address' => 'Cabildo 1234']);
        Building::factory()->create(['company_id' => $this->b['company']->id, 'name' => 'Mirador Ajeno', 'address' => 'Cabildo 1234']);

        $this->actingAs($this->a['technician'])->get(route('buildings.all', ['company' => $this->a['company']->slug, 'search' => 'cabildo']))
            ->assertOk()->assertSee('Edificio Mirador')->assertDontSee('Mirador Ajeno')->assertDontSee($this->a['building']->name);
    }

    public function test_report_form_lists_identifiable_buildings_and_the_server_rejects_foreign_or_inactive_ones(): void
    {
        $tech = $this->a['technician'];
        $this->a['building']->update(['name' => 'Torre del Plata', 'address' => 'Juncal 2450', 'locality' => 'Recoleta', 'elevator_count' => 2, 'freight_elevator_count' => 1]);
        $inactive = Building::factory()->create(['company_id' => $this->a['company']->id, 'is_active' => false, 'elevator_count' => 1]);

        $response = $this->actingAs($tech)->get(route('reports.create', ['company' => $this->a['company']->slug]))->assertOk();
        $buildings = collect($response->viewData('buildings'));
        $mine = $buildings->firstWhere('id', $this->a['building']->id);
        $this->assertSame(['Torre del Plata', 'Juncal 2450', 'Recoleta', true, ['Ascensor 1', 'Ascensor 2', 'Montacargas 1']],
            [$mine['name'], $mine['address'], $mine['place'], $mine['mine'], $mine['units']]);
        $this->assertTrue($buildings->first()['mine']); // los asignados primero
        $this->assertNull($buildings->firstWhere('id', $inactive->id));
        $this->assertNull($buildings->firstWhere('id', $this->b['building']->id));

        $data = ['elevator_number' => 'Ascensor 1', 'description' => 'Falla en puertas', 'priority' => 'media'];
        $this->postJson(route('reports.store', ['company' => $this->a['company']->slug]), [...$data, 'building_id' => $this->b['building']->id])->assertStatus(422);
        $this->postJson(route('reports.store', ['company' => $this->a['company']->slug]), [...$data, 'building_id' => $inactive->id])->assertStatus(422);
        $this->postJson(route('reports.store', ['company' => $this->a['company']->slug]), [...$data, 'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 9'])
            ->assertStatus(422)->assertJsonPath('message', 'Elegí un equipo de este edificio.');
        $this->postJson(route('reports.store', ['company' => $this->a['company']->slug]), [...$data, 'building_id' => $this->a['building']->id])->assertOk();
    }

    public function test_navigation_is_present_on_desktop_and_mobile_with_the_unread_counter(): void
    {
        $this->actingAs($this->a['technician'])->get(route('work-orders.index', ['company' => $this->a['company']->slug]))
            ->assertOk()
            ->assertSee('aria-label="Principal"', false)
            ->assertSee('aria-label="Secciones"', false)
            ->assertSee('Planilla del mes')
            ->assertSee('data-unread-count', false);
    }
}
