<?php

namespace Tests\Feature\Stock;

use App\Filament\Resources\StockItems\Pages\CreateStockItem;
use App\Filament\Resources\StockItems\Pages\ListStockItems;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Stock\StockService;
use App\Services\WorkOrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Stock: catálogo, movimientos y descuento automático (idempotente) al
 * completar órdenes de trabajo.
 */
class StockTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);
    }

    private function item(string $name = 'Contactor Schneider', float $stock = 10, float $min = 2, string $unit = 'unidad'): StockItem
    {
        $item = StockItem::create(['name' => $name, 'unit' => $unit, 'cost' => 15000, 'min_stock' => $min, 'is_active' => true]);

        if ($stock > 0) {
            app(StockService::class)->receive($item, $stock, $this->a['admin'], 'Stock inicial');
        }

        return $item->fresh();
    }

    private function order(string $status = 'in_progress'): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => $status]);
        $workOrder->users()->attach($this->a['technician']->id);

        return $workOrder;
    }

    private function addMaterial(WorkOrder $workOrder, StockItem $item, float $quantity): WorkOrderMaterial
    {
        return WorkOrderMaterial::create(['work_order_id' => $workOrder->id, 'stock_item_id' => $item->id, 'quantity' => $quantity]);
    }

    /*
    |--------------------------------------------------------------------------
    | CATÁLOGO Y MOVIMIENTOS
    |--------------------------------------------------------------------------
    */

    public function test_admin_creates_a_material_with_initial_stock(): void
    {
        Livewire::test(CreateStockItem::class)
            ->fillForm([
                'name' => 'Cable de maniobra',
                'code' => 'CAB-01',
                'unit' => 'metro',
                'cost' => 1200,
                'min_stock' => 20,
                'initial_stock' => 100,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = StockItem::sole();
        $this->assertSame($this->a['company']->id, $item->company_id);
        $this->assertEquals(100, $item->current_stock);
        $this->assertSame('100 metro', $item->formatQuantity($item->current_stock));

        $movement = StockMovement::sole();
        $this->assertSame(StockMovement::IN, $movement->type);
        $this->assertSame('Stock inicial', $movement->reason);
        $this->assertSame($this->a['admin']->id, $movement->user_id);
    }

    public function test_entry_manual_exit_and_adjustment(): void
    {
        $item = $this->item(stock: 5);

        Livewire::test(ListStockItems::class)
            ->callTableAction('in', $item, data: ['quantity' => 3, 'reason' => 'Compra'])
            ->assertHasNoTableActionErrors();
        $this->assertEquals(8, $item->fresh()->current_stock);

        Livewire::test(ListStockItems::class)
            ->callTableAction('out', $item, data: ['quantity' => 2, 'reason' => 'Retiro para taller'])
            ->assertHasNoTableActionErrors();
        $this->assertEquals(6, $item->fresh()->current_stock);

        // Ajuste por conteo físico: deja el stock en 4 (delta -2).
        Livewire::test(ListStockItems::class)
            ->callTableAction('adjustment', $item, data: ['quantity' => 4, 'reason' => 'Conteo de inventario'])
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEquals(4, $item->current_stock);
        $adjustment = StockMovement::where('type', StockMovement::ADJUSTMENT)->sole();
        $this->assertEquals(-2, $adjustment->quantity);
        $this->assertEquals(4, $adjustment->balance_after);

        // El ajuste exige motivo.
        Livewire::test(ListStockItems::class)
            ->callTableAction('adjustment', $item, data: ['quantity' => 10, 'reason' => ''])
            ->assertHasTableActionErrors(['reason']);
    }

    public function test_a_manual_exit_cannot_leave_negative_stock(): void
    {
        $item = $this->item(stock: 1);

        $this->expectException(ValidationException::class);

        try {
            app(StockService::class)->issue($item, 3, $this->a['admin'], 'Error de carga');
        } finally {
            $this->assertEquals(1, $item->fresh()->current_stock);
            $this->assertSame(1, StockMovement::count());
        }
    }

    public function test_movement_history_is_immutable_and_listed(): void
    {
        $item = $this->item(stock: 5);
        app(StockService::class)->issue($item, 1, $this->a['admin'], 'Retiro');

        $movement = StockMovement::latest('id')->first();
        $this->assertFalse($movement->update(['quantity' => 999]));
        $this->assertFalse($movement->delete());
        $this->assertEquals(-1, $movement->fresh()->quantity);

        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords(StockMovement::all())
            ->assertSee('Retiro')
            ->assertSee('Stock inicial');
    }

    public function test_low_stock_is_flagged(): void
    {
        $low = $this->item('Lámpara LED', stock: 2, min: 2);
        $ok = $this->item('Botonera', stock: 10, min: 2);

        $this->assertTrue($low->isLow());
        $this->assertFalse($ok->isLow());
        $this->assertSame('1', StockItemResource::getNavigationBadge());

        Livewire::test(ListStockItems::class)
            ->filterTable('low')
            ->assertCountTableRecords(1)
            ->assertSee('Lámpara LED')
            ->assertDontSee('Botonera');
    }

    /*
    |--------------------------------------------------------------------------
    | ÓRDENES DE TRABAJO
    |--------------------------------------------------------------------------
    */

    public function test_materials_are_added_to_an_order_without_touching_stock_yet(): void
    {
        $contactor = $this->item('Contactor', stock: 5);
        $cable = $this->item('Cable', stock: 50, unit: 'metro');
        $workOrder = $this->order();

        $manager = Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $workOrder, 'pageClass' => EditWorkOrder::class]);
        $manager->callTableAction('create', data: ['stock_item_id' => $contactor->id, 'quantity' => 1])->assertHasNoTableActionErrors();
        $manager->callTableAction('create', data: ['stock_item_id' => $cable->id, 'quantity' => 5])->assertHasNoTableActionErrors();

        $this->assertSame(2, $workOrder->materials()->count());
        $this->assertEquals(5, $contactor->fresh()->current_stock);
        $this->assertEquals(50, $cable->fresh()->current_stock);
        $this->assertEquals(15000, $workOrder->materials()->first()->unit_cost); // costo al momento
    }

    public function test_completing_the_order_from_the_panel_discounts_stock(): void
    {
        $item = $this->item(stock: 5);
        $workOrder = $this->order();
        $material = $this->addMaterial($workOrder, $item, 2);

        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(3, $item->fresh()->current_stock);

        $movement = StockMovement::where('type', StockMovement::OUT)->sole();
        $this->assertSame($workOrder->id, $movement->work_order_id);
        $this->assertSame($material->id, $movement->work_order_material_id);
        $this->assertSame('Orden de trabajo #'.$workOrder->id, $movement->reason);
        $this->assertSame($movement->id, $material->fresh()->stock_movement_id);
    }

    public function test_completing_through_the_technician_flow_discounts_stock(): void
    {
        $item = $this->item(stock: 5);

        // WorkOrderService::finish (cierre del técnico).
        $first = $this->order();
        $this->addMaterial($first, $item, 1);
        app(WorkOrderService::class)->finish($first, $this->a['technician']);

        // Remito firmado de la orden (el camino más común).
        $second = $this->order();
        $second->participants()->attach($this->a['technician']->id, ['role' => 'participant']);
        $this->addMaterial($second, $item, 2);

        $this->actingAs($this->a['technician'])
            ->post("/{$this->a['company']->slug}/delivery-notes/store", [
                'building_id' => $this->a['building']->id,
                'work_order_id' => $second->id,
                'assignment_type' => 'work_order',
                'description' => 'Cambio de contactor',
                'month' => now()->month,
                'year' => now()->year,
                'elevator_quantity' => 1,
                'freight_elevator_quantity' => 0,
                'signature_name' => 'Técnico',
                'signature' => $this->validSignature(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $second->fresh()->status);
        $this->assertEquals(2, $item->fresh()->current_stock); // 5 - 1 - 2
    }

    public function test_never_discounts_twice(): void
    {
        $item = $this->item(stock: 10);
        $workOrder = $this->order();
        $material = $this->addMaterial($workOrder, $item, 3);

        $workOrder->update(['status' => 'completed']);
        $workOrder->update(['notes' => 'Editada después']);              // guardar de nuevo
        $workOrder->update(['status' => 'in_progress']);                 // reabrir…
        $workOrder->update(['status' => 'completed']);                   // …y volver a completar
        app(StockService::class)->consumeWorkOrder($workOrder);          // forzar otra vez
        app(StockService::class)->consumeMaterial($material);

        $this->assertEquals(7, $item->fresh()->current_stock);
        $this->assertSame(1, StockMovement::where('work_order_id', $workOrder->id)->count());

        // Y la base impide un segundo movimiento para el mismo renglón.
        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate = new StockMovement(['stock_item_id' => $item->id, 'type' => 'out', 'quantity' => -3, 'balance_after' => 4, 'work_order_material_id' => $material->id, 'occurred_at' => now()]);
        $duplicate->company_id = $this->a['company']->id;
        $duplicate->save();
    }

    public function test_insufficient_stock_warns_but_does_not_block_closing_the_order(): void
    {
        $item = $this->item(stock: 1, min: 2);
        $workOrder = $this->order();

        // Se puede cargar más de lo que hay (la advertencia del formulario se
        // revisa en el navegador: el modal no forma parte de este componente).
        Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $workOrder, 'pageClass' => EditWorkOrder::class])
            ->callTableAction('create', data: ['stock_item_id' => $item->id, 'quantity' => 3])
            ->assertHasNoTableActionErrors();

        $workOrder->update(['status' => 'completed']);

        $item->refresh();
        $this->assertEquals(-2, $item->current_stock);
        $this->assertTrue($item->isLow());
        $this->assertSame('completed', $workOrder->fresh()->status);
    }

    public function test_material_added_to_a_completed_order_is_discounted_immediately_and_locked(): void
    {
        $item = $this->item(stock: 10);
        $workOrder = $this->order('completed');

        $material = $this->addMaterial($workOrder, $item, 4);

        $this->assertEquals(6, $item->fresh()->current_stock);
        $this->assertTrue($material->fresh()->isConsumed());

        // Ya descontado: ni se edita la cantidad ni se borra.
        $this->assertFalse($material->fresh()->update(['quantity' => 1]));
        $this->assertFalse($material->fresh()->delete());
        $this->assertEquals(4, $material->fresh()->quantity);
    }
}
