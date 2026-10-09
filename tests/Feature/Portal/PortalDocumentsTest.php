<?php

namespace Tests\Feature\Portal;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Services\Notifications\ClientShareNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Centro de documentos y servicio del portal: filtros y paginación en el
 * servidor, sin fugas de edificios no autorizados, privados ni de otra empresa.
 */
class PortalDocumentsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Building $mine;

    private Building $notMine;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        auth()->logout();
        $client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $this->mine = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id, 'name' => 'Edificio Propio']);
        $this->notMine = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id, 'name' => 'Edificio Sin Permiso']);
        $this->client = User::factory()->create();
        $this->client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $this->client->portalBuildings()->sync([$this->mine->id]);
    }

    private function note(Building $building, bool $shared = true, array $attrs = []): DeliveryNote
    {
        $note = DeliveryNote::factory()->create(['building_id' => $building->id, 'company_id' => $building->company_id] + $attrs);
        $shared && $note->shareWithClient(true);

        return $note;
    }

    public function test_documents_are_paginated_and_filtered_on_the_server(): void
    {
        foreach (range(1, 32) as $i) {
            $this->note($this->mine, attrs: ['description' => "Mantenimiento número {$i}", 'created_at' => now()->subDays(40 - $i)]);
        }
        $report = Report::factory()->create(['building_id' => $this->mine->id, 'description' => 'Ruido en máquina', 'status' => 'resuelto']);
        $report->shareWithClient(true);

        $page1 = $this->actingAs($this->client)->get(route('portal.documents'))->assertOk();
        $this->assertSame(15, $page1->viewData('documents')->count());
        $this->assertSame(33, $page1->viewData('documents')->total());
        $this->assertSame(['delivery_note' => 32, 'report' => 1, 'quote' => 0, 'document' => 0], $page1->viewData('counts'));
        $this->get(route('portal.documents', ['page' => 3]))->assertOk()->assertSee('Mantenimiento número 1')->assertDontSee('Mantenimiento número 32');

        // Tipo + estado, búsqueda, fechas, orden.
        $this->get(route('portal.documents', ['type' => 'report', 'status' => 'resuelto']))->assertOk()->assertSee('Ruido en máquina')->assertDontSee('Mantenimiento número');
        $this->get(route('portal.documents', ['type' => 'report', 'status' => 'pendiente']))->assertOk()->assertSee('No hay documentos con estos filtros');
        $this->assertSame(1, $this->get(route('portal.documents', ['q' => 'número 17']))->viewData('documents')->total());
        $this->assertSame(1, $this->get(route('portal.documents', ['from' => now()->subDays(8)->toDateString(), 'to' => now()->subDays(8)->toDateString()]))->viewData('documents')->total());
        $oldest = $this->get(route('portal.documents', ['sort' => 'asc', 'type' => 'delivery_note']))->viewData('documents')->first();
        $this->assertStringContainsString('número 1', $oldest->summary);

        // Filtros inválidos: rechazados, no "todo".
        $this->get(route('portal.documents', ['type' => 'users']))->assertSessionHasErrors('type');
    }

    public function test_unauthorized_buildings_private_records_and_other_companies_never_appear(): void
    {
        $this->note($this->mine, attrs: ['description' => 'VISIBLE']);
        $this->note($this->mine, shared: false, attrs: ['description' => 'PRIVADO']);
        $this->note($this->notMine, attrs: ['description' => 'SIN PERMISO']);
        $this->note($this->b['building'], attrs: ['description' => 'OTRA EMPRESA']);
        $quote = Quote::factory()->create(['building_id' => $this->notMine->id, 'title' => 'PRESUPUESTO AJENO']);
        $quote->shareWithClient(true);

        $this->actingAs($this->client);
        foreach ([[], ['building' => $this->notMine->id], ['building' => $this->b['building']->id], ['type' => 'quote']] as $filters) {
            $this->get(route('portal.documents', $filters))->assertOk()
                ->assertDontSee('PRIVADO')->assertDontSee('SIN PERMISO')->assertDontSee('OTRA EMPRESA')->assertDontSee('PRESUPUESTO AJENO');
        }
        // Un edificio no autorizado en el filtro no amplía nada: se ignora.
        $this->assertSame(1, $this->get(route('portal.documents', ['building' => $this->notMine->id]))->viewData('documents')->total());
        $this->get(route('portal.quote', $quote))->assertNotFound();
    }

    public function test_maintenances_and_inspections_are_listed_separately_with_shared_remitos_only(): void
    {
        $visit = fn (Building $b, string $type, int $month) => BuildingVisit::create(['company_id' => $b->company_id, 'building_id' => $b->id, 'user_id' => $this->a['technician']->id,
            'visit_type' => 'fixed', 'assignment_type' => $type, 'month' => $month, 'year' => 2026, 'status' => 'done', 'visited_at' => now(), 'source' => 'building']);

        $shared = $visit($this->mine, 'maintenance', 8);
        $note = $this->note($this->mine, attrs: ['building_visit_id' => $shared->id, 'assignment_type' => 'maintenance', 'performed' => true]);
        $private = $visit($this->mine, 'maintenance', 9);
        $this->note($this->mine, shared: false, attrs: ['building_visit_id' => $private->id, 'assignment_type' => 'maintenance', 'performed' => false]);
        $visit($this->mine, 'inspection', 7);
        $visit($this->notMine, 'maintenance', 8);

        $this->actingAs($this->client)->get(route('portal.visits', 'maintenance'))->assertOk()
            ->assertSee('Agosto 2026')->assertSee('Septiembre 2026')->assertDontSee('Julio 2026')
            ->assertSee('Ver remito '.$note->number)->assertSee('Remito no compartido')->assertSee('No realizado')
            ->assertDontSee('Edificio Sin Permiso');
        $this->assertSame(2, $this->get(route('portal.visits', 'maintenance'))->viewData('visits')->total());
        $this->assertSame(1, $this->get(route('portal.visits', ['type' => 'maintenance', 'result' => 'not_done']))->viewData('visits')->total());
        $this->get(route('portal.visits', 'inspection'))->assertOk()->assertSee('Julio 2026')->assertDontSee('Agosto 2026');
        $this->get('/portal/servicio/work_order')->assertNotFound();
    }

    public function test_new_shared_items_are_flagged_until_opened(): void
    {
        $note = $this->note($this->mine, attrs: ['description' => 'Recién compartido']);
        app(ClientShareNotifier::class)->shared([$note]);

        $this->actingAs($this->client)->get(route('portal.documents'))->assertSee('Nuevo');
        $this->get(route('portal.delivery-note', $note->number))->assertOk();
        $this->get(route('portal.documents'))->assertDontSee('>Nuevo<', false);
        $this->assertSame(0, $this->client->unreadNotifications()->count());
    }
}
