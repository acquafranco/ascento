<?php

namespace Tests\Feature\Billing;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Pages\ViewMaintenanceService;
use App\Filament\Resources\MaintenanceServices\RelationManagers\VisitsRelationManager;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\MaintenanceService;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Servicio (contrato) → visitas realizadas. Mantenimiento e inspección siguen
 * siendo entidades separadas; el servicio solo sabe cuáles le corresponden.
 */
class ServiceVisitsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->a['building']->users()->attach($this->a['technician']->id, ['type' => 'inspection']);
    }

    private function service(array $attributes = [], ?array $tenant = null): MaintenanceService
    {
        $tenant ??= $this->a;
        $this->actingAs($tenant['admin']);

        $service = MaintenanceService::create([
            'client_id' => $tenant['building']->client_id,
            'building_id' => $tenant['building']->id,
            'description' => 'Mantenimiento mensual',
            'amount' => 100000,
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
            'payment_due_day' => 10,
            'status' => MaintenanceService::ACTIVE,
            ...$attributes,
        ]);

        auth()->logout();

        return $service;
    }

    /** Remito mensual firmado por el técnico (el camino real que crea la visita). */
    private function sign(string $type, ?array $tenant = null, int $month = 10): BuildingVisit
    {
        $tenant ??= $this->a;

        $this->actingAs($tenant['technician'])->post("/{$tenant['company']->slug}/delivery-notes/store", [
            'building_id' => $tenant['building']->id,
            'description' => 'Visita mensual',
            'month' => $month,
            'year' => 2026,
            'elevator_quantity' => 2,
            'freight_elevator_quantity' => 0,
            'assignment_type' => $type,
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
        ])->assertSessionHasNoErrors();

        return BuildingVisit::withoutGlobalScopes()->where('assignment_type', $type)->latest('id')->first();
    }

    public function test_maintenance_and_inspection_are_linked_to_the_service_and_kept_separate(): void
    {
        $service = $this->service();

        $maintenance = $this->sign('maintenance');
        $inspection = $this->sign('inspection');

        $this->assertSame($service->id, $maintenance->maintenance_service_id);
        $this->assertSame($service->id, $inspection->maintenance_service_id);
        $this->assertSame($this->a['building']->id, $maintenance->building_id);

        $this->assertSame([$maintenance->id], $service->maintenanceVisits()->pluck('id')->all());
        $this->assertSame([$inspection->id], $service->inspectionVisits()->pluck('id')->all());

        $status = $service->visitStatus('maintenance');
        $this->assertSame($maintenance->id, $status['last']->id);
        $this->assertTrue($status['done_this_month']);
        $this->assertSame('2026-11-01', $status['next']->toDateString());
    }

    public function test_multiple_visits_and_the_pending_month(): void
    {
        $service = $this->service();
        $this->sign('maintenance', month: 8);
        $this->sign('maintenance', month: 9);

        $this->assertSame(2, $service->maintenanceVisits()->count());
        $this->assertSame(0, $service->inspectionVisits()->count());

        $status = $service->visitStatus('maintenance');
        $this->assertFalse($status['done_this_month']);           // octubre todavía no
        $this->assertSame('2026-10-01', $status['next']->toDateString());
        $this->assertNull($service->visitStatus('inspection')['last']);
    }

    public function test_paused_finished_ended_or_not_started_services_get_no_visits(): void
    {
        $paused = $this->service(['status' => MaintenanceService::PAUSED]);
        $finished = $this->service(['status' => MaintenanceService::FINISHED]);
        $ended = $this->service(['end_date' => '2026-09-30']);
        $future = $this->service(['start_date' => '2026-12-01']);

        $visit = $this->sign('maintenance');

        $this->assertNull($visit->maintenance_service_id);
        $this->assertNull($paused->visitStatus('maintenance')['next']);
        $this->assertNull($ended->visitStatus('maintenance')['next']); // terminó: no hay próxima
        foreach ([$paused, $finished, $ended, $future] as $service) {
            $this->assertSame(0, $service->visits()->count());
        }
    }

    public function test_building_specific_service_wins_over_client_wide_and_other_buildings_are_not_linked(): void
    {
        $clientWide = $this->service(['building_id' => null, 'start_date' => '2026-05-01']);
        $specific = $this->service(['start_date' => '2026-01-01']);

        $this->assertSame($specific->id, $this->sign('maintenance')->maintenance_service_id);

        // Otro edificio del mismo cliente: lo cubre el contrato de todo el cliente.
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id]);
        $visit = BuildingVisit::factory()->create(['building_id' => $other->id, 'user_id' => $this->a['technician']->id]);
        $this->assertSame($clientWide->id, $visit->maintenance_service_id);

        // Edificio de otro cliente: ninguno.
        $foreignClient = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->assertNull(BuildingVisit::factory()->create(['building_id' => $foreignClient->id, 'user_id' => $this->a['technician']->id])->maintenance_service_id);

        // Las visitas de órdenes de trabajo (correctivos) no son del contrato.
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $woVisit = BuildingVisit::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'visit_type' => 'work_order', 'assignment_type' => 'work_order', 'work_order_id' => $order->id]);
        $this->assertNull($woVisit->maintenance_service_id);
    }

    public function test_a_service_never_gets_visits_of_another_company_or_building(): void
    {
        $service = $this->service();
        $b = $this->makeTenant();
        $visitB = BuildingVisit::factory()->create(['building_id' => $b['building']->id, 'user_id' => $b['technician']->id]);
        $this->assertNull($visitB->maintenance_service_id); // no lo toma el servicio de A

        // Forzarlo a mano (o por cualquier otro camino) falla.
        $this->expectException(\DomainException::class);
        $visitB->forceFill(['maintenance_service_id' => $service->id])->save();
    }

    public function test_a_visit_cannot_be_moved_to_a_service_of_another_building(): void
    {
        $service = $this->service();
        $other = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $visit = BuildingVisit::factory()->create(['building_id' => $other->id, 'user_id' => $this->a['technician']->id]);

        $this->expectException(\DomainException::class);
        $visit->forceFill(['maintenance_service_id' => $service->id])->save();
    }

    public function test_existing_visits_are_linked_by_the_migration(): void
    {
        $visit = BuildingVisit::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'visited_at' => '2026-03-10']);
        $service = $this->service(['start_date' => '2026-01-01']);
        $this->assertNull($visit->fresh()->maintenance_service_id); // existía antes del servicio

        $migration = require database_path('migrations/2026_10_16_100300_link_visits_to_maintenance_services.php');
        $migration->down();
        $migration->up();

        $this->assertSame($service->id, $visit->fresh()->maintenance_service_id);
        $this->assertSame(1, BuildingVisit::withoutGlobalScopes()->count()); // no se creó ni borró nada
    }

    public function test_units_only_accept_equipment_of_the_building(): void
    {
        $service = $this->service(['units' => ['Ascensor 1', 'Ascensor 99', '<script>']]);

        $this->assertSame(['Ascensor 1'], $service->fresh()->units);
    }

    public function test_the_service_page_shows_visits_and_other_companies_cannot_open_it(): void
    {
        $service = $this->service();
        $this->sign('maintenance');
        $this->sign('inspection');

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ViewMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->assertSee('1 realizados')
            ->assertSee('1 realizadas')
            ->assertSee('Próximo: Noviembre 2026');
        Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $service, 'pageClass' => ViewMaintenanceService::class])
            ->assertCountTableRecords(2)
            ->filterTable('assignment_type', 'inspection')
            ->assertCountTableRecords(1);

        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin'])->get(MaintenanceServiceResource::getUrl('view', ['record' => $service]))->assertNotFound();
    }
}
