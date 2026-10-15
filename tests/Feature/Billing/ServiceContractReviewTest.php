<?php

namespace Tests\Feature\Billing;

use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Schemas\ServiceAssignmentForm;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\ServiceCatalogItem;
use App\Services\Billing\ServiceBillingService;
use App\Services\Insights\Indicators;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Revisión del módulo de servicios: superposición con contratos históricos,
 * cambios de importe al editar, pago único, pausa y reactivación, visitas y
 * remitos, y manipulación de solicitudes.
 */
class ServiceContractReviewTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    /** Segundo edificio del cliente, sin técnico: 2 ascensores y 1 montacargas. */
    private Building $tower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->b = $this->makeTenant(); // sin sesión: sus datos quedan en su empresa
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
        $this->tower = Building::factory()->create([
            'company_id' => $this->a['company']->id, 'client_id' => $this->client->id,
            'name' => 'Torre Sur', 'elevator_count' => 2, 'freight_elevator_count' => 1,
        ]);
        $this->actingInPanel($this->a['admin']);
    }

    /** Contrato anterior al catálogo: sin tipo, sin modalidad, sin catálogo. */
    private function legacy(Building $building, array $attributes = []): MaintenanceService
    {
        return MaintenanceService::create([
            'client_id' => $this->client->id, 'building_id' => $building->id, 'description' => 'Abono mensual',
            'amount' => 180000, 'frequency' => 'monthly', 'start_date' => '2026-05-01', 'payment_due_day' => 10, 'status' => 'active',
            ...$attributes,
        ]);
    }

    private function form(array $data = [])
    {
        return Livewire::test(CreateMaintenanceService::class)->fillForm([
            'client_id' => $this->client->id, 'description' => 'Mantenimiento por equipo', 'category' => 'maintenance',
            'scope' => 'buildings', 'building_ids' => [$this->tower->id], 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT,
            'unit_price' => 50000, 'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ...$data,
        ]);
    }

    private function visit(Building $building, int $month): BuildingVisit
    {
        return BuildingVisit::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $building->id, 'user_id' => $this->a['technician']->id, 'assignment_type' => 'maintenance', 'month' => $month, 'year' => 2026, 'visited_at' => Carbon::create(2026, $month, 10)]);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. SUPERPOSICIÓN CON CONTRATOS EXISTENTES (aviso, no bloqueo)
    |--------------------------------------------------------------------------
    */

    public function test_a_legacy_maintenance_contract_is_warned_about_but_does_not_block(): void
    {
        $legacy = $this->legacy($this->tower);

        $this->form()
            ->assertSeeHtml('data-overlap-warning')
            ->assertSee('Ya hay una contratación activa de este tipo en este alcance')
            ->assertSee('«Abono mensual»')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(2, MaintenanceService::where('building_id', $this->tower->id)->count());

        // Una visita se vincula a una sola contratación y la agenda pide una sola visita por edificio.
        $visit = $this->visit($this->tower, 10);
        $this->assertSame(1, BuildingVisit::where('building_id', $this->tower->id)->count());
        $this->assertContains($visit->maintenance_service_id, MaintenanceService::where('building_id', $this->tower->id)->pluck('id')->all());
        $this->assertSame(1, app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance')->where('building_id', $this->tower->id)->count());
        // El contrato anterior y su cobro siguen iguales.
        $this->assertEquals(180000, $legacy->fresh()->amount);
        $this->assertEquals(180000, Receivable::where('maintenance_service_id', $legacy->id)->sole()->amount);
    }

    public function test_no_warning_for_services_that_do_not_overlap(): void
    {
        $this->legacy($this->tower, ['units' => ['Montacargas 1'], 'description' => 'Montacargas']);

        // Otros equipos del mismo edificio: no se superponen.
        $this->form(['scope' => 'equipment', 'building_id' => $this->tower->id, 'units' => ['Ascensor 1', 'Ascensor 2']])
            ->assertDontSeeHtml('data-overlap-warning');
        // Una guardia no cubre visitas: no hay aviso.
        $this->form(['category' => 'guard', 'pricing_mode' => ServiceCatalogItem::FIXED])
            ->assertDontSeeHtml('data-overlap-warning');
        // Otro edificio del cliente: tampoco.
        $this->form(['building_ids' => [$this->a['building']->id]])
            ->assertDontSeeHtml('data-overlap-warning');

        // Solo ascensores frente a solo montacargas (por tipo de equipo) no se superponen.
        $this->form(['building_ids' => [$this->a['building']->id], 'equipment_kind' => 'elevator'])->call('create')->assertHasNoFormErrors();
        $this->assertEmpty(ServiceAssignmentForm::overlapping($this->client->id, $this->a['building']->id, null, 'maintenance', 'freight'));
        $this->assertCount(1, ServiceAssignmentForm::overlapping($this->client->id, $this->a['building']->id, null, 'maintenance', 'all'));
    }

    public function test_a_client_wide_contract_counts_as_overlapping_for_each_building(): void
    {
        $this->legacy($this->tower, ['building_id' => null, 'description' => 'Abono general']);

        $this->form(['building_ids' => [$this->a['building']->id]])->assertSee('«Abono general»');
        $this->assertCount(1, ServiceAssignmentForm::overlapping($this->client->id, $this->tower->id, null, 'inspection', 'all'));
    }

    /*
    |--------------------------------------------------------------------------
    | 2. EDICIÓN Y CAMBIO DE IMPORTE
    |--------------------------------------------------------------------------
    */

    public function test_fewer_active_equipment_requires_confirming_the_new_amount_even_when_editing_only_the_name(): void
    {
        $this->form()->call('create')->assertHasNoFormErrors(); // 3 equipos × $50.000
        $service = MaintenanceService::sole();
        $this->assertEquals(150000, $service->amount);

        $this->tower->update(['freight_elevator_count' => 0]); // queda con 2 equipos activos

        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['description' => 'Mantenimiento (renombrado)'])
            ->assertSee('El importe cambia: $150.000 → $100.000')
            ->assertSee('cambió la cantidad de equipos activos (de 3 a 2)')
            ->assertFormFieldIsVisible('confirm_amount_change')
            ->call('save')
            ->assertHasFormErrors(['confirm_amount_change']);
        $this->assertSame(['Mantenimiento por equipo', 150000.0], [$service->fresh()->description, (float) $service->fresh()->amount]);

        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['description' => 'Mantenimiento (renombrado)', 'confirm_amount_change' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertEquals(100000, $service->fresh()->amount);
        $this->assertSame(2, $service->fresh()->quantity);

        // El cobro de octubre, ya generado, no se recalcula.
        $this->assertEquals(150000, Receivable::sole()->amount);
        $this->travelTo(Carbon::parse('2026-11-05'));
        app(ServiceBillingService::class)->generate($service->fresh());
        $this->assertEquals([150000.0, 100000.0], Receivable::orderBy('period_start')->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    public function test_changed_stops_are_shown_as_the_cause(): void
    {
        Elevator::where('building_id', $this->tower->id)->update(['stops' => 5]);
        $this->form(['pricing_mode' => ServiceCatalogItem::PER_STOP, 'unit_price' => 4000])->call('create')->assertHasNoFormErrors(); // 15 paradas
        $service = MaintenanceService::sole();
        $this->assertEquals(60000, $service->amount);

        Elevator::where('building_id', $this->tower->id)->where('kind', 'freight')->update(['stops' => 8]);

        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->assertSee('El importe cambia: $60.000 → $72.000')
            ->assertSee('cambió la cantidad de paradas cargadas (de 15 a 18)')
            ->call('save')
            ->assertHasFormErrors(['confirm_amount_change']);
        $this->assertEquals(60000, $service->fresh()->amount);
    }

    public function test_fixed_price_and_unrelated_edits_do_not_change_the_amount(): void
    {
        $this->form(['pricing_mode' => ServiceCatalogItem::FIXED, 'unit_price' => 90000])->call('create')->assertHasNoFormErrors();
        $service = MaintenanceService::sole();
        $this->tower->update(['elevator_count' => 5]);

        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['description' => 'Otro nombre', 'notes' => 'Llaves en portería', 'payment_due_day' => 15])
            ->assertDontSee('El importe cambia')
            ->assertFormFieldIsHidden('confirm_amount_change')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['Otro nombre', 90000.0], [$service->fresh()->description, (float) $service->fresh()->amount]);
    }

    public function test_a_legacy_contract_can_be_edited_without_economic_changes(): void
    {
        $legacy = $this->legacy($this->tower, ['frequency' => 'quarterly', 'amount' => 77777]);

        Livewire::test(EditMaintenanceService::class, ['record' => $legacy->getRouteKey()])
            ->assertFormSet(['category' => 'maintenance', 'pricing_mode' => ServiceCatalogItem::CUSTOM, 'unit_price' => 77777.0, 'scope' => 'buildings'])
            ->fillForm(['description' => 'Abono trimestral'])
            ->assertDontSee('El importe cambia')
            ->call('save')
            ->assertHasNoFormErrors();

        $legacy->refresh();
        $this->assertSame(['Abono trimestral', 77777.0, 'quarterly', 'maintenance'], [$legacy->description, (float) $legacy->amount, $legacy->frequency, $legacy->category]);
        $this->assertSame($legacy->id, MaintenanceService::covering($this->a['company']->id, $this->tower->id, now())?->id);
    }

    public function test_the_client_tab_also_asks_for_confirmation_and_the_server_enforces_it(): void
    {
        $this->form()->call('create')->assertHasNoFormErrors();
        $service = MaintenanceService::sole();
        $this->tower->update(['elevator_count' => 1]);

        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->callTableAction('edit', $service, data: ['description' => 'Cambio de nombre'])
            ->assertHasTableActionErrors(['confirm_amount_change']);
        $this->assertEquals(150000, $service->fresh()->amount);

        // Aunque se salte el formulario (solicitud manipulada), el servidor lo exige.
        try {
            ServiceAssignmentForm::prepareUpdate(['client_id' => $this->client->id, 'description' => 'X', 'scope' => 'buildings', 'building_id' => $this->tower->id, 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'unit_price' => 50000, 'status' => 'active'], $service);
            $this->fail('Se guardó un importe nuevo sin confirmarlo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.confirm_amount_change', $e->errors());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 3. PAGO ÚNICO, PAUSA Y REACTIVACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_a_one_time_service_is_billed_once_through_pause_reactivation_and_finish(): void
    {
        $this->form(['description' => 'Prueba de paracaídas', 'category' => 'parachute', 'frequency' => 'once', 'unit_price' => 30000])->call('create')->assertHasNoFormErrors();
        $service = MaintenanceService::sole();
        $receivable = Receivable::sole();
        $this->assertEquals(90000, $receivable->amount);

        $service->update(['status' => MaintenanceService::PAUSED]);
        $this->travelTo(Carbon::parse('2027-01-10'));
        $service->update(['status' => MaintenanceService::ACTIVE]);
        app(ServiceBillingService::class)->generateAll();
        $service->update(['start_date' => '2027-01-01']);
        $this->assertSame(1, Receivable::count());

        // Finalizar el contrato no toca su cobro (ni su estado ni su importe).
        $before = $receivable->fresh()->only(['amount', 'status', 'period_start', 'due_date']);
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->callTableAction('finish', $service);
        $this->assertSame(MaintenanceService::FINISHED, $service->fresh()->status);
        $this->assertEquals($before, $receivable->fresh()->only(['amount', 'status', 'period_start', 'due_date']));
    }

    public function test_reactivating_does_not_bill_the_months_it_was_paused(): void
    {
        $service = $this->legacy($this->tower, ['start_date' => '2026-10-01']);
        $this->assertSame(['2026-10'], Receivable::orderBy('period_start')->pluck('period_start')->map->format('Y-m')->all());

        $service->update(['status' => MaintenanceService::PAUSED]);
        $this->travelTo(Carbon::parse('2027-01-05'));
        app(ServiceBillingService::class)->generateAll(); // pausado: nada
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->callTableAction('set_active', $service);

        $this->assertSame(['2026-10', '2027-01'], Receivable::orderBy('period_start')->pluck('period_start')->map->format('Y-m')->all());
        $this->travelTo(Carbon::parse('2027-02-05'));
        app(ServiceBillingService::class)->generateAll();
        $this->assertSame(['2026-10', '2027-01', '2027-02'], Receivable::orderBy('period_start')->pluck('period_start')->map->format('Y-m')->all());
    }

    public function test_one_time_services_are_not_monthly_portfolio_income(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->legacy($this->tower, ['amount' => 120000]);
        $this->form(['description' => 'Prueba de paracaídas', 'category' => 'parachute', 'frequency' => 'once', 'unit_price' => 30000])->call('create')->assertHasNoFormErrors();

        $portfolio = collect(app(Indicators::class)->sections($this->a['company'])['advanced']['portfolio'])->keyBy('question');
        $this->assertSame('$120.000', $portfolio['¿Cuánto factura la cartera por mes?']['value']);
        $this->assertStringStartsWith('1 contratos activos', $portfolio['¿Cuánto factura la cartera por mes?']['context']);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. VISITAS, AGENDA Y REMITOS
    |--------------------------------------------------------------------------
    */

    public function test_only_visit_types_create_maintenance_obligations_and_billing_frequency_is_independent(): void
    {
        $row = fn (int $month) => app(MaintenanceAgenda::class)->rows($month, 2026, 'maintenance')->firstWhere('building_id', $this->tower->id);

        foreach (['guard', 'cleaning', 'extra', 'parachute', 'other'] as $category) {
            $this->form(['description' => $category, 'category' => $category, 'pricing_mode' => ServiceCatalogItem::FIXED, 'unit_price' => 1000])->call('create')->assertHasNoFormErrors();
        }
        $this->assertNull($row(10));
        $this->assertNull(MaintenanceService::covering($this->a['company']->id, $this->tower->id, now()));
        $this->assertNull($this->visit($this->tower, 10)->maintenance_service_id);

        // Un contrato histórico (sin tipo) cobrado trimestralmente: la agenda pide la visita todos los meses
        // (desde noviembre: la agenda memoriza los contratos de octubre dentro de esta misma solicitud).
        $legacy = $this->legacy($this->tower, ['frequency' => 'quarterly', 'start_date' => '2026-10-01']);
        $this->assertNotNull($row(11));
        $this->assertNotNull($row(12));
        $this->assertSame($legacy->id, MaintenanceService::covering($this->a['company']->id, $this->tower->id, now())?->id);
    }

    public function test_pausing_or_finishing_never_touches_visits_or_delivery_notes(): void
    {
        $service = $this->legacy($this->tower);
        $visit = $this->visit($this->tower, 9);
        $note = DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->tower->id, 'building_visit_id' => $visit->id, 'month' => 9, 'year' => 2026]);
        $snapshot = fn () => [$visit->fresh()->toArray(), $note->fresh()->toArray()];
        $before = $snapshot();

        $relation = Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class]);
        $relation->callTableAction('set_paused', $service);
        $relation->callTableAction('set_active', $service);
        $relation->callTableAction('finish', $service);

        $this->assertEquals($before, $snapshot());
        $this->assertSame($service->id, $visit->fresh()->maintenance_service_id);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. PERMISOS Y MANIPULACIÓN DE SOLICITUDES
    |--------------------------------------------------------------------------
    */

    public function test_requests_cannot_reach_another_company_or_override_the_calculation(): void
    {
        // Cliente o edificio de otra empresa.
        $this->form(['client_id' => $this->b['building']->client_id])->call('create')->assertHasFormErrors(['client_id']);
        $this->form(['building_ids' => [$this->b['building']->id]])->call('create')->assertHasFormErrors(['building_ids.0']);
        $this->form(['scope' => 'equipment', 'building_id' => $this->b['building']->id, 'units' => ['Ascensor 1']])->call('create')->assertHasFormErrors(['building_id']);
        $this->assertSame(0, MaintenanceService::withoutGlobalScopes()->count());

        // Importe, cantidad o detalle enviados desde el navegador se ignoran: se recalculan.
        $this->form()->set('data.amount', 1)->set('data.quantity', 99)->call('create')->assertHasNoFormErrors();
        $service = MaintenanceService::sole();
        $this->assertSame([150000.0, 3], [(float) $service->amount, $service->quantity]);

        // Equipos inventados: se rechazan y no se crea nada.
        $this->form(['scope' => 'equipment', 'building_id' => $this->a['building']->id, 'units' => ['Ascensor 1']])
            ->set('data.units', ['Ascensor 1', 'Ascensor 99'])->call('create')->assertHasFormErrors(['units.1']);
        $this->assertSame(0, MaintenanceService::where('building_id', $this->a['building']->id)->count());
    }

    public function test_view_only_staff_cannot_add_edit_pause_or_finish(): void
    {
        $service = $this->legacy($this->tower);
        $viewer = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Solo mira', 'email' => 'solo-mira@demo.test', 'password' => 'clave-segura-123'], ['clients' => 'view', 'receivables' => 'view']);
        $this->flushSession();
        $this->actingInPanel($viewer);

        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $service)
            ->assertTableActionHidden('set_paused', $service)
            ->assertTableActionHidden('finish', $service);
        $this->get(MaintenanceServiceResource::getUrl('edit', ['record' => $service]))->assertForbidden();
        $this->assertSame(MaintenanceService::ACTIVE, $service->fresh()->status);
    }
}
