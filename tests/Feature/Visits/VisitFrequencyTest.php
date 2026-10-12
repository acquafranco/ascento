<?php

namespace Tests\Feature\Visits;

use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\BuildingVisitPlan;
use App\Models\DeliveryNote;
use App\Models\MaintenanceService;
use App\Models\User;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Technicians\TechnicianWorkload;
use App\Services\Visits\VisitPlanService;
use App\Services\Visits\VisitSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Frecuencia real de visitas (Etapa 5): mensual por defecto, quincenal (2
 * visitas en el mes, cada una con su remito) y cada N meses, separada de la
 * frecuencia de cobro, sin reinterpretar el historial, con identidad única
 * por visita y las mismas cuentas en el técnico, el inicio, la Agenda y los
 * recordatorios.
 */
class VisitFrequencyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Building $building;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->building = $this->a['building'];
        $this->tech = $this->a['technician'];
    }

    private function plan(string $frequency, ?int $every = null, string $from = '2026-10', string $type = 'maintenance', ?Building $building = null): void
    {
        [$perMonth, $n, $start] = VisitPlanService::normalize($frequency, $every, $from);
        $this->actingAs($this->a['admin']);
        app(VisitPlanService::class)->set($building ?? $this->building, $type, $perMonth, $n, $start);
    }

    private function sign(int $month = 10, int $year = 2026, array $overrides = [], ?User $as = null, string $type = 'maintenance')
    {
        return $this->actingAs($as ?? $this->tech)->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $this->building->id,
            'description' => 'Mantenimiento preventivo completo.',
            'month' => $month, 'year' => $year,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0,
            'assignment_type' => $type,
            'performed' => '1',
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
            ...$overrides,
        ]);
    }

    private function eval(int $month, int $year = 2026, string $type = 'maintenance'): array
    {
        return VisitSchedule::load([$this->building->id], $month, $year)->evaluate($this->building->id, $type, $month, $year);
    }

    public function test_buildings_without_a_plan_stay_monthly_and_one_remito_per_month(): void
    {
        $this->assertSame(0, BuildingVisitPlan::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->eval(10)['expected']);

        $this->sign()->assertSessionHasNoErrors();
        $this->sign()->assertSessionHasErrors('general');

        $visit = BuildingVisit::sole();
        $this->assertSame('maintenance:2026-10:1', $visit->period_key);
        $this->assertSame(1, $visit->slot);
        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_biweekly_allows_two_remitos_in_the_month_each_with_its_own_visit(): void
    {
        $this->plan('biweekly');

        $this->assertSame(2, $this->eval(10)['expected']);
        $this->sign()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->eval(10)['done']);
        $this->assertSame(1, $this->eval(10)['pending']);

        $this->sign()->assertSessionHasNoErrors();
        $this->sign()->assertSessionHasErrors(['general' => 'Ya se registraron las 2 visitas de este mes.']);

        $this->assertSame(['maintenance:2026-10:1', 'maintenance:2026-10:2'], BuildingVisit::orderBy('slot')->pluck('period_key')->all());
        $this->assertSame(2, DeliveryNote::count());
        $this->assertSame(2, DeliveryNote::pluck('building_visit_id')->unique()->count());
        $this->assertSame(['expected' => 2, 'done' => 2, 'pending' => 0], array_intersect_key($this->eval(10), array_flip(['expected', 'done', 'pending'])));

        // El mes siguiente vuelve a tener dos visitas.
        $this->assertSame(2, $this->eval(11)['expected']);
        $this->assertSame(0, $this->eval(11)['done']);
    }

    public function test_quarterly_counts_one_visit_per_period_from_the_start_month(): void
    {
        $this->plan('months', 3, '2026-10');

        $period = $this->eval(11)['period'];
        $this->assertSame('2026-10', $period->start->format('Y-m'));
        $this->assertSame('2026-12', $period->end->format('Y-m'));
        $this->assertSame('2027-01', $this->eval(1, 2027)['period']->start->format('Y-m'));

        // Pendiente hasta que se hace; se puede hacer en cualquier mes del período.
        $this->assertSame(1, $this->eval(10)['pending']);
        $this->sign(month: 11)->assertSessionHasNoErrors();
        $this->assertSame('maintenance:2026-10:1', BuildingVisit::sole()->period_key);

        // Octubre y diciembre: "hecho en el período", nada previsto ni pendiente.
        foreach ([10, 12] as $month) {
            $e = $this->eval($month);
            $this->assertTrue($e['covered'], "mes {$month}");
            $this->assertSame(0, $e['expected']);
            $this->assertSame(0, $e['pending']);
        }
        $this->assertSame(1, $this->eval(11)['done']);

        // Otro remito en el mismo período se rechaza.
        $this->sign(month: 12)->assertSessionHasErrors('general');
        $this->assertSame(1, BuildingVisit::count());
    }

    public function test_agenda_states_with_longer_frequencies_and_overdue_only_after_the_period(): void
    {
        $this->plan('months', 2, '2026-10');
        $this->actingInPanel($this->a['admin']);
        $agenda = app(MaintenanceAgenda::class);
        $status = fn (int $m, int $y = 2026) => $agenda->rows($m, $y, 'maintenance')->firstWhere('building.id', $this->building->id)['status'];

        $this->assertSame('pending', $status(10));
        $this->assertSame('pending', $status(11)); // el período oct–nov sigue abierto
        $this->assertSame('upcoming', $status(12));

        $this->travelTo(Carbon::parse('2026-12-05'));
        $this->assertSame('overdue', $status(11));

        // Hecho en octubre: noviembre queda "hecho en el período".
        $this->travelTo(Carbon::parse('2026-10-14'));
        BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 10, 'year' => 2026]);
        $this->assertSame('done', $status(10));
        $this->assertSame('covered', $status(11));
    }

    public function test_changing_the_frequency_never_reinterprets_past_months(): void
    {
        // Septiembre mensual sin visita: vencido. Desde octubre, trimestral.
        $this->plan('months', 3, '2026-10');
        $this->actingInPanel($this->a['admin']);
        $agenda = app(MaintenanceAgenda::class);

        $this->assertNull(VisitSchedule::load([$this->building->id], 9, 2026)->period($this->building->id, 'maintenance', 9, 2026)->plan);
        $this->assertSame('overdue', $agenda->rows(9, 2026, 'maintenance')->firstWhere('building.id', $this->building->id)['status']);

        // No se puede programar hacia atrás.
        $this->expectException(ValidationException::class);
        app(VisitPlanService::class)->set($this->building, 'maintenance', 1, 1, CarbonImmutable::create(2026, 9, 1));
    }

    public function test_a_new_frequency_from_a_future_month_keeps_the_current_one_until_then(): void
    {
        $this->plan('biweekly', from: '2026-10');
        $this->plan('monthly', from: '2026-12');

        $this->assertSame(2, $this->eval(11)['expected']);
        $this->assertSame(1, $this->eval(12)['expected']);
        $this->assertSame(2, BuildingVisitPlan::withoutGlobalScopes()->count());
    }

    public function test_visit_frequency_is_independent_from_billing_frequency(): void
    {
        $this->actingInPanel($this->a['admin']);
        $service = MaintenanceService::create(['client_id' => $this->building->client_id, 'building_id' => $this->building->id, 'description' => 'Abono', 'amount' => 90000, 'frequency' => 'quarterly', 'start_date' => '2026-10-01', 'payment_due_day' => 10, 'status' => 'active']);
        $receivables = $service->receivables()->count();

        $this->plan('biweekly');

        // La visita es quincenal; el cobro sigue trimestral y no se generan cobros nuevos.
        $this->assertSame('quarterly', $service->fresh()->frequency);
        $this->assertSame('90000.00', $service->fresh()->amount);
        $this->assertSame($receivables, $service->receivables()->count());
        $this->assertSame(1, MaintenanceService::count());
        $this->assertSame(2, $this->eval(10)['expected']);
    }

    public function test_not_done_remitos_do_not_count_as_done_anywhere(): void
    {
        $this->plan('biweekly');
        $this->sign(overrides: ['performed' => '0'])->assertSessionHasNoErrors();

        $e = $this->eval(10);
        $this->assertSame([0, 1, 1], [$e['done'], $e['not_done'], $e['pending']]);

        // Técnico (Visitas e inicio) y Agenda dicen lo mismo.
        $view = $this->actingAs($this->tech)->get("/{$this->a['company']->slug}/buildings")->assertOk();
        $progress = $view->viewData('progress')['maintenance'];
        // Pendientes = previstas − realizadas (incluye la "no realizada", que se informa aparte).
        $this->assertSame(['assigned' => 1, 'expected' => 2, 'done' => 0, 'not_done' => 1, 'pending' => 2], array_intersect_key($progress, array_flip(['assigned', 'expected', 'done', 'not_done', 'pending'])));

        $home = app(TechnicianWorkload::class)->monthVisits($this->tech)['progress']['maintenance'];
        $this->assertSame($progress, $home);

        $this->actingInPanel($this->a['admin']);
        $agenda = MaintenanceAgenda::progress(app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance'))['maintenance'];
        $this->assertSame($progress['expected'], $agenda['expected']);
        $this->assertSame($progress['done'], $agenda['done']);
        $this->assertSame($progress['pending'], $agenda['pending']);
    }

    public function test_the_technician_screen_shows_progress_by_visits_and_the_second_visit(): void
    {
        $this->plan('biweekly');
        $this->sign()->assertSessionHasNoErrors();

        $this->actingAs($this->tech)->get("/{$this->a['company']->slug}/buildings")->assertOk()
            ->assertSee('data-visit-progress="maintenance"', false)
            ->assertSee('Visita 1 de 2 realizada')
            ->assertSee('Marcar 2ª visita')
            ->assertSee('Quincenal (2 por mes)');
    }

    public function test_a_month_without_a_due_visit_is_shown_as_not_pending(): void
    {
        $this->plan('months', 3, '2026-10');
        $this->sign(month: 10)->assertSessionHasNoErrors();

        $this->actingAs($this->tech)->get("/{$this->a['company']->slug}/buildings?month=11&year=2026")->assertOk()
            ->assertSee('No corresponde visitar este mes')
            ->assertDontSee('Marcar mantenimiento');
    }

    public function test_the_database_rejects_a_duplicated_visit_even_without_the_app_checks(): void
    {
        BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 10, 'year' => 2026]);

        $this->expectException(UniqueConstraintViolationException::class);
        BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 10, 'year' => 2026]);
    }

    public function test_work_order_visits_never_take_a_periodic_slot(): void
    {
        BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'visit_type' => 'work_order', 'assignment_type' => 'work_order', 'source' => 'work_order']);

        $this->assertNull(BuildingVisit::sole()->period_key);
        $this->sign()->assertSessionHasNoErrors();
    }

    public function test_legacy_duplicates_without_identity_are_counted_once(): void
    {
        BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 10, 'year' => 2026]);
        DB::table('building_visits')->insert([
            'company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'user_id' => $this->tech->id,
            'source' => 'building', 'visit_type' => 'fixed', 'assignment_type' => 'maintenance', 'status' => 'done',
            'month' => 10, 'year' => 2026, 'slot' => 1, 'period_key' => null, 'visited_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $e = $this->eval(10);
        $this->assertSame(1, $e['done']);
        $this->assertCount(1, $e['visits']);

        $this->artisan('visits:check-duplicates')->expectsOutputToContain('Grupos duplicados: 1')->assertSuccessful();
    }

    public function test_the_migration_keys_one_visit_per_month_and_keeps_the_rest_as_history(): void
    {
        $migration = require database_path('migrations/2026_11_02_100000_create_visit_plans_and_visit_slots.php');
        $migration->down();

        $row = fn (array $extra) => [
            'company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'user_id' => $this->tech->id,
            'source' => 'building', 'visit_type' => 'fixed', 'assignment_type' => 'maintenance', 'status' => 'done',
            'month' => 9, 'year' => 2026, 'visited_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$extra,
        ];
        $first = DB::table('building_visits')->insertGetId($row([]));
        $withNote = DB::table('building_visits')->insertGetId($row([]));
        $other = DB::table('building_visits')->insertGetId($row(['month' => 8]));
        $workOrder = DB::table('building_visits')->insertGetId($row(['visit_type' => 'work_order', 'assignment_type' => 'work_order', 'source' => 'work_order']));
        DB::table('delivery_notes')->insert([
            'company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'building_visit_id' => $withNote,
            'user_id' => $this->tech->id, 'number' => 1, 'assignment_type' => 'maintenance', 'description' => 'x', 'month' => 9, 'year' => 2026,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'performed' => true, 'signature_name' => 'T', 'signature' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $keys = DB::table('building_visits')->pluck('period_key', 'id');
        $this->assertSame('maintenance:2026-09:1', $keys[$withNote]); // la que tiene remito
        $this->assertNull($keys[$first]);                            // duplicado: historial sin clave
        $this->assertSame('maintenance:2026-08:1', $keys[$other]);
        $this->assertNull($keys[$workOrder]);
        $this->assertSame(4, DB::table('building_visits')->count()); // no se borró nada
    }

    public function test_reminders_follow_the_frequency(): void
    {
        $other = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $other->users()->attach($this->tech->id, ['type' => 'maintenance']);
        $this->plan('biweekly');                       // edificio principal: 2 visitas en octubre
        $this->plan('months', 3, '2026-10', building: $other); // trimestral: vence en diciembre
        auth()->logout();

        $this->travelTo(Carbon::parse('2026-10-21 08:00'));
        $this->artisan('notifications:visits')->assertSuccessful();
        $note = $this->tech->fresh()->notifications()->latest()->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Te quedan 2 visitas', json_encode($note->data, JSON_UNESCAPED_UNICODE));
    }

    public function test_other_companies_never_mix(): void
    {
        $b = $this->makeTenant();
        BuildingVisit::factory()->create(['building_id' => $b['building']->id, 'user_id' => $b['technician']->id, 'month' => 10, 'year' => 2026]);
        [$perMonth, $n, $start] = VisitPlanService::normalize('biweekly', null, '2026-10');
        $this->actingAs($b['admin']);
        app(VisitPlanService::class)->set($b['building'], 'maintenance', $perMonth, $n, $start);

        $this->assertSame(1, $this->eval(10)['expected']);
        $this->assertSame(0, $this->eval(10)['done']);

        // Un admin no puede crear un plan para el edificio de otra empresa.
        $this->actingAs($this->a['admin']);
        $this->expectException(HttpException::class);
        $plan = new BuildingVisitPlan(['building_id' => $b['building']->id, 'type' => 'maintenance', 'visits_per_month' => 2, 'every_months' => 1, 'starts_on' => '2026-10-01']);
        $plan->save();
    }

    public function test_resending_the_same_remito_never_creates_another_visit(): void
    {
        // Quincenal: sin identidad del formulario, el reenvío quedaba como 2ª visita.
        $this->plan('biweekly');
        $token = (string) Str::uuid();

        $this->sign(overrides: ['submission_token' => $token])->assertSessionHasNoErrors()->assertRedirect();
        $this->sign(overrides: ['submission_token' => $token])->assertSessionHasNoErrors()
            ->assertRedirect("/{$this->a['company']->slug}/delivery-notes")->assertSessionHas('success');

        $this->assertSame(1, BuildingVisit::count());
        $this->assertSame(1, DeliveryNote::count());
        $this->assertSame(1, $this->eval(10)['pending']);

        // Un remito nuevo (otro formulario) sí es la 2ª visita.
        $this->sign(overrides: ['submission_token' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame([1, 2], BuildingVisit::orderBy('slot')->pluck('slot')->all());

        // El token de otro técnico no sirve para "dar por hecho" nada ni crea visitas.
        $colleague = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->building->users()->attach($colleague->id, ['type' => 'maintenance']);
        $this->plan('months', 3, '2026-11');
        $this->sign(month: 11, overrides: ['submission_token' => $token], as: $colleague)->assertSessionHasErrors('general');
        $this->assertSame(2, BuildingVisit::count());
    }

    public function test_the_remito_form_carries_its_own_identity(): void
    {
        $page = $this->actingAs($this->tech)->get("/{$this->a['company']->slug}/delivery-notes/create/building/{$this->building->id}?assignment_type=maintenance&month=10&year=2026")->assertOk();
        $this->assertMatchesRegularExpression('/name="submission_token" value="[0-9a-f-]{36}"/', $page->getContent());
    }

    public function test_two_biweekly_visits_are_legitimate_but_never_a_third_even_without_the_app_checks(): void
    {
        $this->plan('biweekly');
        $make = fn (int $slot) => BuildingVisit::create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'visit_type' => 'fixed', 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'slot' => $slot, 'status' => 'done', 'visited_at' => now(), 'source' => 'building']);

        $make(1);
        $make(2);
        $this->assertSame(['maintenance:2026-10:1', 'maintenance:2026-10:2'], BuildingVisit::orderBy('slot')->pluck('period_key')->all());

        // Dos técnicos que calcularon el mismo número de visita a la vez: la base rechaza al segundo.
        $this->expectException(UniqueConstraintViolationException::class);
        $make(2);
    }

    public function test_biweekly_states_with_zero_one_and_two_visits(): void
    {
        $this->plan('biweekly');
        $this->actingInPanel($this->a['admin']);
        $status = fn (int $m) => app(MaintenanceAgenda::class)->rows($m, 2026, 'maintenance')->firstWhere('building.id', $this->building->id);

        $this->assertSame(['pending', 2, 0], [$status(10)['status'], $status(10)['expected'], $status(10)['done']]);
        $this->sign()->assertSessionHasNoErrors();
        $this->actingInPanel($this->a['admin']);
        $this->assertSame(['pending', 1, 1], [$status(10)['status'], $status(10)['done'], $status(10)['pending']]);

        // Si octubre termina con una sola visita, queda vencido (1 de 2).
        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        $this->assertSame(['overdue', 1, 1], [$status(10)['status'], $status(10)['done'], $status(10)['pending']]);
        $this->assertSame('pending', $status(11)['status']);
        $this->assertSame('upcoming', $status(12)['status']);

        $this->travelTo(Carbon::parse('2026-10-20 09:00'));
        $this->sign()->assertSessionHasNoErrors();
        $this->actingInPanel($this->a['admin']);
        $this->assertSame(['done', 2, 0], [$status(10)['status'], $status(10)['done'], $status(10)['pending']]);
    }

    public function test_longer_frequencies_count_one_visit_per_period_across_the_year_change(): void
    {
        foreach ([2 => ['2026-10', '2026-11'], 3 => ['2026-10', '2026-12'], 6 => ['2026-10', '2027-03'], 12 => ['2026-10', '2027-09']] as $every => [$from, $to]) {
            DeliveryNote::query()->delete();
            BuildingVisit::query()->delete();
            BuildingVisitPlan::withoutGlobalScopes()->delete();
            $this->plan('months', $every, '2026-10');

            [$endYear, $endMonth] = array_map('intval', explode('-', $to));
            $period = $this->eval($endMonth, $endYear)['period'];
            $this->assertSame([$from, $to], [$period->start->format('Y-m'), $period->end->format('Y-m')], "cada {$every} meses");

            // Visita en el ÚLTIMO mes del período (cruza el año en 6 y 12).
            $this->sign(month: $endMonth, year: $endYear)->assertSessionHasNoErrors();
            $this->assertSame(sprintf('maintenance:%s:1', $from), BuildingVisit::sole()->period_key);
            $this->assertTrue($this->eval(10)['covered'], "cada {$every} meses: octubre ya cubierto");
            $this->assertSame(0, $this->eval(10)['pending']);
            $this->sign(month: 10)->assertSessionHasErrors('general'); // una sola visita por período

            // El período siguiente vuelve a tener una visita prevista.
            $next = CarbonImmutable::create($endYear, $endMonth, 1)->addMonth();
            $this->assertSame([1, 0, 1], [$this->eval($next->month, $next->year)['expected'], $this->eval($next->month, $next->year)['done'], $this->eval($next->month, $next->year)['pending']]);
        }
    }

    public function test_monthly_buildings_without_a_plan_cross_december_into_january(): void
    {
        $this->sign(month: 12)->assertSessionHasNoErrors();
        $this->assertSame([1, 1], [$this->eval(12)['expected'], $this->eval(12)['done']]);
        $this->assertSame([1, 0, 1], [$this->eval(1, 2027)['expected'], $this->eval(1, 2027)['done'], $this->eval(1, 2027)['pending']]);
        $this->sign(month: 1, year: 2027)->assertSessionHasNoErrors();
        $this->assertSame('maintenance:2027-01:1', BuildingVisit::orderByDesc('id')->first()->period_key);
    }

    public function test_a_frequency_change_applies_from_the_next_valid_period_and_never_rewrites_history(): void
    {
        // Trimestral agosto–octubre; la visita del período se hace en octubre.
        $this->travelTo(Carbon::parse('2026-08-03 09:00'));
        $this->plan('months', 3, '2026-08');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->sign(month: 10)->assertSessionHasNoErrors();
        $this->assertSame('maintenance:2026-08:1', BuildingVisit::sole()->period_key);

        // Pedido "mensual desde octubre": octubre cae en medio del período,
        // así que rige desde noviembre (el siguiente período válido).
        $this->assertSame('2026-11', app(VisitPlanService::class)->effectiveStart($this->building, 'maintenance', CarbonImmutable::parse('2026-10-01'))->format('Y-m'));
        $this->plan('monthly', from: '2026-10');

        $plans = BuildingVisitPlan::withoutGlobalScopes()->orderBy('starts_on')->get();
        $this->assertSame(['2026-08-01', '2026-11-01'], $plans->map(fn ($p) => $p->starts_on->toDateString())->all());

        // Agosto–octubre queda intacto y hecho; agosto y septiembre no aparecen vencidos.
        $this->assertSame(['2026-08', '2026-10', 1], [$this->eval(10)['period']->start->format('Y-m'), $this->eval(10)['period']->end->format('Y-m'), $this->eval(10)['done']]);
        $this->assertTrue($this->eval(8)['covered']);
        $this->assertTrue($this->eval(9)['covered']);
        $this->actingInPanel($this->a['admin']);
        $this->assertSame('covered', app(MaintenanceAgenda::class)->rows(9, 2026, 'maintenance')->firstWhere('building.id', $this->building->id)['status']);
        $this->assertSame(1, $this->eval(11)['pending']);

        // Sin visita registrada pasa lo mismo: el período en curso nunca se corta.
        BuildingVisitPlan::withoutGlobalScopes()->delete();
        $this->travelTo(Carbon::parse('2026-08-03 09:00'));
        $this->plan('months', 3, '2026-08');
        $this->travelTo(Carbon::parse('2026-09-10 09:00'));
        $this->plan('biweekly', from: '2026-09');
        $this->assertSame(['2026-08-01', '2026-11-01'], BuildingVisitPlan::withoutGlobalScopes()->orderBy('starts_on')->get()->map(fn ($p) => $p->starts_on->toDateString())->all());

        // Al inicio de un período sí rige ese mismo mes (noviembre ya es inicio).
        $this->assertSame('2026-11', app(VisitPlanService::class)->effectiveStart($this->building, 'maintenance', CarbonImmutable::parse('2026-11-01'))->format('Y-m'));

        // La acción del panel avisa desde cuándo rige de verdad.
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        BuildingVisitPlan::withoutGlobalScopes()->delete();
        $this->travelTo(Carbon::parse('2026-08-03 09:00'));
        $this->plan('months', 3, '2026-08');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListBuildings::class)
            ->callTableAction('visitFrequency', $this->building, data: ['type' => 'maintenance', 'frequency' => 'biweekly', 'starts_on' => '2026-10'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();
        $this->assertSame(['2026-08-01', '2026-11-01'], BuildingVisitPlan::withoutGlobalScopes()->orderBy('starts_on')->get()->map(fn ($p) => $p->starts_on->toDateString())->all());
    }

    public function test_with_only_monthly_buildings_the_schedule_reads_just_the_month(): void
    {
        DB::enableQueryLog();
        VisitSchedule::load([$this->building->id], 10, 2026)->evaluate($this->building->id, 'maintenance', 10, 2026);
        // Sin comillas de identificadores: igual en SQLite ("year") y MySQL (`year`).
        $sql = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => str_replace(['"', '`'], '', $q))
            ->first(fn ($q) => str_contains($q, 'from building_visits') && str_contains($q, 'period_key'));
        DB::disableQueryLog();

        $this->assertNotNull($sql);
        $this->assertStringContainsString('year = ?', $sql);
        $this->assertStringContainsString('month = ?', $sql);
        $this->assertStringNotContainsString('year * 12', $sql);
    }
}
