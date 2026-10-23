<?php

namespace Tests\Feature\WorkOrders;

use App\Filament\Resources\RepairComponents\RepairComponentResource;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Insights\FailureAnalysis;
use App\Services\Insights\Indicators;
use App\Support\WorkOrderLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Tipo de trabajo de las órdenes: el técnico lo elige al registrar una
 * intervención (validado en el servidor) y la oficina tiene «Reparación»
 * (un trabajo hecho, facturable aparte) distinto de «Reclamo» (un aviso) y
 * del módulo «Reparaciones» (componentes en taller), que no cambia.
 */
class WorkTypeAndRepairTest extends TestCase
{
    use InteractsWithTenants, MakesSignatures, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        auth()->logout();
    }

    private function open(User $tech, array $overrides = [])
    {
        return $this->actingAs($tech)->post(route('interventions.store', ['company' => $this->a['company']->slug]), [
            'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1', 'channel' => 'building', 'kind' => 'unscheduled',
            'description' => 'Durante el mantenimiento se cambió un pulsador de cabina.', 'submission_token' => (string) Str::uuid(), ...$overrides,
        ]);
    }

    public function test_the_type_list_is_single_and_includes_repair_without_touching_existing_values(): void
    {
        $this->assertSame(['maintenance', 'inspection', 'claim', 'repair', 'installation', 'modernization'], array_keys(WorkOrderLabels::TYPES));
        $this->assertSame('Reparación', WorkOrderLabels::type('repair'));
        $this->assertSame('Reclamo', WorkOrderLabels::type('claim'));

        // Las órdenes que ya existían conservan su tipo (la migración solo agrega un valor).
        $this->actingAs($this->a['admin']);
        $old = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'modernization']);
        $repair = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'repair']);
        $this->assertSame(['modernization', 'repair'], [$old->fresh()->type, $repair->fresh()->type]);
    }

    public function test_the_technician_must_choose_a_valid_work_type_and_it_is_saved_on_the_order(): void
    {
        $tech = $this->a['technician'];

        $this->actingAs($tech)->get(route('interventions.create', ['company' => $this->a['company']->slug]))->assertOk()
            ->assertSee('Tipo de trabajo')->assertSee('<option value="repair"', false)->assertSee('Elegí el tipo de trabajo');

        // Sin tipo, o con uno inventado: el servidor lo rechaza y no se crea nada.
        $this->open($tech)->assertSessionHasErrors(['work_type' => 'Elegí el tipo de trabajo.']);
        $this->open($tech, ['work_type' => 'billing'])->assertSessionHasErrors('work_type');
        $this->postJson(route('interventions.store', ['company' => $this->a['company']->slug]), [
            'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1', 'channel' => 'building', 'kind' => 'unscheduled',
            'description' => 'Sin tipo por JSON', 'submission_token' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors('work_type');
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());

        // Con «Reparación»: la orden queda de ese tipo, vinculada a su trabajo.
        $this->open($tech, ['work_type' => 'repair'])->assertSessionHasNoErrors()->assertRedirect();
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $this->assertSame(['repair', WorkOrder::ORIGIN_TECHNICIAN], [$order->type, $order->origin]);
        $this->assertSame($order->id, Report::withoutGlobalScopes()->sole()->work_order_id);
        $this->get(route('work-orders.show', ['company' => $this->a['company']->slug, 'workOrder' => $order->id]))->assertSee('Reparación');

        // Un trabajo sumado a la visita no cambia el tipo de la orden.
        $this->post(route('interventions.job.store', ['company' => $this->a['company']->slug, 'workOrder' => $order->id]), [
            'elevator_number' => 'Ascensor 2', 'channel' => 'detected', 'kind' => 'unscheduled', 'description' => 'Ajuste de puerta',
            'submission_token' => (string) Str::uuid(), 'work_type' => 'claim',
        ])->assertRedirect();
        $this->assertSame('repair', $order->fresh()->type);

        // El remito firmado muestra el tipo.
        $jobs = Report::withoutGlobalScopes()->orderBy('id')->pluck('id');
        $this->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order',
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0, 'signature_name' => $tech->name, 'signature' => $this->validSignature(),
            'jobs' => [$jobs[0] => ['work_done' => 'Pulsador cambiado.', 'result' => 'resolved', 'elevator_state' => 'in_service'], $jobs[1] => ['work_done' => 'Puerta ajustada.', 'result' => 'resolved', 'elevator_state' => 'in_service']],
        ])->assertSessionHasNoErrors();
        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->get(route('delivery-notes.show', ['company' => $this->a['company']->slug, 'deliveryNote' => $note]))->assertSee('Intervención del técnico: Reparación');
    }

    public function test_the_office_creates_edits_and_filters_repair_orders(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateWorkOrder::class)
            ->fillForm(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->a['technician']->id], 'type' => 'repair', 'priority' => 'medium', 'status' => 'pending'])
            ->call('create')->assertHasNoFormErrors();
        $repair = WorkOrder::where('type', 'repair')->sole();

        $maintenance = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'maintenance']);
        Livewire::test(EditWorkOrder::class, ['record' => $maintenance->getRouteKey()])
            ->fillForm(['type' => 'repair', 'users' => [$this->a['technician']->id]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('repair', $maintenance->fresh()->type);

        // Un tipo inventado no se guarda.
        Livewire::test(EditWorkOrder::class, ['record' => $maintenance->getRouteKey()])
            ->fillForm(['type' => 'billing', 'users' => [$this->a['technician']->id]])->call('save')->assertHasFormErrors(['type']);
        $this->assertSame('repair', $maintenance->fresh()->type);

        $claim = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim']);
        Livewire::test(ListWorkOrders::class)
            ->assertSee('Reparación')
            ->filterTable('type', 'repair')->assertCanSeeTableRecords([$repair, $maintenance])->assertCanNotSeeTableRecords([$claim]);
    }

    public function test_a_repair_is_not_counted_as_a_claim_and_the_repairs_module_is_unchanged(): void
    {
        $this->actingInPanel($this->a['admin']);
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'repair', 'unit' => 'Ascensor 1', 'status' => 'completed']);
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'unit' => 'Ascensor 1', 'status' => 'completed']);
        auth()->logout();
        // Intervención del técnico de tipo «Reparación»: su trabajo no es un reclamo.
        $this->open($this->a['technician'], ['work_type' => 'repair']);
        auth()->logout();

        $this->actingInPanel($this->a['admin']);
        $elevator = Elevator::where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->sole();
        $this->assertSame(1, app(FailureAnalysis::class)->forElevator($elevator)['claims']); // solo el reclamo
        $claims = (new \ReflectionMethod(Indicators::class, 'claims'))->invoke(app(Indicators::class), now()->subDay(), now()->addDay());
        $this->assertSame(1, $claims);

        // El módulo «Reparaciones» (componentes en taller) sigue igual y no lista órdenes.
        $this->get(RepairComponentResource::getUrl())->assertOk()->assertDontSee('Reparación de pulsador');
        $this->assertSame('reparaciones', RepairComponentResource::getSlug());
    }

    public function test_work_types_never_cross_companies(): void
    {
        // Un técnico de B con un tipo válido no puede abrir una orden en un edificio de A.
        $this->actingAs($this->b['technician'])->post(route('interventions.store', ['company' => $this->b['company']->slug]), [
            'building_id' => $this->a['building']->id, 'work_type' => 'repair', 'elevator_number' => 'Ascensor 1', 'channel' => 'other',
            'kind' => 'urgent', 'description' => 'Intento cruzado', 'submission_token' => (string) Str::uuid(),
        ])->assertSessionHasErrors('building_id');
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());
    }
}
