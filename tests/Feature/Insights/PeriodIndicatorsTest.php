<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\IndicatorsPage;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Insights\Indicators;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 6, PR 5: inspecciones realizadas, edificios atendidos y presupuestos
 * pendientes (cálculos, períodos y estados excluidos), y todos los
 * indicadores filtrados por las áreas del administrativo y por empresa.
 */
class PeriodIndicatorsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, string $name = 'Ana Oficina'): User
    {
        // El plan admite 5 administrativos: los anteriores se desactivan.
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $this->actingAs($this->a['admin']);
        $user = app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], ['indicators' => 'view', ...$levels]);
        auth()->logout();

        return $user;
    }

    /** Remito firmado (sin usuario logueado: se respeta la empresa explícita). */
    private function note(array $tenant, string $type, string $at, bool $performed = true, ?Building $building = null, ?WorkOrder $order = null): DeliveryNote
    {
        return DeliveryNote::factory()->create([
            'company_id' => $tenant['company']->id, 'building_id' => ($building ?? $tenant['building'])->id, 'user_id' => $tenant['technician']->id,
            'assignment_type' => $type, 'work_order_id' => $order?->id, 'performed' => $performed, 'created_at' => Carbon::parse($at),
        ]);
    }

    private function quote(array $tenant, string $status, ?string $validUntil = null, int $amount = 1000): Quote
    {
        return Quote::factory()->create(['company_id' => $tenant['company']->id, 'building_id' => $tenant['building']->id, 'created_by' => $tenant['admin']->id, 'status' => $status, 'valid_until' => $validUntil, 'amount' => $amount]);
    }

    private function period(?User $user = null, ?string $month = null, ?array $tenant = null): array
    {
        $tenant ??= $this->a;
        $this->inPanel($user ?? $tenant['admin']);
        $period = app(Indicators::class)->sections($tenant['company'], $user ?? $tenant['admin'], $month)['period'];

        return $period === null ? [] : collect($period['items'])->mapWithKeys(fn ($i) => [$i['key'] => $i['value']])->all();
    }

    public function test_inspections_done_count_only_signed_and_performed_inspection_work_of_the_month(): void
    {
        $second = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $inspectionOrder = WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $second->id, 'type' => 'inspection', 'status' => 'completed']);
        $claimOrder = WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $second->id, 'type' => 'claim', 'status' => 'completed']);
        $deletedInspection = WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $second->id, 'type' => 'inspection', 'status' => 'completed']);

        $this->note($this->a, 'inspection', '2026-10-02 10:00');                                 // cuenta
        $this->note($this->a, 'work_order', '2026-10-03 10:00', order: $inspectionOrder, building: $second); // cuenta (orden de inspección)
        $this->note($this->a, 'work_order', '2026-10-04 10:00', order: $deletedInspection, building: $second); // cuenta (la orden se borró después)
        $deletedInspection->delete();
        $this->note($this->a, 'inspection', '2026-10-05 10:00', performed: false);              // no realizado
        $this->note($this->a, 'maintenance', '2026-10-06 10:00');                               // otro tipo
        $this->note($this->a, 'work_order', '2026-10-07 10:00', order: $claimOrder, building: $second); // orden que no es inspección
        $this->note($this->a, 'inspection', '2026-09-30 23:59:59');                             // mes anterior
        $this->note($this->a, 'inspection', '2026-11-01 00:00:00');                             // mes siguiente (no se debería dar, pero se descarta)
        // Visita de inspección en curso, sin remito: no cuenta.
        BuildingVisit::factory()->create(['building_id' => $this->a['building']->id, 'company_id' => $this->a['company']->id, 'user_id' => $this->a['technician']->id, 'assignment_type' => 'inspection', 'month' => 10, 'year' => 2026, 'visited_at' => now()]);

        $this->assertSame('3', $this->period()['inspections_done']);
        $this->assertSame('1', $this->period(month: '2026-09')['inspections_done']);
    }

    public function test_buildings_served_are_distinct_buildings_with_a_performed_signed_note(): void
    {
        $company = $this->a['company']->id;
        [$b2, $b3, $b4] = [Building::factory()->create(['company_id' => $company]), Building::factory()->create(['company_id' => $company]), Building::factory()->create(['company_id' => $company])];

        $this->note($this->a, 'maintenance', '2026-10-01 00:00:00');               // b1
        $this->note($this->a, 'inspection', '2026-10-10 12:00');                   // b1 otra vez: no suma
        $this->note($this->a, 'maintenance', '2026-10-31 23:59:59', building: $b2); // b2, último segundo del mes
        $this->note($this->a, 'maintenance', '2026-10-08 12:00', performed: false, building: $b3); // b3 solo no realizado
        $this->note($this->a, 'maintenance', '2026-09-20 12:00', building: $b4);   // b4 en septiembre
        $b2->delete(); // desactivado después: igual fue atendido

        $this->assertSame('2', $this->period()['buildings_served']);
        $this->assertSame('1', $this->period(month: '2026-09')['buildings_served']);
        $this->assertSame('0', $this->period(month: '2026-08')['buildings_served']);
    }

    public function test_pending_quotes_are_sent_and_still_valid_today_whatever_the_month(): void
    {
        $this->quote($this->a, Quote::SENT, null, 100);                          // cuenta: sin validez
        $this->quote($this->a, Quote::SENT, '2026-10-14', 200);                  // cuenta: vence hoy
        $this->quote($this->a, Quote::SENT, '2026-12-01', 400);                  // cuenta
        $this->quote($this->a, Quote::SENT, '2026-10-13', 800);                  // vencido
        $this->quote($this->a, Quote::DRAFT, null, 1600);
        $this->quote($this->a, Quote::APPROVED, null, 3200);
        $this->quote($this->a, Quote::REJECTED, null, 6400);
        $this->quote($this->a, Quote::VOID, null, 12800);
        $this->quote($this->a, Quote::SENT, null, 25600)->delete();              // borrado

        $this->assertSame('3', $this->period()['quotes_pending']);
        $this->assertSame('3', $this->period(month: '2026-05')['quotes_pending']); // foto de hoy
        $this->inPanel($this->a['admin']);
        $item = collect(app(Indicators::class)->sections($this->a['company'], $this->a['admin'])['period']['items'])->firstWhere('key', 'quotes_pending');
        $this->assertStringContainsString('$700', $item['context']);
    }

    public function test_the_period_is_validated_and_chosen_on_the_page(): void
    {
        $this->note($this->a, 'inspection', '2026-08-10 10:00');

        foreach (['2026-13', '2027-01', 'abc', '1999-01', '2026-8', null] as $invalid) {
            $this->assertSame('2026-10', Indicators::month($invalid)->format('Y-m'), (string) $invalid);
        }
        $this->assertSame('2026-08', Indicators::month('2026-08')->format('Y-m'));
        $this->assertSame('2026-10', array_key_first(Indicators::periodOptions()));
        $this->assertLessThanOrEqual(24, count(Indicators::periodOptions()));

        $this->inPanel($this->a['admin']);
        $this->get(IndicatorsPage::getUrl().'?mes=2026-08')->assertOk()->assertSee('Trabajo del mes')->assertSee('Agosto 2026');
        Livewire::test(IndicatorsPage::class)->set('period', '2026-08')->assertSee('en Agosto 2026')
            ->set('period', '2030-01')->assertSee('en Octubre 2026');
    }

    public function test_staff_only_get_the_indicators_of_their_areas(): void
    {
        $this->note($this->a, 'inspection', '2026-10-02 10:00');
        $this->quote($this->a, Quote::SENT, null, 5000);
        WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'status' => 'pending']);

        // Solo «Indicadores»: ningún número.
        $none = $this->staff([], 'Sin areas');
        $this->inPanel($none);
        $s = app(Indicators::class)->sections($this->a['company'], $none);
        $this->assertSame([], $s['basic']);
        $this->assertSame([], $s['period']['items']);
        $this->assertNull($s['company']['evolution']);
        $this->assertNull($s['company']['technicians']);
        $this->assertNull($s['company']['top_failures']);
        $this->assertNull($s['company']['quotes']);
        $this->assertCount(0, $s['advanced']['comparison']);
        $this->assertSame([], $s['advanced']['portfolio']);
        $this->assertNull($s['advanced']['top_clients']);
        $this->assertNull($s['advanced']['quarters']);
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee('No hay indicadores disponibles con tus permisos')
            ->assertDontSee('Inspecciones realizadas')->assertDontSee('¿Cuánto trabajo tenemos abierto?')->assertDontSee('Tu cartera')
            ->assertDontSee($this->a['technician']->name)->assertDontSee('Mejorá tu plan')->assertDontSee('Ver planes');

        // Remitos: inspecciones y edificios atendidos; nada de presupuestos ni órdenes.
        $docs = $this->staff(['documents' => 'view'], 'Remitos');
        $this->assertSame(['inspections_done' => '1', 'buildings_served' => '1'], $this->period($docs));
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee('Inspecciones realizadas')->assertDontSee('Presupuestos pendientes')
            ->assertDontSee('¿Cuánto trabajo tenemos abierto?')->assertDontSee('¿Cuántos presupuestos nos aprueban?');

        // Presupuestos: pendientes, aprobación y comparación de presupuestos; nada de remitos.
        $quotes = $this->staff(['quotes' => 'view'], 'Presupuestos');
        $this->assertSame(['quotes_pending' => '1'], $this->period($quotes));
        $s = app(Indicators::class)->sections($this->a['company'], $quotes);
        $this->assertNotNull($s['company']['quotes']);
        $this->assertSame(['Presupuestos aprobados ($)'], $s['advanced']['comparison']->pluck('label')->all());

        // Órdenes: trabajo abierto y columnas de órdenes; los reclamos piden además reportes.
        $orders = $this->staff(['work_orders' => 'view'], 'Ordenes');
        $this->inPanel($orders);
        $s = app(Indicators::class)->sections($this->a['company'], $orders);
        $this->assertSame(['¿Cuánto trabajo tenemos abierto?'], array_column($s['basic'], 'question'));
        $this->assertSame(['month', 'completed', 'close_days'], array_keys($s['company']['evolution']->first()));
        $this->assertNull($s['company']['technicians']); // sin «Técnicos», ni nombres
        $this->assertNull($s['company']['top_failures']);

        // Técnicos y visitas: tabla de técnicos solo con la columna de visitas.
        $techs = $this->staff(['technicians' => 'view', 'visits' => 'view'], 'Tecnicos');
        $this->inPanel($techs);
        $s = app(Indicators::class)->sections($this->a['company'], $techs);
        $this->assertSame(['visits'], $s['company']['technician_columns']);
        $this->assertSame(['name', 'visits'], array_keys($s['company']['technicians']->first()));
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee($this->a['technician']->name)->assertDontSee('Órdenes terminadas');

        // Cobranzas: la cartera sí, pero no los edificios sin contrato (piden Edificios y Clientes).
        $money = $this->staff(['receivables' => 'view'], 'Cobranzas');
        $this->inPanel($money);
        $s = app(Indicators::class)->sections($this->a['company'], $money);
        $this->assertSame(['¿Cuánto factura la cartera por mes?', '¿Dependemos de pocos clientes?'], array_column($s['advanced']['portfolio'], 'question'));
    }

    public function test_the_owner_keeps_every_indicator(): void
    {
        $this->inPanel($this->a['admin']);
        $s = app(Indicators::class)->sections($this->a['company'], $this->a['admin']);

        $this->assertCount(3, $s['basic']);
        $this->assertSame(['inspections_done', 'buildings_served', 'quotes_pending'], array_column($s['period']['items'], 'key'));
        $this->assertSame(['month', 'maintenance', 'claims', 'completed', 'close_days'], array_keys($s['company']['evolution']->first()));
        $this->assertSame(['visits', 'orders', 'close_days'], $s['company']['technician_columns']);
        $this->assertNotNull($s['company']['top_failures']);
        $this->assertNotNull($s['company']['quotes']);
        $this->assertCount(4, $s['advanced']['comparison']);
        $this->assertCount(4, $s['advanced']['portfolio']);
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee('Inspecciones realizadas')->assertSee('Edificios atendidos')
            ->assertSee('Presupuestos pendientes')->assertSee('Cómo se calcula')->assertDontSee('No hay indicadores disponibles');
    }

    public function test_plans_keep_their_indicators(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->inPanel($this->a['admin']);
        $s = app(Indicators::class)->sections($this->a['company'], $this->a['admin']);
        $this->assertNull($s['period']);
        $this->assertNull($s['company']);
        $this->assertCount(3, $s['basic']);
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertDontSee('Inspecciones realizadas')->assertSee('Indicadores de empresa');

        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->assertSame(['inspections_done', 'buildings_served', 'quotes_pending'], array_keys($this->period()));
    }

    public function test_numbers_never_cross_companies(): void
    {
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $this->note($b, 'inspection', '2026-10-02 10:00');
        $this->note($b, 'maintenance', '2026-10-03 10:00');
        $this->quote($b, Quote::SENT, null);
        $bOrder = WorkOrder::factory()->create(['company_id' => $b['company']->id, 'building_id' => $b['building']->id, 'type' => 'inspection']);
        // Un remito de A que apunta (mal) a una orden de inspección de B no cuenta como inspección.
        $this->note($this->a, 'work_order', '2026-10-04 10:00', order: $bOrder);

        $this->assertSame(['inspections_done' => '0', 'buildings_served' => '1', 'quotes_pending' => '0'], $this->period());
        $this->assertSame(['inspections_done' => '1', 'buildings_served' => '1', 'quotes_pending' => '1'], $this->period(tenant: $b));
    }
}
