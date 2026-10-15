<?php

namespace Tests\Feature\Billing;

use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\ListMaintenanceServices;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\ServiceCatalogItem;
use App\Services\Billing\ReceivableService;
use App\Services\Billing\ServiceBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Estado del cobro de los servicios de pago único (informativo, con enlace),
 * cobros que nacen vencidos al reactivar y próximo cobro coherente con el
 * ciclo, y nueva confirmación cuando cambia un dato que modifica el importe.
 */
class ServiceChargeStatusTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    private Building $tower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
        $this->tower = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id, 'name' => 'Torre Oeste', 'elevator_count' => 3]);
        $this->actingInPanel($this->a['admin']);
    }

    private function service(array $attributes = []): MaintenanceService
    {
        return MaintenanceService::create([
            'client_id' => $this->client->id, 'building_id' => $this->tower->id, 'description' => 'Prueba de paracaídas', 'category' => 'parachute',
            'amount' => 90000, 'frequency' => 'once', 'start_date' => '2026-10-01', 'payment_due_day' => 20, 'status' => 'active',
            ...$attributes,
        ]);
    }

    private function listsShow(MaintenanceService $service, string $label): void
    {
        Livewire::test(ListMaintenanceServices::class)->assertTableColumnStateSet('one_time_charge', $label, $service);
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertTableColumnStateSet('one_time_charge', $label, $service);
        $this->get(MaintenanceServiceResource::getUrl('view', ['record' => $service]))->assertOk()->assertSee('Cobro del pago único')->assertSee($label);
    }

    /*
    |--------------------------------------------------------------------------
    | PAGO ÚNICO: ESTADO DEL COBRO
    |--------------------------------------------------------------------------
    */

    public function test_one_time_services_show_their_charge_as_pending_paid_or_overdue_with_a_link(): void
    {
        $service = $this->service();
        $charge = Receivable::sole();

        $this->listsShow($service, 'Pendiente');
        $this->get(MaintenanceServiceResource::getUrl('view', ['record' => $service]))
            ->assertSee(ReceivableResource::getUrl('view', ['record' => $charge]), false)
            ->assertSee('Vence el 20/10/2026');

        // Vencido: lo calcula el cobro por su fecha.
        $this->travelTo(Carbon::parse('2026-10-25'));
        $this->listsShow($service, 'Vencida');

        // Pagado.
        app(ReceivableService::class)->registerPayment($charge, 90000, today(), 'cash', null, $this->a['admin']);
        $this->listsShow($service, 'Pagada');

        // Nada de esto cambia el contrato ni crea cobros.
        $this->assertSame(MaintenanceService::ACTIVE, $service->fresh()->status);
        $this->assertSame(1, Receivable::count());
    }

    public function test_without_a_charge_no_status_is_invented_and_nothing_is_generated(): void
    {
        $future = $this->service(['start_date' => '2027-03-01']); // su cobro aún no corresponde
        $monthly = $this->service(['description' => 'Abono', 'category' => 'maintenance', 'frequency' => 'monthly', 'start_date' => '2026-10-01']);
        $this->assertSame(0, Receivable::where('maintenance_service_id', $future->id)->count());

        Livewire::test(ListMaintenanceServices::class)
            ->assertTableColumnStateNotSet('one_time_charge', 'Pendiente', $future)
            ->assertTableColumnStateNotSet('one_time_charge', 'Pendiente', $monthly);
        $this->assertNull($future->oneTimeCharge());
        $this->assertNull($monthly->oneTimeCharge()); // un servicio mensual no muestra este estado
        $this->get(MaintenanceServiceResource::getUrl('view', ['record' => $future]))->assertOk()->assertDontSee('Cobro del pago único');

        $this->assertSame(0, Receivable::where('maintenance_service_id', $future->id)->count());
        $this->assertSame(MaintenanceService::ACTIVE, $future->fresh()->status);
    }

    public function test_the_charge_status_follows_the_charge_and_ignores_the_contract_status(): void
    {
        $service = $this->service();
        $charge = Receivable::sole();
        app(ReceivableService::class)->registerPayment($charge, 30000, today(), 'cash', null, $this->a['admin']);

        $service->update(['status' => MaintenanceService::FINISHED, 'end_date' => today()]);
        $this->listsShow($service->fresh(), 'Parcialmente pagada');
        $this->assertEquals([90000.0, 30000.0], [(float) $charge->fresh()->amount, (float) $charge->fresh()->paid_amount]);
    }

    /*
    |--------------------------------------------------------------------------
    | REACTIVACIÓN (alternativa A): VENCIDOS Y PRÓXIMO COBRO
    |--------------------------------------------------------------------------
    */

    public function test_a_charge_born_after_its_due_day_is_identified_as_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $service = $this->service(['description' => 'Abono semestral', 'category' => 'maintenance', 'frequency' => 'semiannual', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'amount' => 600000]);
        $this->travelTo(Carbon::parse('2026-02-10'));
        $service->update(['status' => MaintenanceService::PAUSED]);

        $this->travelTo(Carbon::parse('2026-07-15')); // vuelve en el mes de un período, después del día 10
        $service->update(['status' => MaintenanceService::ACTIVE]);

        $july = Receivable::whereDate('period_start', '2026-07-01')->sole();
        $this->assertSame('2026-07-10', $july->due_date->toDateString());
        $this->assertSame('overdue', $july->displayStatus());
        $this->assertSame('Vencida', $july->displayStatusLabel());
        $this->assertTrue(Receivable::overdue()->whereKey($july->id)->exists());
    }

    public function test_after_a_mid_cycle_reactivation_the_next_charge_is_the_next_cycle(): void
    {
        $billing = app(ServiceBillingService::class);
        $this->travelTo(Carbon::parse('2026-01-05'));
        $service = $this->service(['description' => 'Abono trimestral', 'category' => 'maintenance', 'frequency' => 'quarterly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'amount' => 300000]);
        $this->travelTo(Carbon::parse('2026-02-10'));
        $service->update(['status' => MaintenanceService::PAUSED]);

        // Vuelve en mayo (a mitad de abril–junio): ese período no se cobra.
        $this->travelTo(Carbon::parse('2026-05-10'));
        $service->update(['status' => MaintenanceService::ACTIVE]);
        $this->assertSame(['2026-01'], Receivable::orderBy('period_start')->pluck('period_start')->map->format('Y-m')->all());

        // Próximo cobro: el ciclo original (julio), con vencimiento el día pactado.
        $next = collect($billing->periodsFor($service->fresh(), Carbon::parse('2027-12-01')))->first(fn ($p) => $p->gt(now()));
        $this->assertSame('2026-07-01', $next->toDateString());
        $this->travelTo(Carbon::parse('2026-07-02'));
        $billing->generateAll();
        $julyCharge = Receivable::whereDate('period_start', '2026-07-01')->sole();
        $this->assertSame('2026-07-10', $julyCharge->due_date->toDateString());
        $this->assertSame('Pendiente', $julyCharge->displayStatusLabel());
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIRMACIÓN: CORRESPONDE AL IMPORTE QUE SE GUARDA
    |--------------------------------------------------------------------------
    */

    public function test_changing_any_amount_field_after_confirming_requires_a_new_confirmation(): void
    {
        Livewire::test(CreateMaintenanceService::class)->fillForm([
            'client_id' => $this->client->id, 'description' => 'Mantenimiento', 'category' => 'maintenance', 'scope' => 'buildings', 'building_ids' => [$this->tower->id],
            'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000, 'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
        ])->call('create')->assertHasNoFormErrors();
        $service = MaintenanceService::latest('id')->first(); // $150.000

        $form = Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['unit_price' => 60000])
            ->fillForm(['confirm_amount_change' => true]) // confirma $180.000
            ->fillForm(['scope' => 'equipment', 'units' => ['Ascensor 1']]) // ahora $60.000
            ->assertFormSet(['confirm_amount_change' => false])
            ->assertSee('El importe cambia: $150.000 → $60.000');
        $form->call('save')->assertHasFormErrors(['confirm_amount_change']);
        $this->assertEquals(150000, $service->fresh()->amount);

        // Volver a un dato que deja el importe como estaba: no hay cambio y no hace falta confirmar.
        $form->fillForm(['scope' => 'buildings', 'unit_price' => 50000])->assertDontSee('El importe cambia')->call('save')->assertHasNoFormErrors();
        $this->assertEquals(150000, $service->fresh()->amount);
    }

    public function test_the_client_tab_creation_also_saves_only_the_amount_shown(): void
    {
        $relation = Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->mountTableAction('create')
            ->setTableActionData([
                'description' => 'Mantenimiento', 'category' => 'maintenance', 'scope' => 'buildings', 'building_ids' => [$this->tower->id],
                'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000, 'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ]);
        // El resumen del modal registra el importe a la vista.
        $this->assertEquals(150000, $relation->get('mountedActions.0.data.shown_total'));
        $this->tower->update(['elevator_count' => 1]);

        $relation->callMountedTableAction()->assertHasTableActionErrors(['unit_price']);
        $this->assertSame(0, MaintenanceService::count());
        $relation->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertEquals(50000, MaintenanceService::sole()->amount);
    }
}
