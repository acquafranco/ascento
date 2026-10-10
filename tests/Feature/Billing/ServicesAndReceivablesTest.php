<?php

namespace Tests\Feature\Billing;

use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\ListMaintenanceServices;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Widgets\ReceivablesOverview;
use App\Models\Building;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Billing\ReceivableService;
use App\Services\Billing\ServiceBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Servicios de mantenimiento, obligaciones de cobro y pagos.
 */
class ServicesAndReceivablesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);
    }

    private function service(array $attributes = []): MaintenanceService
    {
        return MaintenanceService::create([
            'client_id' => $this->a['building']->client_id,
            'building_id' => $this->a['building']->id,
            'description' => 'Mantenimiento mensual de ascensores',
            'amount' => 120000,
            'frequency' => 'monthly',
            'start_date' => '2026-10-01',
            'payment_due_day' => 10,
            'status' => MaintenanceService::ACTIVE,
            ...$attributes,
        ]);
    }

    private function receivable(float $amount = 120000, string $due = '2026-10-20'): Receivable
    {
        return app(ReceivableService::class)->createManual(
            $this->a['building']->client,
            $this->a['building']->id,
            'Reparación de puerta',
            $amount,
            Carbon::parse($due),
            $this->a['admin'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SERVICIOS
    |--------------------------------------------------------------------------
    */

    public function test_admin_creates_a_service_for_a_client_and_building_and_this_month_is_billed(): void
    {
        Livewire::test(CreateMaintenanceService::class)
            ->fillForm([
                'client_id' => $this->a['building']->client_id,
                'building_id' => $this->a['building']->id,
                'description' => 'Mantenimiento mensual de ascensores',
                'amount' => 120000,
                'frequency' => 'monthly',
                'payment_due_day' => 10,
                'start_date' => '2026-10-01',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = MaintenanceService::sole();
        $this->assertSame($this->a['company']->id, $service->company_id);
        $this->assertSame('$120.000 / mes', $service->amountLabel());

        $receivable = Receivable::sole();
        $this->assertSame($service->id, $receivable->maintenance_service_id);
        $this->assertSame('service', $receivable->source);
        $this->assertEquals(120000, $receivable->amount);
        $this->assertSame('2026-10-10', $receivable->due_date->toDateString());
        $this->assertSame('2026-10-01', $receivable->period_start->toDateString());
        $this->assertStringContainsString('Octubre 2026', $receivable->concept);
    }

    public function test_the_building_must_belong_to_the_chosen_client(): void
    {
        $otherBuilding = Building::factory()->create(['company_id' => $this->a['company']->id]); // otro cliente

        Livewire::test(CreateMaintenanceService::class)
            ->fillForm([
                'client_id' => $this->a['building']->client_id,
                'building_id' => $otherBuilding->id,
                'description' => 'Mantenimiento',
                'amount' => 1000,
                'frequency' => 'monthly',
                'payment_due_day' => 10,
                'start_date' => '2026-10-01',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasFormErrors(['building_id']);
    }

    public function test_an_old_start_date_does_not_create_retroactive_debt(): void
    {
        $this->service(['start_date' => '2025-01-01']);

        $this->assertSame(['2026-10-01'], Receivable::pluck('period_start')->map->toDateString()->all());
    }

    public function test_periodic_charges_are_generated_without_duplicates(): void
    {
        $service = $this->service();

        $this->travelTo(Carbon::parse('2026-12-05'));
        $billing = app(ServiceBillingService::class);

        $this->assertSame(2, $billing->generateAll($this->a['company']->id));   // noviembre y diciembre
        $this->assertSame(0, $billing->generateAll($this->a['company']->id));   // nada nuevo
        $this->artisan('billing:generate')->expectsOutputToContain('Obligaciones creadas: 0')->assertSuccessful();

        $this->assertSame(
            ['2026-10-01', '2026-11-01', '2026-12-01'],
            $service->receivables()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all(),
        );
        $this->assertSame(3, Receivable::where('status', Receivable::PENDING)->count());
    }

    public function test_frequency_and_end_date_are_respected(): void
    {
        $quarterly = $this->service(['frequency' => 'quarterly']);
        $ending = $this->service(['description' => 'Contrato corto', 'end_date' => '2026-11-30']);

        $this->travelTo(Carbon::parse('2027-04-10'));
        app(ServiceBillingService::class)->generateAll();

        $this->assertSame(['2026-10-01', '2027-01-01', '2027-04-01'], $quarterly->receivables()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all());
        $this->assertSame(['2026-10-01', '2026-11-01'], $ending->receivables()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all());
    }

    public function test_paused_services_do_not_bill_and_reactivating_resumes(): void
    {
        $service = $this->service();

        Livewire::test(ListMaintenanceServices::class)->callTableAction('set_paused', $service);
        $this->assertSame(MaintenanceService::PAUSED, $service->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-05'));
        $this->assertSame(0, app(ServiceBillingService::class)->generateAll());

        Livewire::test(ListMaintenanceServices::class)->callTableAction('set_active', $service);
        $this->assertSame(MaintenanceService::ACTIVE, $service->fresh()->status);
        $this->assertSame(2, $service->receivables()->count()); // octubre + noviembre
    }

    /*
    |--------------------------------------------------------------------------
    | OBLIGACIONES Y PAGOS
    |--------------------------------------------------------------------------
    */

    public function test_manual_receivable_and_overdue_status(): void
    {
        Livewire::test(ListReceivables::class)
            ->callAction('createManual', data: [
                'client_id' => $this->a['building']->client_id,
                'building_id' => $this->a['building']->id,
                'concept' => 'Reparación de puerta',
                'amount' => 50000,
                'due_date' => '2026-10-20',
            ])
            ->assertHasNoActionErrors();

        $receivable = Receivable::sole();
        $this->assertSame('manual', $receivable->source);
        $this->assertSame('pending', $receivable->displayStatus());

        $this->travelTo(Carbon::parse('2026-10-21'));
        $receivable->refresh();

        $this->assertTrue($receivable->isOverdue());
        $this->assertSame('overdue', $receivable->displayStatus());
        $this->assertSame('Vencida', $receivable->displayStatusLabel());
        $this->assertSame(1, Receivable::overdue()->count());
    }

    public function test_full_payment(): void
    {
        $receivable = $this->receivable();

        Livewire::test(ListReceivables::class)
            ->callTableAction('pay', $receivable, data: ['amount' => 120000, 'paid_at' => '2026-10-15', 'method' => 'transfer'])
            ->assertHasNoTableActionErrors();

        $receivable->refresh();
        $this->assertSame(Receivable::PAID, $receivable->status);
        $this->assertSame(0.0, $receivable->balance());
        $this->assertSame('transfer', $receivable->payments()->sole()->method);
    }

    public function test_partial_payments_keep_the_right_balance(): void
    {
        $receivable = $this->receivable(120000);
        $service = app(ReceivableService::class);

        $service->registerPayment($receivable, 70000, today(), 'cash', null, $this->a['admin']);
        $this->assertSame(Receivable::PARTIAL, $receivable->status);
        $this->assertSame(50000.0, $receivable->balance());

        $service->registerPayment($receivable, 50000, today(), 'check', 'Cheque 123', $this->a['admin']);
        $this->assertSame(Receivable::PAID, $receivable->status);
        $this->assertSame(2, $receivable->payments()->count());
    }

    public function test_invalid_payments_are_rejected(): void
    {
        $receivable = $this->receivable(1000);
        $service = app(ReceivableService::class);

        foreach ([[2000, 'cash'], [0, 'cash'], [100, 'bitcoin']] as [$amount, $method]) {
            try {
                $service->registerPayment($receivable, $amount, today(), $method, null, $this->a['admin']);
                $this->fail("Se aceptó un pago inválido ({$amount}, {$method}).");
            } catch (ValidationException) {
                // esperado
            }
        }

        $service->registerPayment($receivable, 1000, today(), 'cash', null, $this->a['admin']);

        $this->expectException(ValidationException::class);
        $service->registerPayment($receivable, 1, today(), 'cash', null, $this->a['admin']); // ya pagada
    }

    public function test_void_only_without_payments(): void
    {
        $voidable = $this->receivable();
        app(ReceivableService::class)->void($voidable, 'Cargado por error');
        $this->assertSame(Receivable::VOID, $voidable->fresh()->status);
        $this->assertSame(0.0, $voidable->fresh()->balance());

        $paid = $this->receivable();
        app(ReceivableService::class)->registerPayment($paid, 100, today(), 'cash', null, $this->a['admin']);

        $this->expectException(ValidationException::class);
        app(ReceivableService::class)->void($paid, 'No');
    }

    public function test_dashboard_totals_and_filters(): void
    {
        $this->receivable(100000, '2026-10-30');          // pendiente
        $overdue = $this->receivable(40000, '2026-10-01'); // vencida
        $paid = $this->receivable(30000, '2026-10-20');
        app(ReceivableService::class)->registerPayment($paid, 30000, today(), 'cash', null, $this->a['admin']);
        app(ReceivableService::class)->registerPayment($overdue, 10000, today(), 'cash', null, $this->a['admin']);

        Livewire::test(ReceivablesOverview::class)
            ->assertSee('$130.000')   // pendiente: 100.000 + 30.000 de saldo de la vencida
            ->assertSee('$30.000')    // vencido
            ->assertSee('$40.000')    // cobrado este mes
            ->assertSee('2 cuentas pendientes')
            ->assertSee('1 cuenta vencida');

        Livewire::test(ListReceivables::class)
            ->filterTable('state', 'overdue')
            ->assertCountTableRecords(1)
            ->filterTable('state', 'paid')
            ->assertCountTableRecords(1)
            ->filterTable('state', 'open')
            ->filterTable('period', ['from' => '2026-10-25', 'until' => '2026-10-31'])
            ->assertCountTableRecords(1);
    }

    /*
    |--------------------------------------------------------------------------
    | PRESUPUESTOS → COBRO
    |--------------------------------------------------------------------------
    */

    private function quote(string $status = 'approved'): Quote
    {
        // Presupuestos: plan Profesional.
        Subscription::create([
            'company_id' => $this->a['company']->id, 'provider' => 'mercadopago', 'plan' => SubscriptionPlan::PROFESIONAL,
            'status' => 'authorized', 'amount' => 119000, 'current_period_end' => now()->addMonth(),
        ]);

        return Quote::create([
            'building_id' => $this->a['building']->id,
            'client_id' => $this->a['building']->client_id,
            'created_by' => $this->a['admin']->id,
            'title' => 'Cambio de operador',
            'amount' => 450000,
            'status' => $status,
        ]);
    }

    public function test_approved_quote_generates_a_linked_receivable_once(): void
    {
        $quote = $this->quote();

        Livewire::test(ListQuotes::class)
            ->assertTableActionVisible('generateReceivable', $quote)
            ->callTableAction('generateReceivable', $quote, data: [
                'concept' => 'Presupuesto #'.$quote->id.' — Cambio de operador',
                'amount' => 450000,
                'due_date' => '2026-10-30',
            ])
            ->assertHasNoTableActionErrors();

        $receivable = Receivable::sole();
        $this->assertSame($quote->id, $receivable->quote_id);
        $this->assertSame('quote', $receivable->source);
        $this->assertEquals(450000, $receivable->amount);
        $this->assertSame($quote->building_id, $receivable->building_id);

        // Ya tiene cobro: no se ofrece otra vez y el servicio lo rechaza.
        Livewire::test(ListQuotes::class)
            ->assertTableActionHidden('generateReceivable', $quote)
            ->assertTableActionVisible('viewReceivable', $quote);

        $this->expectException(ValidationException::class);
        app(ReceivableService::class)->fromQuote($quote, 'Duplicado', 450000, today(), $this->a['admin']);
    }

    public function test_viewing_or_editing_a_quote_never_creates_a_receivable(): void
    {
        // Un presupuesto abierto (enviado) se ve y se edita; uno aprobado se ve.
        $quote = $this->quote('sent');
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()]);
        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])->call('save');
        $quote->update(['status' => 'approved']);
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()]);

        $this->assertSame(0, Receivable::count());
    }

    public function test_only_approved_quotes_can_be_billed_and_a_void_one_can_be_regenerated(): void
    {
        $quote = $this->quote('sent');

        Livewire::test(ListQuotes::class)->assertTableActionHidden('generateReceivable', $quote);

        try {
            app(ReceivableService::class)->fromQuote($quote, 'X', 1000, today(), $this->a['admin']);
            $this->fail('Se generó el cobro de un presupuesto no aprobado.');
        } catch (ValidationException) {
            // esperado
        }

        $quote->update(['status' => 'approved']);
        $first = app(ReceivableService::class)->fromQuote($quote, 'X', 1000, today(), $this->a['admin']);
        app(ReceivableService::class)->void($first, 'Mal cargado');

        $second = app(ReceivableService::class)->fromQuote($quote, 'X', 1000, today(), $this->a['admin']);
        $this->assertNotSame($first->id, $second->id);
    }
}
