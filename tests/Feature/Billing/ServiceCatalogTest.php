<?php

namespace Tests\Feature\Billing;

use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\ServiceCatalogItems\Pages\CreateServiceCatalogItem;
use App\Filament\Resources\ServiceCatalogItems\Pages\ListServiceCatalogItems;
use App\Filament\Resources\ServiceCatalogItems\ServiceCatalogItemResource;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Billing\ServiceBillingService;
use App\Services\Catalog\ServicePricing;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Catálogo de servicios y contrataciones flexibles: alcance (cliente, edificios,
 * equipos), modalidades de precio, historial intacto ante cambios del catálogo,
 * integración con visitas, agenda, cobros y presupuestos, y aislamiento.
 */
class ServiceCatalogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    /** Segundo edificio del mismo cliente: 3 ascensores y 1 montacargas (sin técnico). */
    private Building $tower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant(); // su edificio: 2 ascensores
        $this->client = Client::find($this->a['building']->client_id);
        $this->tower = Building::factory()->create([
            'company_id' => $this->a['company']->id, 'client_id' => $this->client->id,
            'name' => 'Torre Norte', 'elevator_count' => 3, 'freight_elevator_count' => 1,
        ]);
        $this->actingInPanel($this->a['admin']);
    }

    private function item(array $attributes = []): ServiceCatalogItem
    {
        return ServiceCatalogItem::create([
            'name' => 'Mantenimiento por equipo', 'category' => 'maintenance', 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT,
            'base_price' => 50000, 'billing_frequency' => 'monthly', 'equipment_kind' => 'all', 'is_active' => true,
            ...$attributes,
        ]);
    }

    /** Alta desde «Servicios → Nuevo» con los datos mínimos del formulario. */
    private function assign(array $data)
    {
        return Livewire::test(CreateMaintenanceService::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'description' => 'Mantenimiento mensual',
                'category' => 'maintenance',
                'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT,
                'unit_price' => 50000,
                'frequency' => 'monthly',
                'payment_due_day' => 10,
                'start_date' => '2026-10-01',
                'status' => 'active',
                ...$data,
            ])
            ->call('create');
    }

    private function staff(array $levels, string $name): User
    {
        $this->actingAs($this->a['admin']);

        return app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
    }

    /*
    |--------------------------------------------------------------------------
    | CATÁLOGO
    |--------------------------------------------------------------------------
    */

    public function test_a_catalog_item_is_created_for_the_company_and_its_name_is_unique_there(): void
    {
        Livewire::test(CreateServiceCatalogItem::class)
            ->fillForm([
                'name' => 'Guardia técnica 24 h', 'category' => 'guard', 'description' => 'Atención de urgencias',
                'includes' => ['Atención nocturna', 'Fines de semana'], 'pricing_mode' => ServiceCatalogItem::FIXED,
                'base_price' => 80000, 'billing_frequency' => 'monthly', 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = ServiceCatalogItem::sole();
        $this->assertSame($this->a['company']->id, $item->company_id);
        $this->assertSame(['Atención nocturna', 'Fines de semana'], $item->includes);
        $this->assertEquals(80000, $item->base_price);
        $this->assertSame('$80.000 fijo', ServiceCatalogItemResource::priceLabel($item));

        // Mismo nombre en la misma empresa: no.
        Livewire::test(CreateServiceCatalogItem::class)
            ->fillForm(['name' => 'Guardia técnica 24 h', 'category' => 'guard', 'pricing_mode' => ServiceCatalogItem::FIXED, 'base_price' => 1, 'billing_frequency' => 'monthly'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        // Precio acordado: el precio de referencia es opcional; el resto lo pide.
        Livewire::test(CreateServiceCatalogItem::class)
            ->fillForm(['name' => 'Por equipo sin precio', 'category' => 'maintenance', 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'base_price' => null, 'billing_frequency' => 'monthly'])
            ->call('create')
            ->assertHasFormErrors(['base_price']);
        Livewire::test(CreateServiceCatalogItem::class)
            ->fillForm(['name' => 'Reparación a medida', 'category' => 'extra', 'pricing_mode' => ServiceCatalogItem::CUSTOM, 'base_price' => null, 'billing_frequency' => 'once'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_a_contracted_catalog_item_can_be_deactivated_but_not_deleted(): void
    {
        $item = $this->item();
        $unused = $this->item(['name' => 'Limpieza de sala de máquinas', 'category' => 'cleaning']);
        $this->assign(['catalog_item_id' => $item->id, 'scope' => 'buildings', 'building_ids' => [$this->a['building']->id]])->assertHasNoFormErrors();

        Livewire::test(ListServiceCatalogItems::class)
            ->assertTableActionHidden('delete', $item)
            ->assertTableActionVisible('delete', $unused)
            ->callTableAction('toggle', $item);

        $this->assertFalse($item->fresh()->is_active);
        $this->assertSame(MaintenanceService::ACTIVE, MaintenanceService::sole()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | ALCANCE Y CÁLCULO
    |--------------------------------------------------------------------------
    */

    public function test_assigning_to_the_whole_client_counts_the_equipment_of_all_its_buildings(): void
    {
        $item = $this->item();
        $this->assign(['catalog_item_id' => $item->id, 'scope' => 'client'])->assertHasNoFormErrors();

        $service = MaintenanceService::sole();
        $this->assertNull($service->building_id);
        $this->assertSame($item->id, $service->catalog_item_id);
        $this->assertSame(6, $service->quantity); // 2 + 3 + 1 montacargas
        $this->assertEquals(50000, $service->unit_price);
        $this->assertEquals(300000, $service->amount);
        $this->assertSame('6 equipos × $50.000 = $300.000', $service->pricingExplanation());
        $this->assertCount(2, $service->pricing_detail['lines']);
        $this->assertSame('Todo el cliente', $service->scopeLabel());
        $this->assertEquals(300000, Receivable::sole()->amount);
    }

    public function test_several_buildings_create_one_contract_each_with_its_own_equipment_count(): void
    {
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->a['building']->id, $this->tower->id]])->assertHasNoFormErrors();

        $services = MaintenanceService::orderBy('amount')->get();
        $this->assertSame([$this->a['building']->id, $this->tower->id], $services->pluck('building_id')->all());
        $this->assertSame([2, 4], $services->pluck('quantity')->all());
        $this->assertEquals([100000, 200000], $services->pluck('amount')->map(fn ($v) => (float) $v)->all());
        // Cada contratación genera su cobro (uno por edificio, sin duplicados).
        $this->assertSame(2, Receivable::count());
        $this->assertSame(2, Receivable::distinct('maintenance_service_id')->count('maintenance_service_id'));
    }

    public function test_assigning_to_specific_equipment_and_kind_of_equipment(): void
    {
        $this->assign(['scope' => 'equipment', 'building_id' => $this->tower->id, 'units' => ['Ascensor 2', 'Montacargas 1']])->assertHasNoFormErrors();

        $service = MaintenanceService::sole();
        $this->assertSame(['Ascensor 2', 'Montacargas 1'], $service->units);
        $this->assertSame(2, $service->quantity);
        $this->assertEquals(100000, $service->amount);
        $this->assertSame('Torre Norte: Ascensor 2, Montacargas 1', $service->scopeLabel());

        // «Solo montacargas» en el edificio: cuenta 1.
        $freight = $this->item(['name' => 'Montacargas', 'equipment_kind' => 'freight', 'base_price' => 70000]);
        $this->assign(['catalog_item_id' => $freight->id, 'scope' => 'buildings', 'building_ids' => [$this->tower->id], 'unit_price' => 70000, 'equipment_kind' => 'freight'])->assertHasNoFormErrors();
        $this->assertEquals(70000, MaintenanceService::where('catalog_item_id', $freight->id)->sole()->amount);
    }

    public function test_a_building_without_that_kind_of_equipment_creates_nothing(): void
    {
        // El edificio original no tiene montacargas: no se crea ninguno (todo o nada).
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id, $this->a['building']->id], 'equipment_kind' => 'freight'])
            ->assertHasFormErrors(['unit_price']);

        $this->assertSame(0, MaintenanceService::count());
        $this->assertSame(0, Receivable::count());
    }

    public function test_fixed_and_custom_prices_do_not_depend_on_equipment(): void
    {
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id], 'pricing_mode' => ServiceCatalogItem::FIXED, 'unit_price' => 90000])->assertHasNoFormErrors();
        $this->assign(['scope' => 'client', 'description' => 'Guardia', 'category' => 'guard', 'pricing_mode' => ServiceCatalogItem::CUSTOM, 'unit_price' => 45500.5])->assertHasNoFormErrors();

        [$fixed, $custom] = MaintenanceService::orderBy('id')->get()->all();
        $this->assertEquals([1, 90000], [$fixed->quantity, (float) $fixed->amount]);
        $this->assertSame('Importe por período: $90.000', $fixed->pricingExplanation());
        $this->assertEquals(45500.5, $custom->amount);
        $this->assertSame('Importe acordado: $45.500,50', $custom->pricingExplanation());
    }

    public function test_per_stop_uses_the_loaded_stops_and_never_guesses(): void
    {
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->a['building']->id], 'pricing_mode' => ServiceCatalogItem::PER_STOP, 'unit_price' => 5000])
            ->assertHasFormErrors(['unit_price']);
        $this->assertSame(0, MaintenanceService::count());

        Elevator::where('building_id', $this->a['building']->id)->update(['stops' => 8]);
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->a['building']->id], 'pricing_mode' => ServiceCatalogItem::PER_STOP, 'unit_price' => 5000])
            ->assertHasNoFormErrors();

        $service = MaintenanceService::sole();
        $this->assertSame(16, $service->quantity);
        $this->assertEquals(80000, $service->amount);
        $this->assertSame('16 paradas × $5.000 = $80.000', $service->pricingExplanation());
    }

    public function test_the_live_summary_shows_the_calculation_before_saving(): void
    {
        Livewire::test(CreateMaintenanceService::class)
            ->fillForm(['client_id' => $this->client->id, 'scope' => 'buildings', 'building_ids' => [$this->a['building']->id, $this->tower->id], 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000, 'frequency' => 'monthly'])
            ->assertSeeHtml('data-pricing-summary')
            ->assertSee('2 equipos × $50.000 = $100.000')
            ->assertSee('4 equipos × $50.000 = $200.000')
            ->assertSee('$300.000');

        // Con «algunos equipos», el resumen pide elegirlos (no supone «todos», igual que al guardar).
        Livewire::test(CreateMaintenanceService::class)
            ->fillForm(['client_id' => $this->client->id, 'scope' => 'equipment', 'building_id' => $this->tower->id, 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000])
            ->assertSee('Elegí los equipos para ver el importe.')
            ->assertDontSee('4 equipos × $50.000')
            ->fillForm(['units' => ['Ascensor 1']])
            ->assertSee('1 equipo × $50.000 = $50.000');
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORIAL
    |--------------------------------------------------------------------------
    */

    public function test_changing_the_catalog_never_changes_existing_contracts_or_charges(): void
    {
        $item = $this->item();
        $this->assign(['catalog_item_id' => $item->id, 'description' => 'Mantenimiento por equipo', 'scope' => 'buildings', 'building_ids' => [$this->a['building']->id]])->assertHasNoFormErrors();

        $item->update(['name' => 'Mantenimiento premium', 'base_price' => 99000, 'pricing_mode' => ServiceCatalogItem::FIXED, 'is_active' => false]);

        $service = MaintenanceService::sole();
        $this->assertSame('Mantenimiento por equipo', $service->description);
        $this->assertSame(ServiceCatalogItem::PER_EQUIPMENT, $service->pricing_mode);
        $this->assertEquals([50000, 100000], [(float) $service->unit_price, (float) $service->amount]);
        $this->assertEquals(50000, $service->pricing_detail['catalog_base_price']);
        $this->assertEquals(100000, Receivable::sole()->amount);

        // Los cobros de los meses siguientes también siguen el contrato, no el catálogo.
        $this->travelTo(Carbon::parse('2026-11-05'));
        app(ServiceBillingService::class)->generate($service->fresh());
        $this->assertEquals([100000, 100000], Receivable::orderBy('period_start')->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    public function test_editing_a_contract_recalculates_it_but_keeps_the_charges_already_generated(): void
    {
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->a['building']->id]])->assertHasNoFormErrors();
        $service = MaintenanceService::sole();

        // Sin confirmar el nuevo importe no se guarda (el resumen muestra anterior, nuevo y causa).
        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->assertFormSet(['scope' => 'buildings', 'building_id' => $this->a['building']->id])
            ->fillForm(['unit_price' => 60000])
            ->assertSee('El importe cambia: $100.000 → $120.000')
            ->assertSee('cambió el precio ($50.000 → $60.000)')
            ->call('save')
            ->assertHasFormErrors(['confirm_amount_change']);
        $this->assertEquals(100000, $service->fresh()->amount);

        // Como en pantalla: primero se ve el importe nuevo y recién después se confirma.
        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['unit_price' => 60000])
            ->fillForm(['confirm_amount_change' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(120000, $service->fresh()->amount);
        $this->assertEquals(100000, Receivable::sole()->amount);
    }

    public function test_pausing_and_finishing_from_the_client_keep_history(): void
    {
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->a['building']->id]])->assertHasNoFormErrors();
        $service = MaintenanceService::sole();
        $visit = BuildingVisit::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'visited_at' => now()]);
        $this->assertSame($service->id, $visit->maintenance_service_id);

        $relation = Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class]);
        $relation->callTableAction('set_paused', $service);
        $this->assertSame(MaintenanceService::PAUSED, $service->fresh()->status);
        $relation->callTableAction('set_active', $service);
        $relation->callTableAction('finish', $service);

        $service->refresh();
        $this->assertSame(MaintenanceService::FINISHED, $service->status);
        $this->assertSame('2026-10-15', $service->end_date->toDateString());
        $this->assertSame(1, Receivable::count());
        $this->assertSame($service->id, $visit->fresh()->maintenance_service_id);

        // Finalizado: ya no genera cobros ni impide una nueva contratación del mismo servicio.
        $this->travelTo(Carbon::parse('2026-11-05'));
        $this->assertSame(0, app(ServiceBillingService::class)->generate($service));
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->a['building']->id], 'start_date' => '2026-11-01'])->assertHasNoFormErrors();
    }

    /*
    |--------------------------------------------------------------------------
    | DESDE LA FICHA DEL CLIENTE Y DEL EDIFICIO
    |--------------------------------------------------------------------------
    */

    public function test_adding_a_service_from_the_client_page(): void
    {
        $item = $this->item();

        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertSee('Servicios contratados')
            ->callTableAction('create', data: [
                'catalog_item_id' => $item->id, 'description' => 'Mantenimiento por equipo', 'category' => 'maintenance',
                'scope' => 'buildings', 'building_ids' => [$this->tower->id], 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000,
                'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ])
            ->assertHasNoTableActionErrors();

        $service = MaintenanceService::sole();
        $this->assertSame([$this->client->id, $this->tower->id, 200000.0], [$service->client_id, $service->building_id, (float) $service->amount]);

        // La misma contratación otra vez: se avisa en el formulario y no se duplica.
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->callTableAction('create', data: [
                'catalog_item_id' => $item->id, 'description' => 'Mantenimiento por equipo', 'category' => 'maintenance',
                'scope' => 'buildings', 'building_ids' => [$this->tower->id], 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000,
                'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ])
            ->assertHasTableActionErrors(['catalog_item_id']);
        $this->assertSame(1, MaintenanceService::count());
    }

    public function test_adding_a_service_from_the_building_page_uses_that_building(): void
    {
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->tower, 'pageClass' => EditBuilding::class])
            ->callTableAction('create', data: [
                'description' => 'Mantenimiento de montacargas', 'category' => 'maintenance', 'scope' => 'equipment', 'units' => ['Montacargas 1'],
                'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 70000,
                'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ])
            ->assertHasNoTableActionErrors();

        $service = MaintenanceService::sole();
        $this->assertSame([$this->client->id, $this->tower->id, ['Montacargas 1'], 70000.0], [$service->client_id, $service->building_id, $service->units, (float) $service->amount]);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDACIÓN Y DUPLICADOS
    |--------------------------------------------------------------------------
    */

    public function test_quantities_and_prices_are_validated(): void
    {
        $this->assign(['scope' => 'client', 'unit_price' => 0])->assertHasFormErrors(['unit_price']);
        $this->assign(['scope' => 'client', 'unit_price' => -10])->assertHasFormErrors(['unit_price']);
        $this->assign(['scope' => 'equipment', 'building_id' => $this->tower->id, 'units' => []])->assertHasFormErrors(['units']);
        $this->assign(['scope' => 'buildings', 'building_ids' => []])->assertHasFormErrors(['building_ids']);

        // Un edificio de otro cliente (de la misma empresa) no se acepta.
        $other = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->assign(['scope' => 'equipment', 'building_id' => $other->id, 'units' => ['Ascensor 1']])->assertHasFormErrors(['building_id']);
        $this->assertSame(0, MaintenanceService::count());
    }

    public function test_duplicates_are_prevented_but_different_equipment_is_not_a_duplicate(): void
    {
        $item = $this->item();
        $this->assign(['catalog_item_id' => $item->id, 'scope' => 'equipment', 'building_id' => $this->tower->id, 'units' => ['Ascensor 1']])->assertHasNoFormErrors();

        $this->assign(['catalog_item_id' => $item->id, 'scope' => 'equipment', 'building_id' => $this->tower->id, 'units' => ['Ascensor 1', 'Ascensor 2']])
            ->assertHasFormErrors(['catalog_item_id']);
        $this->assign(['catalog_item_id' => $item->id, 'scope' => 'equipment', 'building_id' => $this->tower->id, 'units' => ['Ascensor 3']])
            ->assertHasNoFormErrors();
        // Sin catálogo, el mismo nombre en el mismo alcance también es repetido.
        $this->assign(['scope' => 'client', 'description' => 'Guardia', 'category' => 'guard'])->assertHasNoFormErrors();
        $this->assign(['scope' => 'client', 'description' => 'Guardia', 'category' => 'guard'])->assertHasFormErrors(['catalog_item_id']);

        $this->assertSame(3, MaintenanceService::count());
    }

    /*
    |--------------------------------------------------------------------------
    | INTEGRACIONES
    |--------------------------------------------------------------------------
    */

    public function test_only_maintenance_and_inspection_contracts_cover_visits_and_existing_ones_are_kept(): void
    {
        // Contrato anterior al catálogo (sin categoría) con su visita y su remito.
        $legacy = MaintenanceService::create(['client_id' => $this->client->id, 'building_id' => $this->a['building']->id, 'description' => 'Mantenimiento', 'amount' => 90000, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);
        $visit = BuildingVisit::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'assignment_type' => 'maintenance', 'month' => 9, 'year' => 2026, 'visited_at' => '2026-09-10']);
        $note = DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'building_visit_id' => $visit->id, 'month' => 9, 'year' => 2026]);
        $this->assertSame($legacy->id, $visit->maintenance_service_id);

        // Una guardia en otro edificio no cubre visitas allí.
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id], 'description' => 'Guardia', 'category' => 'guard', 'pricing_mode' => ServiceCatalogItem::FIXED, 'unit_price' => 30000])->assertHasNoFormErrors();
        $this->assertNull(MaintenanceService::covering($this->a['company']->id, $this->tower->id, now()));
        $this->assertSame($legacy->id, MaintenanceService::covering($this->a['company']->id, $this->a['building']->id, now())?->id);

        // Una inspección sí.
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id], 'description' => 'Inspección', 'category' => 'inspection', 'pricing_mode' => ServiceCatalogItem::FIXED, 'unit_price' => 20000])->assertHasNoFormErrors();
        $this->assertSame('inspection', MaintenanceService::covering($this->a['company']->id, $this->tower->id, now())?->category);

        // Lo existente no cambió.
        $this->assertSame($legacy->id, $visit->fresh()->maintenance_service_id);
        $this->assertSame($visit->id, $note->fresh()->building_visit_id);
        $this->assertEquals(90000, $legacy->fresh()->amount);
    }

    public function test_the_agenda_only_asks_for_visits_on_maintenance_contracts(): void
    {
        $towerRow = fn () => app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance')->firstWhere('building_id', $this->tower->id);

        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id], 'description' => 'Guardia', 'category' => 'guard', 'pricing_mode' => ServiceCatalogItem::FIXED, 'unit_price' => 30000])->assertHasNoFormErrors();
        $this->assertNull($towerRow());

        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id]])->assertHasNoFormErrors();
        $this->assertNotNull($towerRow());
        // Una sola fila por edificio aunque tenga dos contratos.
        $this->assertSame(1, app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance')->where('building_id', $this->tower->id)->count());
    }

    public function test_a_one_time_service_is_billed_once(): void
    {
        $this->assign(['scope' => 'buildings', 'building_ids' => [$this->tower->id], 'description' => 'Prueba de paracaídas', 'category' => 'parachute', 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 25000, 'frequency' => 'once'])->assertHasNoFormErrors();

        $service = MaintenanceService::sole();
        $this->assertSame('$100.000 (pago único)', $service->amountLabel());
        $this->assertSame(1, Receivable::count());

        $this->travelTo(Carbon::parse('2027-02-10'));
        app(ServiceBillingService::class)->generateAll();
        $this->assertSame(1, Receivable::count());
    }

    public function test_a_quote_can_take_an_item_from_the_catalog(): void
    {
        $item = $this->item(['description' => 'Visita mensual y lubricación']);

        $component = Livewire::test(CreateQuote::class)
            ->fillForm(['client_id' => $this->client->id, 'building_id' => $this->tower->id])
            ->set('data.catalog_pick', (string) $item->id);

        $rows = array_values(array_filter($component->get('data.items'), fn ($row) => filled($row['concept'] ?? null)));
        $this->assertCount(1, $rows);
        $this->assertSame(['Mantenimiento por equipo', 'Visita mensual y lubricación', 4], [$rows[0]['concept'], $rows[0]['description'], $rows[0]['quantity']]);
        $this->assertEquals(50000, $rows[0]['unit_price']);
        $this->assertNull($component->get('data.catalog_pick'));
        // Elegirlo no crea contrataciones.
        $this->assertSame(0, MaintenanceService::count());
    }

    /*
    |--------------------------------------------------------------------------
    | AISLAMIENTO Y PERMISOS
    |--------------------------------------------------------------------------
    */

    public function test_companies_never_see_or_use_each_others_catalog_or_buildings(): void
    {
        // La otra empresa se arma sin sesión (si no, sus datos quedarían en la empresa A).
        auth()->logout();
        $b = $this->makeTenant();
        $this->assertNotSame($this->a['company']->id, $b['building']->company_id);
        $this->actingInPanel($b['admin']);
        $foreignItem = ServiceCatalogItem::create(['name' => 'Ajeno', 'category' => 'maintenance', 'pricing_mode' => ServiceCatalogItem::FIXED, 'base_price' => 1, 'billing_frequency' => 'monthly', 'is_active' => true]);
        $this->actingInPanel($this->a['admin']);
        $this->assertSame($b['company']->id, $foreignItem->company_id);

        // El nombre es único por empresa: el mismo nombre que usa otra empresa se acepta.
        Livewire::test(CreateServiceCatalogItem::class)
            ->fillForm(['name' => 'Ajeno', 'category' => 'guard', 'pricing_mode' => ServiceCatalogItem::FIXED, 'base_price' => 70000, 'billing_frequency' => 'monthly'])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(ListServiceCatalogItems::class)
            ->assertCanSeeTableRecords(ServiceCatalogItem::all())
            ->assertCanNotSeeTableRecords([$foreignItem]);

        // Un catálogo ajeno en el pedido no se acepta como opción.
        $this->assign(['catalog_item_id' => $foreignItem->id, 'scope' => 'client'])->assertHasFormErrors(['catalog_item_id']);
        // Ni un edificio ajeno.
        $this->assign(['scope' => 'equipment', 'building_id' => $b['building']->id, 'units' => ['Ascensor 1']])->assertHasFormErrors(['building_id']);
        $this->assertSame(0, MaintenanceService::withoutGlobalScopes()->count());

        // El cálculo tampoco cuenta equipos de otra empresa.
        $pricing = app(ServicePricing::class)->calculate($this->a['company']->id, ServiceCatalogItem::PER_EQUIPMENT, 1000, (int) $b['building']->client_id, $b['building']->id);
        $this->assertNotNull($pricing['error']);

        $this->get(ServiceCatalogItemResource::getUrl('edit', ['record' => $foreignItem]))->assertNotFound();
    }

    public function test_permissions_for_the_catalog_and_the_client_services_tab(): void
    {
        // Administrativo de cobranzas con «ver»: ve el catálogo, no lo cambia ni agrega servicios.
        $viewer = $this->staff(['clients' => 'view', 'receivables' => 'view'], 'Mira cobros');
        $this->flushSession();
        $this->actingInPanel($viewer);
        $this->get(ServiceCatalogItemResource::getUrl('index'))->assertOk();
        $this->assertFalse(ServiceCatalogItemResource::canCreate());
        $this->get(ServiceCatalogItemResource::getUrl('create'))->assertForbidden();
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertDontSee('Agregar servicio');

        // Con «gestionar»: sí.
        $manager = $this->staff(['clients' => 'view', 'receivables' => 'manage'], 'Gestiona cobros');
        $this->flushSession();
        $this->actingInPanel($manager);
        $this->assertTrue(ServiceCatalogItemResource::canCreate());
        $this->assertTrue(MaintenanceServiceResource::canCreate());

        // El técnico no entra (vuelve a su inicio).
        $this->flushSession();
        $this->actingAs($this->a['technician'])->get(ServiceCatalogItemResource::getUrl('index'))
            ->assertRedirect(route('dashboard', ['company' => $this->a['company']->slug]));
        $this->assertFalse(ServiceCatalogItemResource::canAccess());
    }
}
