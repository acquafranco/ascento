<?php

namespace Tests\Feature\Visits;

use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Elevator;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Technicians\TechnicianWorkload;
use App\Services\Visits\VisitPlanService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cada ascensor y montacargas se registra con su tipo y su tracción (la
 * tracción es de cada equipo, no del edificio). Las cantidades del edificio
 * salen de los equipos activos. Un edificio sin equipos no recibe visitas.
 * El progreso cuenta edificios asignados y visitas previstas, no ascensores.
 */
class BuildingEquipmentTest extends TestCase
{
    use InteractsWithTenants {
        makeTenant as makeTenantOnTrial;
    }
    use RefreshDatabase;

    /** Las funciones fotográficas son del plan Empresa: las empresas de estas pruebas lo tienen. */
    protected function makeTenant(): array
    {
        $tenant = $this->makeTenantOnTrial();
        $this->giveCompanyPlan($tenant['company'], 'empresa');

        return $tenant;
    }

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);
    }

    private function equipmentOf(Building $building, bool $active = true): array
    {
        return Elevator::withoutGlobalScopes()->where('building_id', $building->id)->where('is_active', $active)
            ->orderBy('kind')->orderBy('id')->get()->map(fn ($e) => [$e->label, $e->kind, $e->machine_type])->all();
    }

    public function test_creating_a_building_registers_each_unit_with_its_own_traction(): void
    {
        Livewire::test(CreateBuilding::class)
            ->fillForm([
                'client_id' => $this->a['building']->client_id, 'name' => 'Torre Norte', 'address' => '100',
                'equipment' => [
                    ['kind' => 'elevator', 'machine_type' => 'traction'],
                    ['kind' => 'elevator', 'machine_type' => 'hydraulic'],
                    ['kind' => 'freight', 'machine_type' => 'traction_mrl'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $building = Building::where('name', 'Torre Norte')->sole();
        $this->assertSame([
            ['Ascensor 1', 'elevator', 'traction'],
            ['Ascensor 2', 'elevator', 'hydraulic'],
            ['Montacargas 1', 'freight', 'traction_mrl'],
        ], $this->equipmentOf($building));
        // Las cantidades salen de los equipos; nada de tracción a nivel edificio.
        $this->assertSame([2, 1], [$building->elevator_count, $building->freight_elevator_count]);
        $this->assertSame([0, 0], [(int) $building->traction_elevator_count, (int) $building->hydraulic_elevator_count]);
    }

    public function test_a_building_cannot_be_created_without_equipment_or_with_new_units_without_traction(): void
    {
        $base = ['client_id' => $this->a['building']->client_id, 'name' => 'Vacío', 'address' => '1'];

        Livewire::test(CreateBuilding::class)->fillForm($base)->call('create')->assertHasFormErrors(['equipment']);
        Livewire::test(CreateBuilding::class)->fillForm([...$base, 'equipment' => [['kind' => 'elevator', 'machine_type' => null]]])
            ->call('create')->assertHasFormErrors();
        Livewire::test(CreateBuilding::class)->fillForm([...$base, 'equipment' => [['kind' => 'elevator', 'machine_type' => 'magnetic']]])
            ->call('create')->assertHasFormErrors();

        $this->assertSame(0, Building::where('name', 'Vacío')->count());
    }

    public function test_the_quantity_adds_units_but_never_removes_registered_ones_silently(): void
    {
        $building = $this->a['building']; // 2 ascensores registrados (factory)
        $component = Livewire::test(EditBuilding::class, ['record' => $building->getRouteKey()]);
        $this->assertSame(2, $component->get('data.equipment_elevators'));

        // Subir a 3 agrega un equipo nuevo para completar.
        $component->set('data.equipment_elevators', 3);
        $this->assertCount(3, collect($component->get('data.equipment'))->where('kind', 'elevator'));

        // Bajar a 1: se va el nuevo, pero los registrados no (hay que quitarlos uno por uno, con confirmación).
        $component->set('data.equipment_elevators', 1)->assertNotified('Para dar de baja un equipo registrado, quitalo de la lista');
        $this->assertCount(2, collect($component->get('data.equipment'))->where('kind', 'elevator'));
        $this->assertSame(2, Elevator::withoutGlobalScopes()->where('building_id', $building->id)->where('is_active', true)->count());
    }

    public function test_editing_adds_and_retires_units_without_duplicates_or_losing_history(): void
    {
        $building = $this->a['building'];
        [$first, $second] = Elevator::withoutGlobalScopes()->where('building_id', $building->id)->orderBy('id')->get()->all();
        $component = Livewire::test(EditBuilding::class, ['record' => $building->getRouteKey()]);
        $items = collect($component->get('data.equipment'));

        // Existentes sin tracción: se pueden dejar así. Al primero se le carga; el segundo se quita; se agrega un montacargas.
        $keep = $items->first(fn ($i) => (int) $i['id'] === $first->id);
        $component->set('data.equipment', [
            'a' => [...$keep, 'machine_type' => 'hydraulic'],
            'b' => ['id' => null, 'label' => null, 'kind' => 'freight', 'machine_type' => 'traction'],
        ])->call('save')->assertHasNoFormErrors();

        $this->assertSame([['Ascensor 1', 'elevator', 'hydraulic'], ['Montacargas 1', 'freight', 'traction']], $this->equipmentOf($building));
        $this->assertSame([['Ascensor 2', 'elevator', null]], $this->equipmentOf($building, false)); // inactivo, con su historial
        $this->assertSame([1, 1], [$building->fresh()->elevator_count, $building->fresh()->freight_elevator_count]);

        // Guardar otra vez sin cambios no duplica nada; uno nuevo toma el número siguiente (no reusa el 2).
        $again = Livewire::test(EditBuilding::class, ['record' => $building->getRouteKey()]);
        $again->set('data.equipment', [...$again->get('data.equipment'), 'c' => ['id' => null, 'label' => null, 'kind' => 'elevator', 'machine_type' => 'traction']])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['Ascensor 1', 'Ascensor 3', 'Montacargas 1'], collect($this->equipmentOf($building))->pluck(0)->sort()->values()->all());
        $this->assertSame(4, Elevator::withoutGlobalScopes()->where('building_id', $building->id)->count());
    }

    public function test_a_tampered_unit_id_never_touches_another_building(): void
    {
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);
        $foreign = Elevator::withoutGlobalScopes()->where('building_id', $b['building']->id)->first();

        $component = Livewire::test(EditBuilding::class, ['record' => $this->a['building']->getRouteKey()]);
        $component->set('data.equipment', [...$component->get('data.equipment'), 'x' => ['id' => $foreign->id, 'label' => $foreign->label, 'kind' => 'elevator', 'machine_type' => 'hydraulic']])
            ->call('save')->assertHasNoFormErrors();

        $this->assertNull($foreign->fresh()->machine_type);
        $this->assertSame((int) $b['building']->id, (int) $foreign->fresh()->building_id);
        $this->assertSame(3, Elevator::withoutGlobalScopes()->where('building_id', $this->a['building']->id)->count()); // tratado como nuevo
    }

    public function test_existing_buildings_without_equipment_can_still_be_edited(): void
    {
        $legacy = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 0, 'freight_elevator_count' => 0]);

        Livewire::test(EditBuilding::class, ['record' => $legacy->getRouteKey()])
            ->fillForm(['name' => 'Edificio viejo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edificio viejo', $legacy->fresh()->name);
        // Pero uno con equipos no puede quedarse sin ninguno.
        Livewire::test(EditBuilding::class, ['record' => $this->a['building']->getRouteKey()])
            ->set('data.equipment', [])->call('save')->assertHasFormErrors(['equipment']);
        $this->assertSame(2, $this->a['building']->fresh()->elevator_count);
    }

    public function test_a_building_without_equipment_is_never_a_visit_candidate(): void
    {
        $empty = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 0, 'freight_elevator_count' => 0]);
        $empty->users()->attach($this->a['technician']->id, ['type' => 'maintenance']);
        $this->a['company']->forceFill(['photos_maintenance' => true])->save();

        // Agenda: "Sin equipos", sin visitas previstas ni pendientes.
        $row = app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance')->firstWhere('building.id', $empty->id);
        $this->assertSame(['no_equipment', 0, 0], [$row['status'], $row['expected'], $row['pending']]);
        $progress = MaintenanceAgenda::progress(app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance'))['maintenance'];
        $this->assertSame(1, $progress['expected']); // solo el edificio con equipos

        // Técnico: aviso, sin botón de remito ni de visita; el servidor rechaza ambos.
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/buildings")->assertOk()->assertSee('data-no-equipment', false);
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $empty->id, 'description' => 'x', 'month' => 10, 'year' => 2026, 'elevator_quantity' => 0, 'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance', 'signature_name' => 'T', 'signature' => $this->validSignature(),
        ])->assertSessionHasErrors('general');
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/visits/start", ['building_id' => $empty->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026])
            ->assertSessionHasErrors('general');
        $this->assertSame(0, BuildingVisit::withoutGlobalScope('completed')->where('building_id', $empty->id)->count());

        // Recordatorios: no se reclama nada de ese edificio.
        auth()->logout();
        $this->travelTo(Carbon::parse('2026-10-21 08:00'));
        $this->artisan('notifications:visits')->assertSuccessful();
        $this->assertStringContainsString('Te queda 1 visita sin remito', json_encode($this->a['technician']->fresh()->notifications()->latest()->first()?->data, JSON_UNESCAPED_UNICODE));
    }

    public function test_assigned_counts_buildings_and_expected_counts_visits_by_real_frequency(): void
    {
        // El técnico tiene 4 edificios: mensual (2 ascensores), quincenal, trimestral ya hecho en el período y uno sin equipos.
        $tech = $this->a['technician'];
        $biweekly = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 5]);
        $quarterly = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 1]);
        $empty = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 0]);
        foreach ([$biweekly, $quarterly, $empty] as $b) {
            $b->users()->attach($tech->id, ['type' => 'maintenance']);
        }
        $plans = app(VisitPlanService::class);
        $plans->set($biweekly, 'maintenance', 2, 1, CarbonImmutable::parse('2026-10-01'));
        $this->travelTo(Carbon::parse('2026-09-02 09:00'));
        $plans->set($quarterly, 'maintenance', 1, 3, CarbonImmutable::parse('2026-09-01'));
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        BuildingVisit::factory()->create(['building_id' => $quarterly->id, 'user_id' => $tech->id, 'month' => 9, 'year' => 2026]);
        // Una visita del quincenal hecha y otra con remito "no realizado".
        BuildingVisit::factory()->create(['building_id' => $biweekly->id, 'user_id' => $tech->id, 'month' => 10, 'year' => 2026, 'slot' => 1]);
        $notDone = BuildingVisit::factory()->create(['building_id' => $biweekly->id, 'user_id' => $tech->id, 'month' => 10, 'year' => 2026, 'slot' => 2]);
        DB::table('delivery_notes')->insert(['company_id' => $this->a['company']->id, 'building_id' => $biweekly->id, 'building_visit_id' => $notDone->id, 'user_id' => $tech->id, 'number' => 99, 'assignment_type' => 'maintenance', 'description' => 'x', 'month' => 10, 'year' => 2026, 'elevator_quantity' => 5, 'freight_elevator_quantity' => 0, 'performed' => false, 'signature_name' => 'T', 'signature' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        // 4 edificios asignados; previstas: mensual 1 + quincenal 2 + trimestral 0 + sin equipos 0 = 3.
        $page = $this->actingAs($tech)->get("/{$this->a['company']->slug}/buildings")->assertOk()->assertSee('data-progress-help', false);
        $tech_ = $page->viewData('progress')['maintenance'];
        $this->assertSame(['assigned' => 4, 'expected' => 3, 'done' => 1, 'not_done' => 1, 'pending' => 2, 'percent' => 33], array_intersect_key($tech_, array_flip(['assigned', 'expected', 'done', 'not_done', 'pending', 'percent'])));
        $page->assertSee('1 de 3');

        // Inicio del técnico y Agenda del admin, filtrada por ese técnico: lo mismo.
        $this->assertSame($tech_, app(TechnicianWorkload::class)->monthVisits($tech)['progress']['maintenance']);
        $this->actingInPanel($this->a['admin']);
        $agenda = MaintenanceAgenda::progress(app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance', $tech->id))['maintenance'];
        $this->assertSame(array_intersect_key($tech_, array_flip(['assigned', 'expected', 'done', 'not_done', 'pending', 'percent'])), array_intersect_key($agenda, array_flip(['assigned', 'expected', 'done', 'not_done', 'pending', 'percent'])));
        // Los ascensores no multiplican nada (el quincenal tiene 5).
        $this->assertSame(3, $agenda['expected']);
    }
}
