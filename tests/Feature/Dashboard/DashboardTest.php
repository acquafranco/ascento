<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AdminStats;
use App\Filament\Widgets\FollowUpPanel;
use App\Filament\Widgets\PlatformOverviewWidget;
use App\Filament\Widgets\QuotesStatusChart;
use App\Filament\Widgets\StaffNotice;
use App\Filament\Widgets\WelcomeBanner;
use App\Filament\Widgets\WorkTrendChart;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Crm\ClientInteractionService;
use App\Services\Insights\DashboardSummary;
use App\Services\Staff\StaffManager;
use App\Services\Tasks\TaskService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Inicio del panel (Etapa 6): el dueño ve todo como antes (salvo la regla
 * acordada de presupuestos pendientes); un administrativo, solo tarjetas,
 * series y líneas de sus áreas, sin datos de otras en los gráficos; un
 * permiso quitado a mitad de sesión corta las actualizaciones; nada cruza
 * entre empresas.
 */
class DashboardTest extends TestCase
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
        $user = app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
        $this->inPanel($user);

        return $user;
    }

    /** Datos en todos los módulos (empresa A). */
    private function fill(array $tenant): void
    {
        $company = $tenant['company']->id;
        $building = $tenant['building'];
        WorkOrder::factory()->create(['company_id' => $company, 'building_id' => $building->id, 'status' => 'pending', 'priority' => 'urgent']);
        WorkOrder::factory()->create(['company_id' => $company, 'building_id' => $building->id, 'status' => 'pending']);
        WorkOrder::factory()->inProgress()->create(['company_id' => $company, 'building_id' => $building->id])
            ->participants()->attach($tenant['technician']->id, ['role' => 'participant']);
        WorkOrder::factory()->create(['company_id' => $company, 'building_id' => $building->id, 'status' => 'completed', 'finished_at' => now()->subHour()]);
        foreach ([[Quote::SENT, null, 1000], [Quote::SENT, '2026-10-16', 2000], [Quote::SENT, '2026-10-01', 4000], [Quote::DRAFT, null, 8000], [Quote::APPROVED, null, 16000], [Quote::REJECTED, null, 32000]] as [$status, $valid, $amount]) {
            Quote::factory()->create(['company_id' => $company, 'building_id' => $building->id, 'created_by' => $tenant['admin']->id, 'status' => $status, 'valid_until' => $valid, 'amount' => $amount]);
        }
        Receivable::withoutGlobalScopes()->forceCreate(['company_id' => $company, 'client_id' => $building->client_id, 'source' => 'manual', 'concept' => 'Abono vencido', 'amount' => 90000, 'paid_amount' => 40000, 'status' => Receivable::PARTIAL, 'due_date' => today()->subDays(5)]);

        $this->actingAs($tenant['admin']);
        app(TaskService::class)->create($tenant['admin'], ['title' => 'Llamar al consorcio', 'priority' => 'high', 'due_at' => now()->subDay(), 'assigned_to' => $tenant['admin']->id]);
        app(ClientInteractionService::class)->record($tenant['admin'], ['client_id' => $building->client_id, 'type' => 'call', 'result' => 'follow_up', 'occurred_at' => now()->subDays(2)]);
        auth()->logout();
    }

    private function cards(?User $user): array
    {
        return collect(app(DashboardSummary::class)->cards($user))->keyBy('key')->all();
    }

    /** Los datos que viajan al gráfico (de ahí salen también los tooltips). */
    private function chartPayload(string $widget): string
    {
        return json_encode((fn () => $this->getData())->call(Livewire::test($widget)->instance()), JSON_UNESCAPED_UNICODE);
    }

    public function test_the_owner_sees_every_card_chart_and_follow_up_line(): void
    {
        $this->fill($this->a);
        $this->inPanel($this->a['admin']);

        $cards = $this->cards($this->a['admin']);
        $this->assertSame(['orders', 'completed_today', 'notes_today', 'quotes', 'receivables', 'buildings', 'technicians'], array_keys($cards));
        $this->assertSame('2', $cards['orders']['value']);
        $this->assertStringStartsWith('1 en proceso', $cards['orders']['description']);
        $this->assertSame('1', $cards['completed_today']['value']);
        // Presupuestos: solo enviados y vigentes; el vencido y el borrador, no.
        $this->assertSame('2', $cards['quotes']['value']);
        $this->assertStringContainsString('$3.000', $cards['quotes']['description']);
        $this->assertStringContainsString('1 borrador sin enviar', $cards['quotes']['description']);
        // Antes la tarjeta sumaba los borradores vigentes: la suma sigue cerrando.
        $this->assertSame(Quote::open()->count(), 2 + 1);
        $this->assertSame('$50.000', $cards['receivables']['value']);
        $this->assertSame('1 de 1', $cards['technicians']['value']);
        foreach ($cards as $card) {
            $this->assertNotNull($card['url'], $card['key']); // lo agrupado sigue accesible desde la tarjeta
        }

        foreach ([WelcomeBanner::class, AdminStats::class, WorkTrendChart::class, QuotesStatusChart::class, FollowUpPanel::class] as $widget) {
            $this->assertTrue($widget::canView(), $widget);
        }
        $this->assertFalse(StaffNotice::canView());

        $payload = $this->chartPayload(WorkTrendChart::class);
        foreach (['Órdenes terminadas', 'Reportes y reclamos', 'Mantenimientos cumplidos (%)'] as $series) {
            $this->assertStringContainsString($series, $payload);
        }
        $this->assertSame(['awaiting' => 2, 'approved' => 1, 'rejected' => 1, 'expired' => 1], app(DashboardSummary::class)->quotesBreakdown($this->a['company'], $this->a['admin']));

        $follow = app(DashboardSummary::class)->followUp($this->a['company'], $this->a['admin']);
        $this->assertSame([1, 1, 1], [$follow['tasks']['overdue'], $follow['tasks']['mine'], $follow['contacts']['count']]);
        $this->assertSame(1, $follow['expiring']['count']);
        $this->assertNotEmpty($follow['alerts']['items']);
        Livewire::test(FollowUpPanel::class)->assertSee('Seguimiento comercial')->assertSee('Tareas vencidas')
            ->assertSee('Contactos para seguir')->assertSee('Presupuestos por vencer')->assertSee('Para atender');

        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Bienvenido')
            ->assertSeeLivewire(AdminStats::class)->assertSeeLivewire(WorkTrendChart::class)
            ->assertSeeLivewire(QuotesStatusChart::class)->assertSeeLivewire(FollowUpPanel::class);
    }

    public function test_staff_only_get_the_blocks_of_their_areas(): void
    {
        $this->fill($this->a);

        // Tareas, contactos y clientes: solo el seguimiento (tareas y contactos).
        $laura = $this->staff(['tasks' => 'view', 'contacts' => 'manage', 'clients' => 'manage'], 'Laura');
        $this->assertSame([], $this->cards($laura));
        $this->assertFalse(AdminStats::canView());
        $this->assertFalse(WorkTrendChart::canView());
        $this->assertFalse(QuotesStatusChart::canView());
        $this->assertTrue(FollowUpPanel::canView());
        $follow = app(DashboardSummary::class)->followUp($this->a['company'], $laura);
        $this->assertNotNull($follow['tasks']);
        $this->assertNotNull($follow['contacts']);
        $this->assertNull($follow['expiring']);
        $this->assertNull($follow['alerts']);
        Livewire::test(FollowUpPanel::class)->assertSee('Tareas vencidas')->assertSee('Contactos para seguir')
            ->assertDontSee('Presupuestos por vencer')->assertDontSee('Para atender');
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Bienvenido')
            ->assertDontSeeLivewire(AdminStats::class)->assertDontSeeLivewire(WorkTrendChart::class)->assertSeeLivewire(FollowUpPanel::class);

        // Remitos y órdenes: sus tarjetas y solo la serie de órdenes (los reclamos piden también reportes).
        $marcos = $this->staff(['documents' => 'view', 'work_orders' => 'view'], 'Marcos');
        $this->assertSame(['orders', 'completed_today', 'notes_today'], array_keys($this->cards($marcos)));
        $payload = $this->chartPayload(WorkTrendChart::class);
        $this->assertStringContainsString('Órdenes terminadas', $payload);
        $this->assertStringNotContainsString('Reportes y reclamos', $payload);
        $this->assertStringNotContainsString('Mantenimientos cumplidos', $payload);
        $this->assertFalse(QuotesStatusChart::canView());
        $this->assertFalse(FollowUpPanel::canView());
        Livewire::test(AdminStats::class)->assertSee('Órdenes pendientes')->assertDontSee('Presupuestos')->assertDontSee('Cobros vencidos')
            ->assertDontSee('Técnicos ocupados')->assertDontSee('$50.000');

        // Presupuestos: su tarjeta, su gráfico y los que vencen; nada más.
        $quotes = $this->staff(['quotes' => 'view'], 'Presupuestos');
        $this->assertSame(['quotes'], array_keys($this->cards($quotes)));
        $this->assertTrue(QuotesStatusChart::canView());
        $this->assertFalse(WorkTrendChart::canView());
        $follow = app(DashboardSummary::class)->followUp($this->a['company'], $quotes);
        $this->assertSame([null, null, 1, null], [$follow['tasks'], $follow['contacts'], $follow['expiring']['count'], $follow['alerts']]);

        // Centro de atención + tareas: solo las alertas de tareas (ni sus números de otras áreas).
        $attention = $this->staff(['attention' => 'view', 'tasks' => 'view'], 'Atencion');
        $alerts = app(DashboardSummary::class)->followUp($this->a['company'], $attention)['alerts'];
        $this->assertSame(['Tareas vencidas'], array_column($alerts['items'], 'title'));
        $this->assertSame(0, $alerts['more']);
        Livewire::test(FollowUpPanel::class)->assertDontSee('Órdenes urgentes')->assertDontSee('Cobros vencidos');

        // Cobranzas: el saldo vencido.
        $money = $this->staff(['receivables' => 'view'], 'Cobranzas');
        $this->assertSame(['receivables'], array_keys($this->cards($money)));
    }

    public function test_a_staff_member_without_blocks_gets_a_notice_with_their_modules(): void
    {
        $this->staff(['clients' => 'view'], 'Solo clientes');

        $this->assertTrue(StaffNotice::canView());
        Livewire::test(StaffNotice::class)->assertSee('Tu inicio muestra solo lo de tus áreas')->assertSee('Clientes')
            ->assertDontSee('Presupuestos')->assertDontSee('Órdenes de trabajo');
        $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(StaffNotice::class)->assertDontSeeLivewire(AdminStats::class);
    }

    public function test_permissions_revoked_mid_session_stop_the_updates(): void
    {
        $this->fill($this->a);
        $ana = $this->staff(['work_orders' => 'view', 'quotes' => 'view'], 'Ana');

        $stats = Livewire::test(AdminStats::class)->assertSee('Órdenes pendientes');
        $chart = Livewire::test(QuotesStatusChart::class);

        // El dueño le quita Presupuestos: deja de verse en el próximo pedido.
        $this->actingAs($this->a['admin']);
        app(StaffManager::class)->setPermissions($this->a['admin'], $ana, ['quotes' => 'none']);
        $this->actingAs($ana->fresh());
        $stats->call('$refresh')->assertSee('Órdenes pendientes')->assertDontSee('Presupuestos esperando respuesta');
        $chart->call('$refresh')->assertForbidden();

        // Y sin ninguna área de tarjetas: tampoco las tarjetas.
        $this->actingAs($this->a['admin']);
        app(StaffManager::class)->setPermissions($this->a['admin'], $ana->fresh(), ['work_orders' => 'none']);
        $this->actingAs($ana->fresh());
        $stats->call('$refresh')->assertForbidden();
    }

    public function test_numbers_never_cross_companies(): void
    {
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $this->fill($b);

        $this->inPanel($this->a['admin']);
        $cards = $this->cards($this->a['admin']);
        $this->assertSame(['0', '0', '0', '$0'], [$cards['orders']['value'], $cards['completed_today']['value'], $cards['quotes']['value'], $cards['receivables']['value']]);
        $this->assertSame(['awaiting' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0], app(DashboardSummary::class)->quotesBreakdown($this->a['company'], $this->a['admin']));
        $follow = app(DashboardSummary::class)->followUp($this->a['company'], $this->a['admin']);
        $this->assertSame([0, 0, 0], [$follow['tasks']['open'], $follow['contacts']['count'], $follow['expiring']['count']]);

        $this->inPanel($b['admin']);
        $this->assertSame('2', $this->cards($b['admin'])['orders']['value']);
    }

    public function test_plans_keep_their_dashboard(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->inPanel($this->a['admin']);
        $this->assertFalse(WorkTrendChart::canView()); // la evolución es de «Indicadores de empresa»
        $this->assertTrue(AdminStats::canView());
    }

    public function test_the_superadmin_keeps_the_platform_view_and_enters_companies(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        // Sin empresa elegida: el estado de la plataforma, como antes.
        $this->get('/admin/dashboard')->assertOk()->assertSeeLivewire(PlatformOverviewWidget::class)->assertDontSeeLivewire(AdminStats::class);
        // Con una empresa elegida: el inicio de esa empresa.
        $this->withSession(['selected_company_id' => $this->a['company']->id])->get('/admin/dashboard')->assertOk()
            ->assertSeeLivewire(AdminStats::class)->assertDontSeeLivewire(PlatformOverviewWidget::class);
    }

    public function test_the_cards_use_a_bounded_number_of_queries(): void
    {
        $this->fill($this->a);
        $this->inPanel($this->a['admin']);
        app(DashboardSummary::class)->cards($this->a['admin']); // calienta el plan y la empresa

        DB::enableQueryLog();
        app(DashboardSummary::class)->cards($this->a['admin']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $count, "Las tarjetas hicieron {$count} consultas.");
    }
}
