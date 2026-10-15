<?php

namespace Tests\Feature\Billing;

use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
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
 * Vencimiento de los cobros de servicios y su coherencia al editar la contratación:
 * - el primer cobro no nace vencido (si el día pactado ya pasó, vence el mes siguiente);
 * - cambiar el día de vencimiento o el inicio ajusta solo los cobros pendientes sin pagos;
 * - lo que sigue realmente vencido sigue vencido, y pagados, parciales y anulados no cambian.
 */
class ServiceChargeDueDateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
        $this->actingInPanel($this->a['admin']);
    }

    private function contract(array $data = []): MaintenanceService
    {
        Livewire::test(CreateMaintenanceService::class)->fillForm([
            'client_id' => $this->client->id, 'description' => 'Abono mensual', 'category' => 'maintenance',
            'scope' => 'buildings', 'building_ids' => [$this->a['building']->id], 'pricing_mode' => ServiceCatalogItem::CUSTOM, 'unit_price' => 100000,
            'frequency' => 'monthly', 'payment_due_day' => 10, 'start_date' => '2026-10-01', 'status' => 'active',
            ...$data,
        ])->call('create')->assertHasNoFormErrors();

        return MaintenanceService::latest('id')->first();
    }

    private function edit(MaintenanceService $service, array $data): void
    {
        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])->fillForm($data)->call('save')->assertHasNoFormErrors();
    }

    /** Lo que muestra Cobranzas en la columna Estado. */
    private function cobranzasShows(Receivable $receivable, string $label): void
    {
        Livewire::test(ListReceivables::class)->assertTableColumnStateSet('status', $label, $receivable);
    }

    public function test_reported_case_editing_the_due_day_updates_the_pending_charge_in_cobranzas(): void
    {
        $service = $this->contract(); // vence el 10/10
        $charge = Receivable::sole();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->cobranzasShows($charge, 'Vencida');

        // Se corrige el día pactado a 20: el cobro pendiente pasa a vencer el 20/10.
        $this->edit($service, ['payment_due_day' => 20]);
        $this->assertSame('2026-10-20', $charge->fresh()->due_date->toDateString());
        $this->cobranzasShows($charge->fresh(), 'Pendiente');

        // Si con el nuevo día sigue vencido, sigue vencido.
        $this->edit($service, ['payment_due_day' => 12]);
        $this->assertSame('2026-10-12', $charge->fresh()->due_date->toDateString());
        $this->cobranzasShows($charge->fresh(), 'Vencida');

        // Mismo cobro: ni duplicados ni cambios de importe o período.
        $this->assertSame(1, Receivable::count());
        $this->assertSame(['2026-10-01', 100000.0], [$charge->fresh()->period_start->toDateString(), (float) $charge->fresh()->amount]);
    }

    public function test_the_first_charge_is_not_born_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->contract(); // día 10 ya pasó en octubre

        $charge = Receivable::sole();
        $this->assertSame(['2026-10-01', '2026-11-10'], [$charge->period_start->toDateString(), $charge->due_date->toDateString()]);
        $this->cobranzasShows($charge, 'Pendiente');

        // Los siguientes vencen el día pactado de su mes.
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));
        app(ServiceBillingService::class)->generateAll();
        $this->assertSame('2026-11-10', Receivable::whereDate('period_start', '2026-11-01')->sole()->due_date->toDateString());

        // Editar otra cosa (nombre) no mueve vencimientos.
        $this->edit(MaintenanceService::sole(), ['description' => 'Abono renombrado']);
        $this->assertSame(['2026-11-10', '2026-11-10'], Receivable::orderBy('period_start')->get()->map(fn ($r) => $r->due_date->toDateString())->all());
    }

    public function test_paid_partial_and_voided_charges_are_not_touched(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 10:00'));
        $service = $this->contract(['start_date' => '2026-08-01']);
        foreach (['2026-09-02', '2026-10-02', '2026-11-02'] as $day) {
            $this->travelTo(Carbon::parse($day.' 10:00'));
            app(ServiceBillingService::class)->generateAll();
        }
        [$aug, $sep, $oct, $nov] = Receivable::orderBy('period_start')->get()->all();
        $payments = app(ReceivableService::class);
        $payments->registerPayment($aug, 100000, today(), 'cash', null, $this->a['admin']); // pagado
        $payments->registerPayment($sep, 40000, today(), 'cash', null, $this->a['admin']);  // parcial (y vencido)
        $payments->void($oct, 'Bonificado');                                                  // anulado
        $before = collect([$aug, $sep, $oct])->map(fn ($r) => $r->fresh()->toArray());

        $this->edit($service, ['payment_due_day' => 25]);

        $this->assertEquals($before, collect([$aug, $sep, $oct])->map(fn ($r) => $r->fresh()->toArray()));
        $this->assertSame('2026-11-25', $nov->fresh()->due_date->toDateString()); // solo el pendiente sin pagos
        $this->cobranzasShows($sep->fresh(), 'Parcialmente pagada (vencida)');
        $this->assertSame(4, Receivable::count());
    }

    public function test_moving_the_start_later_voids_charges_left_outside_without_deleting_them(): void
    {
        $service = $this->contract(); // cobro de octubre
        $october = Receivable::sole();

        $this->edit($service, ['start_date' => '2026-11-01']);

        $october->refresh();
        $this->assertSame(Receivable::VOID, $october->status);
        $this->assertStringContainsString('ahora empieza el 01/11/2026', $october->void_reason);
        $this->assertSame('Anulada', $october->displayStatusLabel()); // Cobranzas oculta las anuladas por defecto

        // Noviembre se cobra a su tiempo, una sola vez.
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));
        app(ServiceBillingService::class)->generateAll();
        app(ServiceBillingService::class)->generateAll();
        $this->assertSame(['2026-10', '2026-11'], Receivable::orderBy('period_start')->pluck('period_start')->map->format('Y-m')->all());
        $this->assertSame(1, Receivable::where('status', '!=', Receivable::VOID)->count());

        // Un cobro con pagos no se anula aunque su período quede fuera.
        $paid = $this->contract(['description' => 'Otro', 'start_date' => '2026-11-01']);
        app(ReceivableService::class)->registerPayment($paid->receivables()->sole(), 1000, today(), 'cash', null, $this->a['admin']);
        $this->edit($paid, ['start_date' => '2026-12-01']);
        $this->assertSame(Receivable::PARTIAL, $paid->receivables()->sole()->status);
    }

    public function test_reactivation_and_rebilling_keep_their_rules(): void
    {
        // Reactivación: si vuelve después del día pactado, el cobro nace vencido (regla aprobada).
        $service = $this->contract(); // octubre, vence 10/10
        $service->update(['status' => MaintenanceService::PAUSED]);
        $this->travelTo(Carbon::parse('2026-12-15 10:00'));
        $service->update(['status' => MaintenanceService::ACTIVE]);
        $this->assertSame('overdue', Receivable::whereDate('period_start', '2026-12-01')->sole()->displayStatus());

        // «Volver a cobrar»: su cobro no se reprograma al editar el día pactado.
        $once = $this->contract(['description' => 'Prueba de paracaídas', 'category' => 'parachute', 'frequency' => 'once', 'start_date' => '2026-12-01', 'payment_due_day' => 20]);
        app(ReceivableService::class)->void($once->receivables()->sole(), 'Error');
        $rebilled = app(ServiceBillingService::class)->rebillOneTime($once, $this->a['admin']);
        $this->edit($once, ['payment_due_day' => 25]);
        $this->assertSame('2026-12-20', $rebilled->fresh()->due_date->toDateString());
        $this->assertSame(1, $once->receivables()->where('status', '!=', Receivable::VOID)->count());
    }

    public function test_other_companies_are_not_affected(): void
    {
        // Contrato de la otra empresa, creado por su admin.
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $other = MaintenanceService::create(['client_id' => $this->b['building']->client_id, 'building_id' => $this->b['building']->id, 'description' => 'Abono B', 'amount' => 5000, 'frequency' => 'monthly', 'start_date' => '2026-10-01', 'payment_due_day' => 10, 'status' => 'active']);
        $otherCharge = Receivable::withoutGlobalScopes()->where('maintenance_service_id', $other->id)->sole()->toArray();

        $this->flushSession();
        $this->actingInPanel($this->a['admin']);
        $service = $this->contract();
        $this->edit($service, ['payment_due_day' => 22, 'start_date' => '2026-10-01']);

        $this->assertSame($otherCharge, Receivable::withoutGlobalScopes()->where('maintenance_service_id', $other->id)->sole()->toArray());
        Livewire::test(ListReceivables::class)->assertCountTableRecords(1);
    }

    public function test_due_dates_across_year_end_february_and_days_beyond_28(): void
    {
        // Alta el 15/12 con día 10: vence el 10/01 del año siguiente.
        $this->travelTo(Carbon::parse('2026-12-15 10:00'));
        $service = MaintenanceService::create(['client_id' => $this->client->id, 'building_id' => $this->a['building']->id, 'description' => 'Diciembre', 'amount' => 1000, 'frequency' => 'monthly', 'start_date' => '2026-12-01', 'payment_due_day' => 10, 'status' => 'active']);
        $charge = $service->receivables()->sole();
        $this->assertSame('2027-01-10', $charge->due_date->toDateString());

        // Misma instancia editada en la misma ejecución: el ajuste también corre (antes no).
        $service->update(['payment_due_day' => 28]);
        $this->assertSame('2026-12-28', $charge->fresh()->due_date->toDateString());

        // 29, 30 o 31: el formulario no los acepta y el modelo los limita a 28.
        Livewire::test(EditMaintenanceService::class, ['record' => $service->getRouteKey()])->set('data.payment_due_day', 31)->call('save')->assertHasFormErrors(['payment_due_day']);
        $service->update(['payment_due_day' => 31]);
        $this->assertSame([28, '2026-12-28'], [$service->fresh()->payment_due_day, $charge->fresh()->due_date->toDateString()]);

        // Febrero: el día 28 existe siempre.
        $this->travelTo(Carbon::parse('2027-02-02 10:00'));
        app(ServiceBillingService::class)->generateAll();
        $this->assertSame('2027-02-28', $service->receivables()->whereDate('period_start', '2027-02-01')->sole()->due_date->toDateString());
        $this->assertSame(3, $service->receivables()->count()); // dic, ene, feb: sin duplicados
    }

    public function test_a_charge_paid_or_voided_meanwhile_is_left_alone(): void
    {
        $service = $this->contract();
        $charge = Receivable::sole();

        // Otro usuario registra un pago justo después de que el ajuste leyó los pendientes.
        $hit = false;
        Receivable::retrieved(function (Receivable $r) use ($charge, &$hit) {
            if (! $hit && $r->id === $charge->id && $r->status === Receivable::PENDING) {
                $hit = true;
                \DB::table('receivables')->where('id', $r->id)->update(['status' => Receivable::PARTIAL, 'paid_amount' => 500]);
            }
        });
        $this->edit($service, ['start_date' => '2026-11-01']);

        $this->assertTrue($hit);
        $this->assertSame([Receivable::PARTIAL, 500.0], [$charge->fresh()->status, (float) $charge->fresh()->paid_amount]);
        $this->assertSame('2026-11-01', $service->fresh()->start_date->toDateString()); // la edición se guardó igual
    }

    public function test_a_manual_void_reason_is_never_overwritten(): void
    {
        $service = $this->contract();
        $charge = Receivable::sole();

        $hit = false;
        Receivable::retrieved(function (Receivable $r) use ($charge, &$hit) {
            if (! $hit && $r->id === $charge->id && $r->status === Receivable::PENDING) {
                $hit = true;
                \DB::table('receivables')->where('id', $r->id)->update(['status' => Receivable::VOID, 'void_reason' => 'Anulado a mano', 'voided_at' => now()]);
            }
        });
        $this->edit($service, ['start_date' => '2026-11-01']);

        $this->assertTrue($hit);
        $this->assertSame('Anulado a mano', $charge->fresh()->void_reason);
    }

    public function test_an_old_wrongly_overdue_charge_can_be_corrected_from_the_contract(): void
    {
        // Cobro emitido por la versión anterior: alta el 15/10 con día 10, vence el 10/10 (nació vencido).
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $service = $this->contract();
        $charge = Receivable::sole();
        \DB::table('receivables')->where('id', $charge->id)->update(['due_date' => '2026-10-10']);
        $this->cobranzasShows($charge->fresh(), 'Vencida');

        // Guardar sin cambiar el día no mueve vencimientos (regla acordada).
        $this->edit($service, ['description' => 'Abono']);
        $this->assertSame('2026-10-10', $charge->fresh()->due_date->toDateString());

        // Cambiar el día (y, si se quiere conservar el mismo, volver a él) lo corrige con la regla actual.
        $this->edit($service, ['payment_due_day' => 11]);
        $this->edit($service, ['payment_due_day' => 10]);
        $this->assertSame('2026-11-10', $charge->fresh()->due_date->toDateString());
        $this->cobranzasShows($charge->fresh(), 'Pendiente');
        $this->assertSame(1, Receivable::count());
    }
}
