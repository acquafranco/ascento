<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Pages\ListMaintenanceServices;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\StockItems\Pages\CreateStockItem;
use App\Filament\Resources\StockItems\Pages\ListStockItems;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Models\Building;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Billing\ReceivableService;
use App\Services\Billing\ServiceBillingService;
use App\Services\Stock\StockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Stock, Servicios y Cobranzas: aislamiento entre empresas y autorización.
 */
class StockAndBillingIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    /** @var array{item: StockItem, service: MaintenanceService, receivable: Receivable} */
    private array $dataB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        // Datos de la empresa B, creados por su admin.
        $this->actingInPanel($this->b['admin']);
        $item = StockItem::create(['name' => 'Material B', 'unit' => 'unidad', 'cost' => 100, 'min_stock' => 0, 'is_active' => true]);
        app(StockService::class)->receive($item, 10, $this->b['admin'], 'Stock inicial');
        $service = MaintenanceService::create([
            'client_id' => $this->b['building']->client_id, 'building_id' => $this->b['building']->id,
            'description' => 'Servicio B', 'amount' => 5000, 'frequency' => 'monthly',
            'start_date' => '2026-10-01', 'payment_due_day' => 10, 'status' => 'active',
        ]);
        $this->dataB = ['item' => $item->fresh(), 'service' => $service, 'receivable' => $service->receivables()->sole()];

        $this->actingInPanel($this->a['admin']);
    }

    public function test_lists_and_widgets_only_show_the_own_company(): void
    {
        $mine = StockItem::create(['name' => 'Material A', 'unit' => 'unidad', 'cost' => 1, 'min_stock' => 0, 'is_active' => true]);

        Livewire::test(ListStockItems::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$this->dataB['item']]);

        Livewire::test(ListStockMovements::class)
            ->assertCanNotSeeTableRecords(StockMovement::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->get());

        Livewire::test(ListMaintenanceServices::class)->assertCanNotSeeTableRecords([$this->dataB['service']]);
        Livewire::test(ListReceivables::class)->assertCanNotSeeTableRecords([$this->dataB['receivable']])->assertCountTableRecords(0);

        $this->assertNull(StockItem::find($this->dataB['item']->id));
        $this->assertNull(Receivable::find($this->dataB['receivable']->id));
    }

    public function test_records_of_another_company_cannot_be_opened(): void
    {
        $urls = [
            StockItemResource::getUrl('edit', ['record' => $this->dataB['item']]),
            MaintenanceServiceResource::getUrl('edit', ['record' => $this->dataB['service']]),
            ReceivableResource::getUrl('view', ['record' => $this->dataB['receivable']]),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->get(StockItemResource::getUrl())->assertOk();
        $this->get(ReceivableResource::getUrl())->assertOk();
    }

    public function test_injected_company_id_is_ignored(): void
    {
        Livewire::test(CreateStockItem::class)
            ->fillForm(['name' => 'Inyectado', 'unit' => 'unidad', 'cost' => 1, 'min_stock' => 0, 'is_active' => true])
            ->set('data.company_id', $this->b['company']->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->a['company']->id, StockItem::where('name', 'Inyectado')->sole()->company_id);
    }

    public function test_a_work_order_cannot_use_materials_of_another_company(): void
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'in_progress']);

        // Desde el panel: el material de B no es una opción válida.
        Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $workOrder, 'pageClass' => EditWorkOrder::class])
            ->callTableAction('create', data: ['stock_item_id' => $this->dataB['item']->id, 'quantity' => 1])
            ->assertHasTableActionErrors(['stock_item_id']);

        // Y a nivel modelo, por cualquier otro camino.
        try {
            WorkOrderMaterial::create(['work_order_id' => $workOrder->id, 'stock_item_id' => $this->dataB['item']->id, 'quantity' => 1]);
            $this->fail('Se aceptó un material de otra empresa.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, WorkOrderMaterial::withoutGlobalScopes()->count());
        $this->assertEquals(10, StockItem::withoutGlobalScopes()->find($this->dataB['item']->id)->current_stock);
    }

    public function test_receivables_cannot_mix_clients_or_companies(): void
    {
        $service = app(ReceivableService::class);
        $otherClientBuilding = Building::factory()->create(['company_id' => $this->a['company']->id]);

        foreach ([$otherClientBuilding->id, $this->b['building']->id] as $buildingId) {
            try {
                $service->createManual($this->a['building']->client, $buildingId, 'X', 1000, today(), $this->a['admin']);
                $this->fail('Se aceptó un edificio de otro cliente.');
            } catch (ValidationException) {
                // esperado
            }
        }

        $this->assertSame(0, Receivable::count());
    }

    public function test_generating_charges_for_one_company_does_not_touch_another(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05'));
        $countB = fn () => Receivable::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->count();

        // Con la sesión de A no se genera nada de B, ni siquiera pidiéndolo.
        $this->assertSame(0, app(ServiceBillingService::class)->generateAll($this->a['company']->id));
        $this->assertSame(0, app(ServiceBillingService::class)->generateAll($this->b['company']->id));
        $this->assertSame(0, app(ServiceBillingService::class)->generate($this->dataB['service']));
        $this->assertSame(1, $countB());
        $this->assertSame(0, Receivable::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());

        // El comando programado (sin usuario) lo genera en la empresa correcta.
        auth()->logout();
        $this->artisan('billing:generate')->assertSuccessful();
        $this->assertSame(2, $countB());
        $this->assertSame(0, Receivable::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());
    }

    public function test_technicians_cannot_reach_these_screens(): void
    {
        $home = route('dashboard', ['company' => $this->a['company']->slug]);

        foreach ([StockItemResource::getUrl(), StockMovementResource::getUrl(), MaintenanceServiceResource::getUrl(), ReceivableResource::getUrl()] as $url) {
            $this->actingAs($this->a['technician'])->get($url)->assertRedirect($home);
        }

        $this->actingAs($this->a['technician']);
        $this->assertFalse(StockItemResource::canAccess());
        $this->assertFalse(ReceivableResource::canAccess());
        $this->assertFalse(MaintenanceServiceResource::canAccess());
    }
}
