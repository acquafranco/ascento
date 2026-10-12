<?php

namespace Tests\Feature\Visits;

use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Models\Building;
use App\Models\BuildingVisitPlan;
use App\Models\User;
use App\Services\Buildings\BulkAssignmentService;
use App\Services\Notifications\AssignmentNotifier;
use App\Services\Visits\VisitPlanService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Asignación masiva de técnicos: agregar / reemplazar / conservar, con los
 * límites de siempre (2 de mantenimiento, 1 inspector), resumen previo,
 * edificios salteados con motivo, todo revalidado en el servidor, atómico y
 * con los avisos después de guardar. La asignación individual no cambia.
 */
class BulkAssignmentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private User $t2;

    private User $t3;

    private Collection $buildings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant(); // edificio 1 ya tiene a "technician" en mantenimiento
        $company = $this->a['company']->id;
        $this->t2 = User::factory()->technician()->create(['company_id' => $company, 'name' => 'Técnica Dos']);
        $this->t3 = User::factory()->technician()->create(['company_id' => $company, 'name' => 'Técnico Tres']);
        $this->buildings = collect([$this->a['building'], ...Building::factory()->count(2)->create(['company_id' => $company])]);
        $this->actingInPanel($this->a['admin']);
    }

    private function service(): BulkAssignmentService
    {
        return app(BulkAssignmentService::class);
    }

    private function ids(): array
    {
        return $this->buildings->pluck('id')->all();
    }

    private function techsOf(Building $b, string $type = 'maintenance'): array
    {
        return $b->users()->wherePivot('type', $type)->pluck('users.id')->sort()->values()->all();
    }

    public function test_add_mode_respects_the_limit_and_skips_with_a_reason(): void
    {
        $this->a['building']->users()->attach($this->t3->id, ['type' => 'maintenance']); // ya tiene 2

        $plan = $this->service()->plan($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add']);

        $this->assertSame(['change' => 2, 'unchanged' => 0, 'skipped' => 1], $plan['counts']);
        $skipped = collect($plan['rows'])->firstWhere('outcome', 'skipped');
        $this->assertSame($this->a['building']->id, $skipped['building_id']);
        $this->assertStringContainsString('límite de 2', $skipped['reason']);

        $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add']);

        $this->assertCount(2, $this->techsOf($this->a['building']->fresh()));           // sin cambios
        $this->assertNotContains($this->t2->id, $this->techsOf($this->a['building']));
        $this->assertSame([$this->t2->id], $this->techsOf($this->buildings[1]));
        $this->assertSame([$this->t2->id], $this->techsOf($this->buildings[2]));
    }

    public function test_replace_and_keep_modes(): void
    {
        $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t2->id, $this->t3->id], 'mode' => 'keep']);
        // Conservar: el edificio 1 mantiene a su técnico; los vacíos reciben a los dos.
        $this->assertSame([$this->a['technician']->id], $this->techsOf($this->a['building']));
        $this->assertSame(collect([$this->t2->id, $this->t3->id])->sort()->values()->all(), $this->techsOf($this->buildings[1]));

        $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t3->id], 'mode' => 'replace']);
        foreach ($this->buildings as $b) {
            $this->assertSame([$this->t3->id], $this->techsOf($b));
        }

        // Repetir lo mismo no duplica nada.
        $plan = $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t3->id], 'mode' => 'add']);
        $this->assertSame(3, $plan['counts']['unchanged']);
        $this->assertSame(3, DB::table('building_user')->where('user_id', $this->t3->id)->count());
    }

    public function test_only_one_inspector_per_building(): void
    {
        $this->a['building']->users()->attach($this->t2->id, ['type' => 'inspection']);

        $plan = $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'inspection', 'user_ids' => [$this->t3->id], 'mode' => 'add']);
        $this->assertSame('Ya tiene inspector y solo puede haber uno.', collect($plan['rows'])->firstWhere('building_id', $this->a['building']->id)['reason']);
        $this->assertSame([$this->t2->id], $this->techsOf($this->a['building'], 'inspection'));

        $this->expectException(ValidationException::class);
        $this->service()->plan($this->a['company']->id, $this->ids(), ['type' => 'inspection', 'user_ids' => [$this->t2->id, $this->t3->id], 'mode' => 'add']);
    }

    public function test_technicians_and_buildings_of_other_companies_or_admins_are_rejected(): void
    {
        auth()->logout(); // la otra empresa se crea sin sesión (si no, el scope le pone la empresa A)
        $b = $this->makeTenant();
        $this->assertNotSame($this->a['company']->id, $b['technician']->company_id);
        $this->actingInPanel($this->a['admin']);

        foreach ([$b['technician']->id, $this->a['admin']->id] as $invalid) {
            try {
                $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$invalid], 'mode' => 'add']);
                $this->fail('Debió rechazar al usuario '.$invalid);
            } catch (ValidationException $e) {
                $this->assertSame('Uno de los técnicos elegidos no es válido.', collect($e->errors())->flatten()->first());
            }
        }

        // Un edificio ajeno mezclado en la selección se saltea y no se toca.
        $plan = $this->service()->apply($this->a['company']->id, [...$this->ids(), $b['building']->id], ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add']);
        $this->assertSame('No es un edificio de tu empresa.', collect($plan['rows'])->firstWhere('building_id', $b['building']->id)['reason']);
        $this->assertSame([$b['technician']->id], $this->techsOf($b['building']));
    }

    public function test_an_unexpected_error_rolls_back_everything_and_sends_no_notices(): void
    {
        $this->mock(VisitPlanService::class, function ($mock) {
            $mock->shouldReceive('effectiveStart')->andReturnUsing(fn ($building, $type, $start) => $start);
            $mock->shouldReceive('set')->once()->andReturnTrue();
            $mock->shouldReceive('set')->andThrow(new \RuntimeException('falla a mitad de camino'));
        });
        $this->mock(AssignmentNotifier::class)->shouldNotReceive('building');

        try {
            app(BulkAssignmentService::class)->apply($this->a['company']->id, $this->ids(), [
                'type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add', 'frequency' => 'biweekly', 'starts_on' => '2026-10',
            ]);
            $this->fail('Debió fallar');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('building_user')->where('user_id', $this->t2->id)->count());
    }

    public function test_notices_go_out_after_saving_only_to_affected_technicians(): void
    {
        $this->service()->apply($this->a['company']->id, [$this->a['building']->id], ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'replace']);

        // Bandeja de cada técnico (Notifier guarda el aviso en la base).
        $this->assertSame(1, $this->t2->fresh()->notifications()->count());             // asignado
        $this->assertSame(1, $this->a['technician']->fresh()->notifications()->count()); // reemplazado
        $this->assertSame(0, $this->t3->fresh()->notifications()->count());              // no cambió nada
    }

    public function test_bulk_frequency_without_changing_technicians(): void
    {
        $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [], 'mode' => 'add', 'frequency' => 'months', 'every_months' => 3, 'starts_on' => '2026-11']);

        $this->assertSame(3, BuildingVisitPlan::count());
        $this->assertSame([$this->a['technician']->id], $this->techsOf($this->a['building']));
        $this->assertSame('Trimestral (cada 3 meses)', $this->a['building']->fresh()->load('visitPlans')->visitPlans->first()->label());
    }

    public function test_the_table_bulk_action_previews_and_applies(): void
    {
        $this->a['building']->users()->attach($this->t3->id, ['type' => 'maintenance']);

        // Como en el navegador: paso 1, «Siguiente» (arma el resumen y su huella), confirmar.
        $this->wizard(['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add', 'frequency' => 'keep'])
            ->callMountedTableBulkAction()
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Asignación aplicada');

        $this->assertSame([$this->t2->id], $this->techsOf($this->buildings[1]));
        $this->assertNotContains($this->t2->id, $this->techsOf($this->a['building']));

        $preview = view('filament.buildings.bulk-assignment-preview', ['plan' => $this->service()->plan($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t3->id], 'mode' => 'replace'])])->render();
        $this->assertStringContainsString('Ya tiene:', $preview);
        $this->assertStringContainsString('data-outcome="change"', $preview);
    }

    /** Asignación masiva abierta en el paso de resumen (como al tocar «Siguiente»). */
    private function wizard(array $data)
    {
        return Livewire::test(ListBuildings::class)
            ->mountTableBulkAction('bulkAssign', $this->buildings)
            ->fillForm($data)
            ->call('callSchemaComponentMethod', 'mountedActionSchema0.mountedActions.0.data::wizard', 'nextStep', ['currentStepIndex' => 0]);
    }

    public function test_confirming_without_the_preview_applies_nothing(): void
    {
        Livewire::test(ListBuildings::class)
            ->callTableBulkAction('bulkAssign', $this->buildings, data: [
                'type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add', 'frequency' => 'keep',
            ])
            ->assertNotified('No se aplicó la asignación');

        $this->assertSame(0, DB::table('building_user')->where('user_id', $this->t2->id)->count());
    }

    public function test_if_assignments_change_after_the_preview_nothing_is_applied(): void
    {
        $component = $this->wizard(['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add', 'frequency' => 'keep']);
        $this->assertNotEmpty($component->get('mountedActions.0.data.preview_fingerprint'));

        // Otro admin completa uno de los edificios (2 técnicos) mientras este
        // mira el resumen: lo que se ejecutaría ya no es lo que vio.
        $this->buildings[1]->users()->attach([$this->t3->id => ['type' => 'maintenance'], $this->a['technician']->id => ['type' => 'maintenance']]);

        $component->callMountedTableBulkAction()->assertNotified('No se aplicó la asignación');

        $this->assertSame(0, DB::table('building_user')->where('user_id', $this->t2->id)->count());
        $this->assertSame(collect([$this->t3->id, $this->a['technician']->id])->sort()->values()->all(), $this->techsOf($this->buildings[1]));
    }

    public function test_the_preview_matches_what_is_applied(): void
    {
        $input = ['type' => 'maintenance', 'user_ids' => [$this->t2->id, $this->t3->id], 'mode' => 'replace'];
        $preview = $this->service()->plan($this->a['company']->id, $this->ids(), $input);
        $result = $this->service()->apply($this->a['company']->id, $this->ids(), $input, BulkAssignmentService::fingerprint($preview));

        $this->assertSame(BulkAssignmentService::fingerprint($preview), BulkAssignmentService::fingerprint($result));
        foreach ($this->buildings as $building) {
            $this->assertSame(collect([$this->t2->id, $this->t3->id])->sort()->values()->all(), $this->techsOf($building));
        }
    }

    public function test_a_failing_notice_never_leaves_a_partial_assignment(): void
    {
        $calls = 0;
        $this->mock(AssignmentNotifier::class)->shouldReceive('building')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('correo caído');
            }
        });

        $result = $this->service()->apply($this->a['company']->id, $this->ids(), ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add']);

        // Todo quedó asignado, los demás avisos salieron y la falla quedó contada.
        foreach ($this->buildings as $building) {
            $this->assertContains($this->t2->id, $this->techsOf($building));
        }
        $this->assertSame(3, $calls);
        $this->assertSame(1, $result['notices_failed']);
    }

    public function test_concurrent_admins_never_exceed_the_limit(): void
    {
        // El primero completa el edificio 2 con dos técnicos; el segundo, con
        // el resumen ya calculado antes, intenta agregar otro: se revalida.
        $stale = $this->service()->plan($this->a['company']->id, [$this->buildings[1]->id], ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add']);
        $this->service()->apply($this->a['company']->id, [$this->buildings[1]->id], ['type' => 'maintenance', 'user_ids' => [$this->a['technician']->id, $this->t3->id], 'mode' => 'add']);

        try {
            $this->service()->apply($this->a['company']->id, [$this->buildings[1]->id], ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add'], BulkAssignmentService::fingerprint($stale));
            $this->fail('Debió rechazarse');
        } catch (ValidationException) {
        }

        // Sin huella (o con una nueva) se saltea con motivo: nunca 3 técnicos.
        $result = $this->service()->apply($this->a['company']->id, [$this->buildings[1]->id], ['type' => 'maintenance', 'user_ids' => [$this->t2->id], 'mode' => 'add']);
        $this->assertSame('skipped', $result['rows'][0]['outcome']);
        $this->assertCount(2, $this->techsOf($this->buildings[1]));
    }

    public function test_bulk_frequency_never_cuts_a_running_period_and_says_from_when_it_applies(): void
    {
        // Trimestral desde agosto (agosto–octubre) en el primer edificio; los otros, mensuales.
        $this->travelTo(Carbon::parse('2026-08-03 09:00'));
        app(VisitPlanService::class)->set($this->a['building'], 'maintenance', 1, 3, CarbonImmutable::parse('2026-08-01'));
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));

        $input = ['type' => 'maintenance', 'user_ids' => [], 'mode' => 'add', 'frequency' => 'biweekly', 'starts_on' => '2026-10'];
        $preview = $this->service()->plan($this->a['company']->id, $this->ids(), $input);
        $row = collect($preview['rows'])->firstWhere('building_id', $this->a['building']->id);
        $this->assertSame('2026-11', $row['frequency_from']);
        $this->assertStringContainsString('noviembre 2026', $row['frequency_note']);
        $this->assertSame('2026-10', collect($preview['rows'])->firstWhere('building_id', $this->buildings[1]->id)['frequency_from']);

        $this->service()->apply($this->a['company']->id, $this->ids(), $input, BulkAssignmentService::fingerprint($preview));

        // Agosto–octubre sigue trimestral; quincenal desde noviembre. Los otros, desde octubre.
        $plans = BuildingVisitPlan::where('building_id', $this->a['building']->id)->orderBy('starts_on')->get();
        $this->assertSame([['2026-08-01', 3, 1], ['2026-11-01', 1, 2]], $plans->map(fn ($p) => [$p->starts_on->toDateString(), $p->every_months, $p->visits_per_month])->all());
        $this->assertSame('2026-10-01', BuildingVisitPlan::where('building_id', $this->buildings[1]->id)->sole()->starts_on->toDateString());
    }

    public function test_the_individual_assignment_still_works_as_before(): void
    {
        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->buildings[1], data: ['user_ids' => [$this->t2->id], 'type' => 'inspection'])
            ->assertHasNoTableActionErrors();

        $this->assertSame([$this->t2->id], $this->techsOf($this->buildings[1], 'inspection'));
    }

    public function test_the_row_frequency_action_saves_from_the_chosen_month(): void
    {
        Livewire::test(ListBuildings::class)
            ->callTableAction('visitFrequency', $this->a['building'], data: ['type' => 'inspection', 'frequency' => 'biweekly', 'starts_on' => '2026-10'])
            ->assertHasNoTableActionErrors();

        $plan = BuildingVisitPlan::sole();
        $this->assertSame([2, 1, 'inspection', '2026-10-01'], [$plan->visits_per_month, $plan->every_months, $plan->type, $plan->starts_on->toDateString()]);
    }
}
