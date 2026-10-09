<?php

namespace Tests\Feature\Insights;

use App\Enums\PlanFeature;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\AttentionCenterPage;
use App\Filament\Pages\IndicatorsPage;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Insights\AttentionCenter;
use App\Services\Insights\FailureAnalysis;
use App\Services\Insights\Indicators;
use App\Services\Insights\MaintenanceAgenda;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Agenda, centro de atención, indicadores y análisis de fallas: reglas,
 * planes (backend), aislamiento entre empresas y roles.
 */
class InsightsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-25 10:00'));
        $this->a = $this->makeTenant(); // b1 con mantenimiento del técnico
        $this->b = $this->makeTenant();
    }

    private function plan(string $slug, ?array $tenant = null): void
    {
        $tenant ??= $this->a;
        Subscription::updateOrCreate(['company_id' => $tenant['company']->id], ['provider' => 'mercadopago', 'plan' => $slug, 'status' => 'authorized', 'amount' => 1, 'current_period_end' => now()->addMonth()]);
        $tenant['company']->forgetPlan();
    }

    private function visit(array $tenant, string $type, int $month, ?Building $building = null): BuildingVisit
    {
        return BuildingVisit::factory()->create([
            'building_id' => ($building ?? $tenant['building'])->id, 'user_id' => $tenant['technician']->id,
            'assignment_type' => $type, 'month' => $month, 'year' => 2026, 'visited_at' => Carbon::create(2026, $month, 5),
        ]);
    }

    /** Crea datos como invitado: logueado, los modelos toman la empresa del usuario actual. */
    private function asGuest(callable $callback): void
    {
        $user = auth()->user();
        auth()->logout();
        $callback();
        if ($user) {
            $this->actingInPanel($user);
        }
    }

    private function interventions(array $tenant, string $label, array $components, int $daysAgo = 10): void
    {
        foreach ($components as $i => $component) {
            $this->travel(-$daysAgo)->days();
            $i % 2 === 0
                ? Report::factory()->create(['building_id' => $tenant['building']->id, 'user_id' => $tenant['technician']->id, 'elevator_number' => $label, 'component' => $component])
                : WorkOrder::factory()->create(['building_id' => $tenant['building']->id, 'unit' => $label, 'type' => 'claim', 'status' => 'completed', 'component' => $component]);
            $this->travelBack();
            $this->travelTo(Carbon::parse('2026-10-25 10:00'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PLANES
    |--------------------------------------------------------------------------
    */

    public function test_the_three_plans_get_the_right_new_features_without_losing_any(): void
    {
        $plans = SubscriptionPlan::whereIn('slug', ['inicial', 'profesional', 'empresa'])->get()->keyBy('slug');

        foreach ([PlanFeature::Agenda, PlanFeature::AttentionCenter, PlanFeature::ElevatorFile, PlanFeature::Indicators, PlanFeature::Map, PlanFeature::Reports] as $core) {
            $this->assertTrue($plans['inicial']->allows($core), $core->value);
        }

        foreach ([PlanFeature::AttentionAdvanced, PlanFeature::CompanyIndicators, PlanFeature::ElevatorHistoryAdvanced, PlanFeature::FailureAnalysis, PlanFeature::Quotes] as $pro) {
            $this->assertFalse($plans['inicial']->allows($pro), $pro->value);
            $this->assertTrue($plans['profesional']->allows($pro), $pro->value);
            $this->assertTrue($plans['empresa']->allows($pro), $pro->value);
        }

        foreach ([PlanFeature::AdvancedIndicators, PlanFeature::AdvancedAlerts] as $empresa) {
            $this->assertFalse($plans['profesional']->allows($empresa));
            $this->assertTrue($plans['empresa']->allows($empresa));
        }

        // Precios y límites intactos.
        $this->assertEquals([69000, 119000, 169000], [$plans['inicial']->price, $plans['profesional']->price, $plans['empresa']->price]);
        $this->assertSame([20, 70, 300], [$plans['inicial']->maxBuildings(), $plans['profesional']->maxBuildings(), $plans['empresa']->maxBuildings()]);
    }

    public function test_the_plan_migration_only_adds_keys_and_rolls_back_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_20_100400_add_new_features_to_plans.php');

        $profesional = fn () => SubscriptionPlan::where('slug', 'profesional')->sole();

        $migration->down();
        $this->assertFalse($profesional()->allows(PlanFeature::FailureAnalysis));
        $this->assertTrue($profesional()->allows(PlanFeature::Quotes)); // lo de antes queda

        $migration->up();
        $migration->up(); // idempotente
        $keys = $profesional()->feature_keys;
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertTrue($profesional()->allows(PlanFeature::FailureAnalysis));
    }

    public function test_pages_check_the_plan_in_the_backend_without_errors(): void
    {
        $this->actingInPanel($this->a['admin']);

        // Inicial: las páginas abren, las secciones de otros planes no traen datos.
        $this->plan('inicial');
        $this->interventions($this->a, 'Ascensor 1', ['doors', 'doors', 'controller']);
        $this->get(AttentionCenterPage::getUrl())->assertOk()->assertSee('Disponible en el plan Profesional')->assertDontSee('Ascensores con fallas que se repiten');
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee('Disponible en el plan Profesional')->assertDontSee('¿Cómo trabaja cada técnico?');
        $this->get(Agenda::getUrl())->assertOk();

        $sections = app(AttentionCenter::class)->sections($this->a['company']);
        $this->assertNull($sections['advanced']);
        $this->assertNull($sections['alerts']);
        $this->assertNull(app(Indicators::class)->sections($this->a['company'])['company']);

        // Profesional: avanzado sí, Empresa no.
        $this->plan('profesional');
        $this->get(AttentionCenterPage::getUrl())->assertOk()->assertSee('Ascensores con fallas que se repiten')->assertSee('Disponible en el plan Empresa');
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee('¿Cómo trabaja cada técnico?')->assertDontSee('Este año contra el anterior');

        // Empresa: todo.
        $this->plan('empresa');
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertSee('Este año contra el anterior')->assertSee('Tu cartera');
        $this->get(AttentionCenterPage::getUrl())->assertOk()->assertDontSee('Disponible en el plan');

        // Si un plan no incluyera la función: explica y lleva a planes (no 403/500, sin datos).
        $plan = SubscriptionPlan::findBySlug('empresa');
        $plan->update(['feature_keys' => array_values(array_diff($plan->feature_keys, ['agenda']))]);
        $this->a['company']->forgetPlan();
        $this->get(Agenda::getUrl())->assertRedirect();
    }

    public function test_technicians_cannot_open_the_new_screens(): void
    {
        foreach ([Agenda::getUrl(), AttentionCenterPage::getUrl(), IndicatorsPage::getUrl()] as $url) {
            $this->actingAs($this->a['technician'])->get($url)->assertRedirect();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AGENDA
    |--------------------------------------------------------------------------
    */

    public function test_agenda_states_and_filters(): void
    {
        $this->actingInPanel($this->a['admin']);
        $agenda = app(MaintenanceAgenda::class);
        $b2 = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id, 'name' => 'Torre Sin Técnico']);
        MaintenanceService::create(['client_id' => $b2->client_id, 'building_id' => $b2->id, 'description' => 'Abono', 'amount' => 1000, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);
        $this->visit($this->a, 'maintenance', 8);

        $status = fn (int $month) => $agenda->rows($month, 2026, 'maintenance')->mapWithKeys(fn ($r) => [$r['building']->name => $r['status']])->all();

        $this->assertSame('done', $status(8)[$this->a['building']->name]);
        $this->assertSame('overdue', $status(9)[$this->a['building']->name]);
        $this->assertSame('pending', $status(10)[$this->a['building']->name]);
        $this->assertSame('upcoming', $status(11)[$this->a['building']->name]);
        $this->assertSame('unassigned', $status(10)['Torre Sin Técnico']);

        // Lo urgente primero; filtros por estado y técnico.
        $this->assertSame('unassigned', $agenda->rows(10, 2026)->first()['status']);
        $this->assertCount(1, $agenda->rows(10, 2026, status: 'pending'));
        $this->assertCount(0, $agenda->rows(10, 2026, technicianId: User::factory()->technician()->create(['company_id' => $this->a['company']->id])->id));

        // La empresa B no aparece nunca.
        $this->assertFalse($agenda->rows(10, 2026)->contains(fn ($r) => $r['building']->id === $this->b['building']->id));
    }

    public function test_visits_are_monthly_whatever_the_billing_frequency_of_the_contract(): void
    {
        $this->actingInPanel($this->a['admin']);
        $agenda = app(MaintenanceAgenda::class);
        $this->a['building']->users()->detach(); // sin técnico: la fila sale del contrato

        foreach (array_keys(MaintenanceService::FREQUENCIES) as $frequency) {
            MaintenanceService::query()->delete();
            $service = MaintenanceService::create(['client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id, 'description' => "Abono {$frequency}", 'amount' => 120000, 'frequency' => $frequency, 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);

            // La agenda pide la visita TODOS los meses del contrato (nunca saltea por la frecuencia de cobro).
            foreach ([7, 8, 9, 10, 11] as $month) {
                $this->assertTrue($agenda->rows($month, 2026, 'maintenance')->contains(fn ($r) => $r['building']->id === $this->a['building']->id), "{$frequency}: mes {$month}");
            }

            // Hecho este mes → la próxima visita es el mes que viene.
            $visit = $this->visit($this->a, 'maintenance', 10);
            $this->assertSame('2026-11-01', $service->fresh()->visitStatus('maintenance')['next']->toDateString(), $frequency);
            $visit->delete();

            // El cobro sí sigue la frecuencia del contrato (períodos de N meses).
            $this->assertSame(MaintenanceService::FREQUENCIES[$frequency][1], $service->monthsPerPeriod());
        }

        $this->assertSame(1, MaintenanceAgenda::VISIT_EVERY_MONTHS);
    }

    public function test_contract_dates_bound_the_agenda(): void
    {
        $this->actingInPanel($this->a['admin']);
        $this->a['building']->users()->detach();
        MaintenanceService::create(['client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id, 'description' => 'Abono', 'amount' => 1, 'frequency' => 'quarterly', 'start_date' => '2026-09-01', 'end_date' => '2026-10-31', 'payment_due_day' => 10, 'status' => 'active']);
        $agenda = app(MaintenanceAgenda::class);
        $has = fn (int $m) => $agenda->rows($m, 2026, 'maintenance')->contains(fn ($r) => $r['building']->id === $this->a['building']->id);

        $this->assertFalse($has(8));   // antes del inicio
        $this->assertTrue($has(9));
        $this->assertTrue($has(10));
        $this->assertFalse($has(11));  // después del fin
    }

    public function test_agenda_page_ignores_tampered_filters(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(Agenda::class)->assertSet('month', '2026-10'); // sin filtro: el mes actual

        Livewire::withQueryParams(['month' => '1900-13', 'status' => 'hackeado', 'type' => '<script>', 'technician' => (string) $this->b['technician']->id])
            ->test(Agenda::class)
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertDontSee($this->b['building']->name)
            ->assertDontSee('<script>', false);
    }

    /*
    |--------------------------------------------------------------------------
    | ANÁLISIS DE FALLAS
    |--------------------------------------------------------------------------
    */

    public function test_failure_analysis_separates_reports_claims_and_resolved_claims(): void
    {
        $this->actingInPanel($this->a['admin']);
        // 4 reportes (pares) y 3 reclamos completados (impares) → 7 avisos.
        $this->interventions($this->a, 'Ascensor 1', ['doors', 'doors', 'doors', 'doors', 'controller', 'controller', 'controller']);
        // Un reclamo abierto (no resuelto): cuenta como aviso, NO como intervención hecha.
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'type' => 'claim', 'status' => 'pending', 'component' => 'doors']);
        // No cuentan: fuera de los 90 días, otro equipo, otra empresa, órdenes que no son reclamo, órdenes eliminadas.
        $this->interventions($this->a, 'Ascensor 1', ['doors', 'doors'], daysAgo: 120);
        $this->interventions($this->a, 'Ascensor 2', ['motor']);
        $this->asGuest(fn () => $this->interventions($this->b, 'Ascensor 1', ['doors', 'doors', 'doors', 'doors']));
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'type' => 'installation', 'status' => 'completed']);
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'type' => 'claim', 'status' => 'pending'])->delete();

        $elevator = Elevator::where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->sole();
        $r = app(FailureAnalysis::class)->forElevator($elevator);

        $this->assertSame(4, $r['reports']);
        $this->assertSame(4, $r['claims']);   // 3 completados + 1 abierto
        $this->assertSame(3, $r['done']);     // solo los completados son intervenciones hechas
        $this->assertSame(8, $r['signals']);
        $this->assertSame(['Puertas y operador' => 5, 'Maniobra / controlador' => 3], $r['by_component']);
        $this->assertTrue($r['recurrent']);
        $this->assertSame("Ascensor 1 ({$this->a['building']->name}): 8 avisos de falla en los últimos 90 días (4 reportes y 4 reclamos; 3 reclamos resueltos). Por componente: 5 de puertas y operador, 3 de maniobra / controlador.", $r['message']);

        // A nivel empresa: un solo equipo reincidente, con los mismos números (sin duplicar).
        $recurrent = app(FailureAnalysis::class)->recurrent();
        $this->assertCount(1, $recurrent);
        $this->assertSame($elevator->id, $recurrent->first()['elevator']->id);
        $this->assertSame([8, 4, 4, 3], [$recurrent->first()['signals'], $recurrent->first()['reports'], $recurrent->first()['claims'], $recurrent->first()['done']]);
    }

    public function test_the_90_day_window_is_exact_and_windows_do_not_overlap(): void
    {
        $this->actingInPanel($this->a['admin']);
        $elevator = Elevator::where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->sole();
        $make = fn (CarbonInterface $at) => Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 1', 'created_at' => $at]);

        $make(now()->subDays(90)->addMinute());   // adentro
        $make(now()->subDays(90));                // justo en el borde: ventana (desde, hasta] → afuera
        $make(now()->subDays(91));                // afuera (cae en la ventana anterior)

        $this->assertSame(1, app(FailureAnalysis::class)->forElevator($elevator)['reports']);
        $this->assertSame(2, app(FailureAnalysis::class)->forElevator($elevator, until: now()->subDays(90))['reports']); // ventana anterior
        // Cada reporte cae en una sola ventana.
        $this->assertSame(3, app(FailureAnalysis::class)->forElevator($elevator)['reports'] + app(FailureAnalysis::class)->forElevator($elevator, until: now()->subDays(90))['reports']);
    }

    /*
    |--------------------------------------------------------------------------
    | CENTRO DE ATENCIÓN
    |--------------------------------------------------------------------------
    */

    public function test_attention_center_lists_what_needs_action_and_only_for_the_own_company(): void
    {
        $this->plan('empresa');
        $this->actingInPanel($this->a['admin']);

        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'pending', 'priority' => 'urgent', 'created_at' => now()->subDays(5)]);
        Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'priority' => 'critica', 'status' => 'pendiente']);
        Quote::factory()->create(['building_id' => $this->a['building']->id, 'status' => Quote::SENT, 'valid_until' => today()->addDays(3)]);
        $elevator = Elevator::where('building_id', $this->a['building']->id)->first();
        $doc = new ElevatorDocument(['elevator_id' => $elevator->id, 'type' => 'certificate', 'title' => 'Habilitación', 'expires_at' => today()->addDays(10)]);
        $doc->forceFill(['path' => 'elevators/'.$this->a['company']->id.'/x.pdf'])->save();
        MaintenanceService::create(['client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id, 'description' => 'Abono', 'amount' => 1000, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'end_date' => today()->addDays(20), 'payment_due_day' => 10, 'status' => 'active']);
        $this->interventions($this->a, 'Ascensor 1', ['doors', 'doors', 'doors']);

        // La empresa B tiene muchas cosas pendientes: no deben aparecer.
        $this->asGuest(fn () => WorkOrder::factory()->count(9)->create(['building_id' => $this->b['building']->id, 'status' => 'pending', 'priority' => 'urgent', 'created_at' => now()->subDays(5)]));

        $sections = app(AttentionCenter::class)->sections($this->a['company']);
        $titles = collect($sections['basic'])->merge($sections['advanced'])->merge($sections['alerts'])->pluck('count', 'title');

        $this->assertSame(1, $titles['Órdenes sin tomar hace más de 2 días']);
        $this->assertSame(1, $titles['Órdenes urgentes o de prioridad alta abiertas']);
        $this->assertSame(1, $titles['Reportes de prioridad alta o crítica sin revisar']);
        $this->assertSame(1, $titles['Presupuestos enviados que vencen en 5 días']);
        $this->assertSame(1, $titles['Mantenimientos vencidos']);               // septiembre sin remito
        $this->assertSame(1, $titles['Mantenimientos pendientes del mes']);     // día 25: el mes se termina
        $this->assertSame(1, $titles['Ascensores con fallas que se repiten']);
        $this->assertSame(1, $titles['Certificados vencidos o por vencer']);
        $this->assertSame(1, $titles['Contratos que terminan en los próximos 30 días']);
        $this->assertArrayHasKey('Ascensores con la ficha técnica incompleta', $titles->all());

        // Todo en orden → nada que mostrar (no estadísticas de relleno).
        auth()->logout();
        $empty = $this->makeTenant();
        $empty['building']->users()->detach();
        $this->actingInPanel($empty['admin']);
        $this->assertCount(0, app(AttentionCenter::class)->basic($empty['company']));
        $this->get(AttentionCenterPage::getUrl())->assertOk()->assertSee('Nada pendiente acá');
    }

    /*
    |--------------------------------------------------------------------------
    | INDICADORES
    |--------------------------------------------------------------------------
    */

    public function test_indicators_answer_questions_with_the_own_company_numbers(): void
    {
        $this->plan('empresa');
        $this->actingInPanel($this->a['admin']);

        $this->visit($this->a, 'maintenance', 10);                    // mantenimiento de octubre hecho
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'pending']);
        $done = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'completed', 'created_at' => now()->subDays(4), 'finished_at' => now()->subDays(2)]);
        $done->participants()->attach($this->a['technician']->id, ['role' => 'participant']);
        MaintenanceService::create(['client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id, 'description' => 'Abono', 'amount' => 300000, 'frequency' => 'quarterly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);

        // Ruido de la empresa B.
        $this->asGuest(function () {
            WorkOrder::factory()->count(5)->create(['building_id' => $this->b['building']->id, 'status' => 'pending']);
            $this->visit($this->b, 'maintenance', 9);
        });

        $sections = app(Indicators::class)->sections($this->a['company']);
        $basic = collect($sections['basic'])->keyBy('question');

        $this->assertSame('100%', $basic['¿Estamos cumpliendo los mantenimientos de este mes?']['value']);
        $this->assertSame('1', $basic['¿Cuánto trabajo tenemos abierto?']['value']);

        // Solo técnicos de la empresa (User no tiene scope global: regresión).
        $names = collect($sections['company']['technicians'])->pluck('name');
        $this->assertTrue($names->contains($this->a['technician']->name));
        $this->assertFalse($names->contains($this->b['technician']->name));
        $this->get(IndicatorsPage::getUrl())->assertDontSee($this->b['technician']->name);

        $tech = collect($sections['company']['technicians'])->firstWhere('name', $this->a['technician']->name);
        $this->assertSame(1, $tech['orders']);
        $this->assertSame(2.0, $tech['close_days']);

        $portfolio = collect($sections['advanced']['portfolio'])->keyBy('question');
        $this->assertSame('$100.000', $portfolio['¿Cuánto factura la cartera por mes?']['value']);  // 300.000 trimestral
        $this->assertSame('100%', $portfolio['¿Dependemos de pocos clientes?']['value']);
    }
}
