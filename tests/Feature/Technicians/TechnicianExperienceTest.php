<?php

namespace Tests\Feature\Technicians;

use App\Models\Building;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Reports\ReportVideoService;
use App\Services\Technicians\TechnicianWorkload;
use App\Support\Diagnostics\ServerLimits;
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
            ->assertSee('Mi planilla')
            ->assertSee('data-unread-count', false);
    }

    public function test_report_and_delivery_note_forms_keep_a_local_draft_per_technician(): void
    {
        $tech = $this->a['technician'];
        $slug = $this->a['company']->slug;

        $this->actingAs($tech)->get(route('reports.create', ['company' => $slug]))->assertOk()
            ->assertSee('data-draft="reporte.'.$tech->id.'.'.$this->a['company']->id.'"', false)
            ->assertSee('data-draft-xhr', false)->assertSee('data-draft-notice', false)
            ->assertDontSee('data-draft-restore="off"', false);

        $url = route('delivery-notes.building', ['company' => $slug, 'building' => $this->a['building']->id, 'month' => now()->month, 'year' => now()->year, 'assignment_type' => 'maintenance']);
        $this->get($url)->assertOk()
            // Por período: un borrador viejo de otro mes no se recupera en este remito.
            ->assertSee('data-draft="remito.'.$tech->id.'.'.$this->a['building']->id.'.maintenance.'.now()->year.'-'.now()->month.'"', false)
            ->assertDontSee('data-draft-restore="off"', false);

        // Volvió con un error de validación: manda lo que trae la página, no el borrador.
        $this->from($url)->post(route('delivery-notes.store', ['company' => $slug]), ['building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance'])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('data-draft-restore="off"', false);
    }

    public function test_logout_clears_only_the_current_users_drafts_on_desktop_and_mobile(): void
    {
        $tech = $this->a['technician'];

        $html = $this->actingAs($tech)->get(route('work-orders.index', ['company' => $this->a['company']->slug]))->assertOk()->getContent();

        // drafts.js borra solo las claves "tipo.{este usuario}.…" al cerrar sesión.
        $this->assertStringContainsString('<meta name="ascento-user" content="'.$tech->id.'">', $html);
        // El "Cerrar sesión" de escritorio dispara el evento submit (form.submit() no lo hace).
        $this->assertStringContainsString('f.requestSubmit ? f.requestSubmit() : f.submit();', $html);
        $this->assertStringNotContainsString("this.closest('form').submit()", $html);
    }

    public function test_the_video_limit_shown_on_the_web_never_exceeds_what_php_accepts(): void
    {
        config(['media.video_max_mb' => 50]);
        $this->assertSame(50, ReportVideoService::effectiveMaxMb()); // consola: el configurado

        // Como en la web (PHP-FPM): el menor entre lo configurado y lo que acepta PHP.
        $running = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $running->setValue($this->app, false);
        try {
            $upload = ServerLimits::toMb((string) ini_get('upload_max_filesize')) ?? INF;
            $post = ServerLimits::toMb((string) ini_get('post_max_size')) ?? INF;
            $expected = max(1, (int) floor(min(50, $upload, $post - 5)));
            $this->assertSame($expected, ReportVideoService::effectiveMaxMb());
            $this->assertSame($expected * 1024, ReportVideoService::maxKb());
        } finally {
            $running->setValue($this->app, true);
        }
    }
}
