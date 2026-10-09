<?php

namespace Tests\Feature\Flows;

use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\User;
use App\Services\Insights\AttentionCenter;
use App\Services\Insights\ElevatorHistory;
use App\Services\Insights\MaintenanceAgenda;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Trabajo de los técnicos de punta a punta: asignación → lista del técnico →
 * remito → visita → agenda / centro de atención / historial. El técnico
 * trabaja a su ritmo: nada se marca hecho (ni vencido) antes de tiempo.
 */
class TechnicianWorkFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Building $building;

    private User $t1;

    private User $t2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->a['building']->users()->detach();
        $this->t1 = $this->a['technician'];
        $this->t2 = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Técnica Dos']);

        // El admin carga el edificio con 2 ascensores y su contrato.
        $this->actingInPanel($this->a['admin']);
        $this->building = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id, 'name' => 'Torre Norte', 'elevator_count' => 2, 'freight_elevator_count' => 0]);
        MaintenanceService::create(['client_id' => $this->building->client_id, 'building_id' => $this->building->id, 'description' => 'Abono mensual', 'amount' => 100000, 'frequency' => 'quarterly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);
    }

    private function assign(User $technician, string $type): void
    {
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->building, data: ['user_ids' => [$technician->id], 'type' => $type])
            ->assertHasNoTableActionErrors();
    }

    private function unassign(User $technician, string $type): void
    {
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListBuildings::class)->callTableAction('removeTechnician', $this->building, data: ['assignment' => $technician->id.'-'.$type]);
    }

    private function sign(User $technician, string $type = 'maintenance', array $overrides = [])
    {
        return $this->actingAs($technician)->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $this->building->id,
            'description' => 'Revisión completa de máquina, puertas y cabina.',
            'month' => 10, 'year' => 2026,
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'assignment_type' => $type,
            'performed' => '1',
            'signature_name' => $technician->name,
            'signature' => $this->validSignature(),
            ...$overrides,
        ]);
    }

    private function agendaStatus(int $month = 10, string $type = 'maintenance'): ?string
    {
        $this->actingInPanel($this->a['admin']);

        return app(MaintenanceAgenda::class)->rows($month, 2026, $type)->firstWhere(fn ($r) => $r['building']->id === $this->building->id)['status'] ?? null;
    }

    public function test_full_maintenance_cycle_from_assignment_to_history(): void
    {
        // Antes de asignar: el contrato existe pero nadie lo tiene.
        $this->assertSame('unassigned', $this->agendaStatus());

        $this->assign($this->t1, 'maintenance');
        $this->assertSame('pending', $this->agendaStatus());          // asignado ≠ hecho
        $this->assertSame('overdue', $this->agendaStatus(9));          // el mes pasado sí venció

        // El técnico lo ve en su lista, desde el celular, y consulta la ficha.
        $slug = $this->a['company']->slug;
        $this->actingAs($this->t1)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')
            ->get("/{$slug}/buildings")->assertOk()->assertSee('Torre Norte')->assertSee('Marcar mantenimiento')
            ->assertSee('name="viewport"', false);
        $this->get("/{$slug}/delivery-notes/create/building/{$this->building->id}?assignment_type=maintenance&month=10&year=2026")->assertOk();

        // Mirar pantallas no completa nada.
        $this->assertSame(0, BuildingVisit::count());
        $this->assertSame('pending', $this->agendaStatus());

        // Firma el remito.
        $this->sign($this->t1)->assertSessionHasNoErrors();

        $visit = BuildingVisit::sole();
        $this->assertSame($this->t1->id, $visit->user_id);
        $this->assertSame([10, 2026, 'maintenance'], [$visit->month, $visit->year, $visit->assignment_type]);
        $this->assertTrue((bool) $visit->deliveryNote->performed);
        $this->assertSame('done', $this->agendaStatus());
        $this->assertNotNull($visit->maintenance_service_id);    // vinculada al contrato

        // Centro de atención: ya no lo pide; el legajo lo muestra.
        $titles = collect(app(AttentionCenter::class)->basic($this->a['company']))->pluck('title');
        $this->assertFalse($titles->contains('Mantenimientos pendientes del mes'));
        $elevator = Elevator::where('building_id', $this->building->id)->first();
        $this->assertSame('visit', app(ElevatorHistory::class)->events($elevator, true)->first()['type']);

        // Un segundo remito del mismo mes no duplica la visita.
        $this->sign($this->t1)->assertSessionHasErrors('general');
        $this->assertSame(1, BuildingVisit::count());
        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_a_remito_signed_as_not_done_is_not_counted_as_done(): void
    {
        $this->assign($this->t1, 'maintenance');

        $this->sign($this->t1, overrides: ['performed' => null])->assertSessionHasNoErrors();

        $this->assertSame('not_done', $this->agendaStatus());
        $this->actingInPanel($this->a['admin']);
        $titles = collect(app(AttentionCenter::class)->basic($this->a['company']))->pluck('count', 'title');
        $this->assertSame(1, $titles['Visitas marcadas como no realizadas']);
        $this->assertFalse(MaintenanceService::sole()->visitStatus('maintenance')['done_this_month']);

        $this->actingAs($this->t1)->get("/{$this->a['company']->slug}/buildings")->assertSee('no se pudo realizar');
    }

    public function test_reassignment_keeps_history_and_moves_the_pending_work(): void
    {
        $this->assign($this->t1, 'maintenance');
        $this->sign($this->t1, overrides: ['month' => 9])->assertSessionHasNoErrors(); // septiembre, cargado tarde

        // Pasa a la técnica 2.
        $this->unassign($this->t1, 'maintenance');
        $this->assign($this->t2, 'maintenance');

        $this->assertSame('done', $this->agendaStatus(9));               // lo hecho sigue hecho
        $this->assertSame($this->t1->id, BuildingVisit::sole()->user_id); // y es de quien lo hizo
        $this->assertSame('pending', $this->agendaStatus(10));

        // El técnico 1 tenía el formulario abierto: ya no puede firmar.
        $this->sign($this->t1)->assertForbidden();
        $this->assertSame(1, BuildingVisit::count());

        // La técnica 2 sí, y la ve en su lista.
        $this->actingAs($this->t2)->get("/{$this->a['company']->slug}/buildings")->assertSee('Torre Norte');
        $this->sign($this->t2)->assertSessionHasNoErrors();
        $this->assertSame('done', $this->agendaStatus(10));
        $this->assertSame($this->t2->id, BuildingVisit::where('month', 10)->sole()->user_id);
    }

    public function test_two_maintenance_technicians_on_the_same_building_never_duplicate_the_visit(): void
    {
        $this->assign($this->t1, 'maintenance');
        $this->assign($this->t2, 'maintenance');

        $this->sign($this->t1, overrides: ['participants' => [$this->t1->id, $this->t2->id]])->assertSessionHasNoErrors();
        $this->sign($this->t2)->assertSessionHasErrors('general');   // "Este remito ya fue generado"

        $visit = BuildingVisit::sole();
        $this->assertEqualsCanonicalizing([$this->t1->id, $this->t2->id], $visit->participants()->pluck('users.id')->all());
        $this->actingAs($this->t2)->get("/{$this->a['company']->slug}/buildings")->assertSee('Mantenimiento realizado');
    }

    public function test_inspections_are_separate_from_maintenance(): void
    {
        $this->assign($this->t1, 'maintenance');
        $this->assign($this->t2, 'inspection');

        // El técnico de mantenimiento no puede firmar la inspección (y al revés).
        $this->sign($this->t1, 'inspection')->assertForbidden();
        $this->sign($this->t2, 'maintenance')->assertForbidden();

        $this->sign($this->t2, 'inspection')->assertSessionHasNoErrors();
        $this->assertSame('done', $this->agendaStatus(10, 'inspection'));
        $this->assertSame('pending', $this->agendaStatus(10, 'maintenance'));

        $elevator = Elevator::where('building_id', $this->building->id)->first();
        $this->assertStringContainsString('Inspección', app(ElevatorHistory::class)->events($elevator, true)->first()['title']);

        // Reasignar la inspección: el historial queda y el nuevo inspector la ve.
        $this->unassign($this->t2, 'inspection');
        $this->assign($this->t1, 'inspection');
        $this->assertSame('done', $this->agendaStatus(10, 'inspection'));
        $this->assertSame($this->t2->id, BuildingVisit::where('assignment_type', 'inspection')->sole()->user_id);
    }

    public function test_only_one_inspector_per_building(): void
    {
        $this->assign($this->t1, 'inspection');

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->building, data: ['user_ids' => [$this->t2->id], 'type' => 'inspection'])
            ->assertNotified('Este edificio ya tiene un inspector asignado.');

        $this->assertSame([$this->t1->id], $this->building->users()->wherePivot('type', 'inspection')->pluck('users.id')->all());
    }
}
