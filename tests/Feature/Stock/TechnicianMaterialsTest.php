<?php

namespace Tests\Feature\Stock;

use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * El técnico declara los materiales usados al firmar el remito de la orden.
 * Solo declara: no crea materiales, no toca costos ni stock directamente.
 */
class TechnicianMaterialsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private WorkOrder $order;

    private StockItem $contactor;

    private StockItem $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();

        $this->actingAs($this->a['admin']);
        $this->contactor = $this->item('Contactor', 'unidad', 5, 45000);
        $this->cable = $this->item('Cable', 'metro', 100, 3200);
        auth()->logout();

        $this->order = WorkOrder::factory()->inProgress()->create(['building_id' => $this->a['building']->id]);
        $this->order->users()->attach($this->a['technician']->id);
        $this->order->participants()->attach($this->a['technician']->id, ['role' => 'participant']);
    }

    private function item(string $name, string $unit, float $stock, float $cost, array $attributes = []): StockItem
    {
        $item = StockItem::create(['name' => $name, 'unit' => $unit, 'cost' => $cost, 'min_stock' => 1, 'is_active' => true, ...$attributes]);
        app(StockService::class)->receive($item, $stock, auth()->user(), 'Stock inicial');

        return $item->fresh();
    }

    private function sign(array $materials, ?User $as = null, ?WorkOrder $order = null)
    {
        $order ??= $this->order;

        return $this->actingAs($as ?? $this->a['technician'])->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $order->building_id,
            'work_order_id' => $order->id,
            'assignment_type' => 'work_order',
            'description' => 'Cambio de contactor y cableado',
            'elevator_quantity' => 1,
            'freight_elevator_quantity' => 0,
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
            'materials' => $materials,
        ]);
    }

    public function test_the_form_lists_the_company_materials_without_costs(): void
    {
        $this->actingAs($this->a['admin']);
        WorkOrderMaterial::create(['work_order_id' => $this->order->id, 'stock_item_id' => $this->cable->id, 'quantity' => 4]);

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/delivery-notes/create/work-order/{$this->order->id}")
            ->assertOk()
            ->assertSee('Materiales utilizados')
            ->assertSee('Contactor')
            ->assertSee('Ya cargados por la oficina')
            ->assertSee('4 metro')
            ->assertDontSee('45000')
            ->assertDontSee('45.000');
    }

    public function test_technician_declares_materials_and_stock_is_discounted_once_on_closing(): void
    {
        $this->sign([
            ['stock_item_id' => $this->contactor->id, 'quantity' => 1],
            ['stock_item_id' => $this->cable->id, 'quantity' => '12.5'],
            ['stock_item_id' => $this->contactor->id, 'quantity' => 1], // repetido: se suma
        ])->assertSessionHasNoErrors();

        $this->assertSame('completed', $this->order->fresh()->status);

        $rows = $this->order->materials()->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(2, $rows->firstWhere('stock_item_id', $this->contactor->id)->quantity);
        $this->assertEquals(45000, $rows->firstWhere('stock_item_id', $this->contactor->id)->unit_cost); // costo del catálogo
        $this->assertTrue($rows->every(fn ($row) => $row->declared_by === $this->a['technician']->id && $row->isConsumed()));

        $this->assertEquals(3, $this->contactor->fresh()->current_stock);
        $this->assertEquals(87.5, $this->cable->fresh()->current_stock);
        $this->assertSame(2, StockMovement::where('work_order_id', $this->order->id)->count());

        // El remito queda asociado a esos materiales.
        $note = $this->order->fresh()->deliveryNote;
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")
            ->assertOk()->assertSee('Materiales utilizados')->assertSee('12,50 metro');

        // Doble envío: rechazado, sin renglones ni descuentos nuevos.
        $this->sign([['stock_item_id' => $this->contactor->id, 'quantity' => 1]])->assertStatus(409);
        $this->assertEquals(3, $this->contactor->fresh()->current_stock);
        $this->assertCount(2, $this->order->materials()->get());
    }

    public function test_invalid_quantities_are_rejected_and_nothing_changes(): void
    {
        foreach ([0, -1, 'abc', '1.234', 100000, null] as $quantity) {
            $this->sign([['stock_item_id' => $this->contactor->id, 'quantity' => $quantity]])
                ->assertSessionHasErrors('materials.0.quantity');
        }

        $this->sign([['quantity' => 1]])->assertSessionHasErrors('materials.0.stock_item_id');
        $this->sign(array_fill(0, 21, ['stock_item_id' => $this->contactor->id, 'quantity' => 1]))->assertSessionHasErrors('materials');

        $this->assertSame('in_progress', $this->order->fresh()->status);
        $this->assertSame(0, WorkOrderMaterial::count());
        $this->assertEquals(5, $this->contactor->fresh()->current_stock);
    }

    public function test_materials_of_another_company_inactive_or_deleted_are_rejected(): void
    {
        $b = $this->makeTenant();
        $this->actingAs($b['admin']);
        $foreign = $this->item('Contactor de B', 'unidad', 10, 1);
        $this->actingAs($this->a['admin']);
        $inactive = $this->item('Viejo', 'unidad', 3, 1, ['is_active' => false]);
        $deleted = $this->item('Borrado', 'unidad', 3, 1);
        $deleted->delete();

        foreach ([$foreign, $inactive, $deleted] as $item) {
            $this->sign([['stock_item_id' => $item->id, 'quantity' => 1]])->assertSessionHasErrors('materials.0.stock_item_id');
        }

        $this->assertSame(0, WorkOrderMaterial::withoutGlobalScopes()->count());
        $this->assertEquals(10, StockItem::withoutGlobalScopes()->find($foreign->id)->current_stock);
    }

    public function test_cost_company_and_stock_cannot_be_injected(): void
    {
        $b = $this->makeTenant();

        $this->sign([[
            'stock_item_id' => $this->contactor->id,
            'quantity' => 1,
            'unit_cost' => 1,
            'company_id' => $b['company']->id,
            'stock_movement_id' => 999,
            'declared_by' => $this->a['admin']->id,
        ]], null)->assertSessionHasNoErrors();

        $row = WorkOrderMaterial::sole();
        $this->assertEquals(45000, $row->unit_cost);
        $this->assertSame($this->a['company']->id, $row->company_id);
        $this->assertSame($this->a['technician']->id, $row->declared_by);
        $this->assertNotSame(999, $row->stock_movement_id);
        $this->assertEquals(4, $this->contactor->fresh()->current_stock);
    }

    public function test_only_an_assigned_technician_can_declare_materials(): void
    {
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->sign([['stock_item_id' => $this->contactor->id, 'quantity' => 1]], $other)->assertForbidden();

        $this->assertSame(0, WorkOrderMaterial::count());
        $this->assertEquals(5, $this->contactor->fresh()->current_stock);

        // Y no puede tocar stock ni materiales desde el panel.
        $this->actingAs($this->a['technician'])->get('/admin/stock-items/create')->assertRedirect();
        $this->actingAs($this->a['technician'])->get('/admin/stock-items')->assertRedirect();
    }

    public function test_insufficient_stock_is_allowed_but_left_visible(): void
    {
        $this->sign([['stock_item_id' => $this->contactor->id, 'quantity' => 8]])->assertSessionHasNoErrors();

        $item = $this->contactor->fresh();
        $this->assertEquals(-3, $item->current_stock);
        $this->assertTrue($item->isLow());
    }

    public function test_cancelled_or_finished_orders_take_no_materials(): void
    {
        foreach (['failed', 'completed', 'pending'] as $status) {
            $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => $status]);
            $order->users()->attach($this->a['technician']->id);

            $this->sign([['stock_item_id' => $this->contactor->id, 'quantity' => 1]], null, $order)->assertStatus(409);
        }

        $this->assertSame(0, WorkOrderMaterial::count());
        $this->assertEquals(5, $this->contactor->fresh()->current_stock);
    }

    public function test_reopening_and_closing_again_never_discounts_twice_and_used_quantities_are_locked(): void
    {
        $this->sign([['stock_item_id' => $this->contactor->id, 'quantity' => 2]])->assertSessionHasNoErrors();
        $this->assertEquals(3, $this->contactor->fresh()->current_stock);

        // El admin reabre la orden y la vuelve a completar desde el panel.
        $this->actingInPanel($this->a['admin']);
        foreach (['in_progress', 'completed', 'in_progress', 'completed'] as $status) {
            Livewire::test(EditWorkOrder::class, ['record' => $this->order->getRouteKey()])
                ->fillForm(['status' => $status])->call('save')->assertHasNoFormErrors();
        }

        $this->assertEquals(3, $this->contactor->fresh()->current_stock);
        $this->assertSame(1, StockMovement::where('work_order_id', $this->order->id)->count());

        // La cantidad ya descontada no se cambia ni se borra: se corrige con un ajuste.
        $row = WorkOrderMaterial::sole();
        $this->assertFalse($row->update(['quantity' => 1]));
        $this->assertFalse($row->delete());
        $this->assertEquals(2, $row->fresh()->quantity);

        app(StockService::class)->adjustTo($this->contactor->fresh(), 4, $this->a['admin'], 'Se devolvió un contactor sin usar');
        $this->assertEquals(4, $this->contactor->fresh()->current_stock);

        // Si mientras estaba reabierta se agrega otro material, se descuenta al completar (una vez).
        Livewire::test(EditWorkOrder::class, ['record' => $this->order->getRouteKey()])->fillForm(['status' => 'in_progress'])->call('save');
        WorkOrderMaterial::create(['work_order_id' => $this->order->id, 'stock_item_id' => $this->cable->id, 'quantity' => 3]);
        $this->assertEquals(100, $this->cable->fresh()->current_stock);
        Livewire::test(EditWorkOrder::class, ['record' => $this->order->getRouteKey()])->fillForm(['status' => 'completed'])->call('save');
        $this->assertEquals(97, $this->cable->fresh()->current_stock);
        $this->assertEquals(4, $this->contactor->fresh()->current_stock);
    }
}
