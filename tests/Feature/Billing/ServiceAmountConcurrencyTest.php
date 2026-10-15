<?php

namespace Tests\Feature\Billing;

use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Models\Building;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\ServiceCatalogItem;
use App\Services\Billing\ServiceBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * El importe que se guarda es el que el usuario vio (y confirmó): si otro usuario
 * cambia equipos o paradas mientras se completa el formulario, el servidor
 * recalcula, no guarda y muestra el nuevo importe. Y la reactivación de
 * servicios trimestrales y semestrales respeta su ciclo sin cobrar la pausa.
 */
class ServiceAmountConcurrencyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    /** 3 ascensores. */
    private Building $tower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
        $this->tower = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id, 'name' => 'Torre Este', 'elevator_count' => 3]);
        $this->actingInPanel($this->a['admin']);
    }

    private function createForm(array $data = [])
    {
        return Livewire::test(CreateMaintenanceService::class)->fillForm([
            'client_id' => $this->client->id, 'description' => 'Mantenimiento por equipo', 'category' => 'maintenance',
            'scope' => 'buildings', 'building_ids' => [$this->tower->id], 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT,
            'unit_price' => 50000, 'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ...$data,
        ]);
    }

    private function contract(array $data = []): MaintenanceService
    {
        $this->createForm($data)->call('create')->assertHasNoFormErrors();

        return MaintenanceService::latest('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | EDICIÓN
    |--------------------------------------------------------------------------
    */

    public function test_a_confirmed_amount_is_not_saved_if_the_equipment_changes_before_saving(): void
    {
        $service = $this->contract(); // 3 × $50.000

        $form = Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['unit_price' => 60000])
            ->assertSee('El importe cambia: $150.000 → $180.000')
            ->fillForm(['confirm_amount_change' => true]);

        $this->tower->update(['elevator_count' => 2]); // otro usuario, antes de guardar: ahora serían $120.000

        $form->call('save')->assertHasFormErrors(['confirm_amount_change']);
        $this->assertStringContainsString('ahora es $120.000 (habías confirmado $180.000)', $form->errors()->first('data.confirm_amount_change'));
        $this->assertEquals(150000, $service->fresh()->amount);
        // La pantalla ya muestra el importe nuevo y la confirmación quedó sin marcar.
        $form->assertSee('El importe cambia: $150.000 → $120.000')->assertFormSet(['confirm_amount_change' => false]);

        // Confirmando el importe que ahora está a la vista, se guarda ese.
        $form->fillForm(['confirm_amount_change' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame([120000.0, 2], [(float) $service->fresh()->amount, $service->fresh()->quantity]);
        $this->assertEquals(150000, Receivable::sole()->amount); // el cobro ya generado no cambia
    }

    public function test_concurrent_stop_changes_also_require_a_new_confirmation(): void
    {
        Elevator::where('building_id', $this->tower->id)->update(['stops' => 5]);
        $service = $this->contract(['pricing_mode' => ServiceCatalogItem::PER_STOP, 'unit_price' => 1000]); // 15 paradas

        $form = Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['unit_price' => 2000])
            ->fillForm(['confirm_amount_change' => true]); // confirma $30.000
        Elevator::where('building_id', $this->tower->id)->update(['stops' => 6]); // ahora $36.000

        $form->call('save')->assertHasFormErrors(['confirm_amount_change']);
        $this->assertEquals(15000, $service->fresh()->amount);
    }

    public function test_an_unrelated_edit_does_not_save_an_amount_changed_by_someone_else(): void
    {
        $service = $this->contract();

        $form = Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['description' => 'Solo cambio el nombre'])
            ->assertDontSee('El importe cambia');
        $this->tower->update(['elevator_count' => 1]);

        $form->call('save')->assertHasFormErrors(['confirm_amount_change']);
        $this->assertSame(['Mantenimiento por equipo', 150000.0], [$service->fresh()->description, (float) $service->fresh()->amount]);
    }

    public function test_the_client_tab_edit_is_protected_the_same_way(): void
    {
        $service = $this->contract();
        $this->tower->update(['elevator_count' => 2]);

        // Confirmación sin el importe a la vista (por ejemplo, una solicitud armada a mano): no se guarda.
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->callTableAction('edit', $service, data: ['unit_price' => 50000, 'confirm_amount_change' => true, 'confirmed_amount' => 999])
            ->assertHasTableActionErrors(['confirm_amount_change']);
        $this->assertEquals(150000, $service->fresh()->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | ALTA
    |--------------------------------------------------------------------------
    */

    public function test_a_new_contract_is_not_created_with_an_amount_different_from_the_one_shown(): void
    {
        $form = $this->createForm(['building_ids' => [$this->tower->id, $this->a['building']->id]])
            ->assertSee('$250.000'); // 3 + 2 equipos
        $this->tower->update(['elevator_count' => 2]); // otro usuario: ahora $200.000

        $form->call('create')->assertHasFormErrors(['unit_price']);
        $this->assertStringContainsString('ahora es $200.000 (se mostraba $250.000)', $form->errors()->first('data.unit_price'));
        $this->assertSame(0, MaintenanceService::count());
        $this->assertSame(0, Receivable::count());

        // Ya con el resumen actualizado a la vista, se crea con el importe correcto.
        $form->assertSee('$200.000')->call('create')->assertHasNoFormErrors();
        $this->assertEquals([100000.0, 100000.0], MaintenanceService::orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    /*
    |--------------------------------------------------------------------------
    | REACTIVACIÓN EN SERVICIOS TRIMESTRALES Y SEMESTRALES
    |--------------------------------------------------------------------------
    */

    /**
     * Regla actual (sin prorrateo): al reactivar no se cobra la pausa; el ciclo sigue contado
     * desde el inicio del servicio y se cobra el primer período que empieza desde el mes de
     * la reactivación (inclusive). Nunca se duplica un período ya cobrado.
     *
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function reactivations(): array
    {
        return [
            'trimestral, vuelve dentro del trimestre ya cobrado' => ['quarterly', '2026-02-10', '2026-03-10', ['2026-01', '2026-04', '2026-07', '2026-10', '2027-01']],
            'trimestral, vuelve en el mes de un período' => ['quarterly', '2026-02-10', '2026-04-10', ['2026-01', '2026-04', '2026-07', '2026-10', '2027-01']],
            'trimestral, vuelve a mitad de un período' => ['quarterly', '2026-02-10', '2026-05-10', ['2026-01', '2026-07', '2026-10', '2027-01']],
            'trimestral, pausa larga' => ['quarterly', '2026-03-31', '2026-08-20', ['2026-01', '2026-10', '2027-01']],
            'semestral, vuelve en el mes de un período' => ['semiannual', '2026-02-10', '2026-07-15', ['2026-01', '2026-07', '2027-01']],
            'semestral, vuelve a mitad de un período' => ['semiannual', '2026-02-10', '2026-09-15', ['2026-01', '2027-01']],
        ];
    }

    #[DataProvider('reactivations')]
    public function test_reactivation_keeps_the_cycle_without_billing_the_pause(string $frequency, string $pause, string $resume, array $expected): void
    {
        $billing = app(ServiceBillingService::class);
        $this->travelTo(Carbon::parse('2026-01-05'));
        $service = MaintenanceService::create(['client_id' => $this->client->id, 'building_id' => $this->tower->id, 'description' => 'Abono', 'amount' => 300000, 'frequency' => $frequency, 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);

        $this->travelTo(Carbon::parse($pause));
        $billing->generateAll();
        $service->update(['status' => MaintenanceService::PAUSED]);

        $this->travelTo(Carbon::parse($resume));
        $billing->generateAll(); // pausado: nada
        $service->update(['status' => MaintenanceService::ACTIVE]);
        $billing->generateAll();
        $billing->generateAll(); // idempotente

        $this->travelTo(Carbon::parse('2027-02-15'));
        $billing->generateAll();

        $receivables = Receivable::where('maintenance_service_id', $service->id)->orderBy('period_start')->get();
        $this->assertSame($expected, $receivables->map(fn ($r) => $r->period_start->format('Y-m'))->all());
        // Vencimiento: el día pactado del mes en que empieza cada período; mismo importe en todos.
        $this->assertTrue($receivables->every(fn ($r) => $r->due_date->isSameDay($r->period_start->copy()->day(10)) && (float) $r->amount === 300000.0));
    }
}
