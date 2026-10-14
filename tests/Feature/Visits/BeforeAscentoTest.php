<?php

namespace Tests\Feature\Visits;

use App\Filament\Pages\Agenda;
use App\Services\Insights\AttentionCenter;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Visits\VisitSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Meses anteriores a que la empresa usara Ascento (su alta, companies.created_at):
 * no tener registros de esa época no significa que el trabajo quedó vencido.
 * Desde el alta, todo sigue igual.
 */
class BeforeAscentoTest extends TestCase
{
    use InteractsWithTenants {
        makeTenant as makeTenantOnTrial;
    }
    use RefreshDatabase;

    /** Se sumó a Ascento el 3 de octubre de 2026. */
    private array $nueva;

    /** Usa Ascento desde junio de 2026. */
    private array $antigua;

    protected function makeTenant(): array
    {
        $tenant = $this->makeTenantOnTrial();
        $this->giveCompanyPlan($tenant['company'], 'empresa');
        $tenant['building']->users()->attach($tenant['technician']->id, ['type' => 'inspection']);

        return $tenant;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->nueva = $this->makeTenant();
        $this->usingAscentoSince($this->nueva, '2026-10-03 11:00');
        $this->antigua = $this->makeTenant();
        $this->usingAscentoSince($this->antigua, '2026-06-10 09:00');
    }

    private function agenda(array $tenant, int $month, string $type = 'maintenance')
    {
        $this->actingInPanel($tenant['admin']);

        return app(MaintenanceAgenda::class)->rows($month, 2026, $type);
    }

    private function rowStatus(array $tenant, int $month, string $type = 'maintenance'): ?string
    {
        return $this->agenda($tenant, $month, $type)->firstWhere(fn ($r) => $r['building']->id === $tenant['building']->id)['status'] ?? null;
    }

    /** Remito firmado por el técnico para ese mes (la misma ruta que usa la app). */
    private function sign(array $tenant, int $month, string $type = 'maintenance', bool $performed = true): void
    {
        $this->actingAs($tenant['technician'])->post("/{$tenant['company']->slug}/delivery-notes/store", [
            'building_id' => $tenant['building']->id,
            'description' => 'Mantenimiento preventivo completo.',
            'month' => $month, 'year' => 2026,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0,
            'assignment_type' => $type,
            'performed' => $performed ? '1' : '0',
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
        ])->assertSessionHasNoErrors();
    }

    public function test_a_company_that_just_joined_has_no_fictitious_overdue_months(): void
    {
        foreach (['maintenance', 'inspection'] as $type) {
            foreach ([7, 8, 9] as $month) {
                $this->assertSame('before_start', $this->rowStatus($this->nueva, $month, $type), "{$type} {$month}/2026");
            }
            // El mes del alta sí cuenta: lo que corresponde está pendiente.
            $this->assertSame('pending', $this->rowStatus($this->nueva, 10, $type));
        }

        // Ningún vencido, ni previstas, ni pendientes en esos meses.
        $progress = MaintenanceAgenda::progress($this->agenda($this->nueva, 9));
        $this->assertSame(0, $progress['maintenance']['expected']);
        $this->assertSame(0, $progress['maintenance']['pending']);
        $this->assertTrue($this->agenda($this->nueva, 9)->every(fn ($r) => $r['status'] !== 'overdue'));
    }

    public function test_a_company_already_using_ascento_keeps_its_real_overdue_work(): void
    {
        foreach (['maintenance', 'inspection'] as $type) {
            $this->assertSame('overdue', $this->rowStatus($this->antigua, 9, $type));
            $this->assertSame('overdue', $this->rowStatus($this->antigua, 6, $type)); // mes del alta
            $this->assertSame('before_start', $this->rowStatus($this->antigua, 5, $type));
        }

        $progress = MaintenanceAgenda::progress($this->agenda($this->antigua, 9));
        $this->assertSame(1, $progress['maintenance']['expected']);
        $this->assertSame(1, $progress['maintenance']['pending']);
    }

    public function test_work_actually_done_keeps_its_state(): void
    {
        $this->sign($this->antigua, 9);
        $this->sign($this->antigua, 8, performed: false);

        $this->assertSame('done', $this->rowStatus($this->antigua, 9));
        $this->assertSame('not_done', $this->rowStatus($this->antigua, 8));
        $this->assertSame('overdue', $this->rowStatus($this->antigua, 7)); // el que de verdad no se hizo
    }

    public function test_real_records_loaded_for_months_before_joining_can_still_be_seen(): void
    {
        // Se puede cargar una visita histórica real de un mes anterior al alta…
        $this->sign($this->nueva, 8);

        // …y se ve con su estado real, contada en el mes.
        $this->assertSame('done', $this->rowStatus($this->nueva, 8));
        $row = $this->agenda($this->nueva, 8)->firstWhere(fn ($r) => $r['building']->id === $this->nueva['building']->id);
        $this->assertSame(1, $row['visits']->count());
        $progress = MaintenanceAgenda::progress($this->agenda($this->nueva, 8));
        $this->assertSame(['expected' => 1, 'done' => 1, 'pending' => 0], array_intersect_key($progress['maintenance'], array_flip(['expected', 'done', 'pending'])));
    }

    public function test_two_companies_with_different_start_dates_are_independent(): void
    {
        $ids = [$this->nueva['building']->id, $this->antigua['building']->id];
        $schedule = VisitSchedule::load($ids, 9, 2026);

        $nueva = $schedule->evaluate($this->nueva['building']->id, 'maintenance', 9, 2026);
        $antigua = $schedule->evaluate($this->antigua['building']->id, 'maintenance', 9, 2026);

        $this->assertTrue($nueva['before_start']);
        $this->assertSame([0, 0], [$nueva['expected'], $nueva['pending']]);
        $this->assertFalse($antigua['before_start']);
        $this->assertSame([1, 1], [$antigua['expected'], $antigua['pending']]);
    }

    public function test_the_technician_screen_shows_earlier_months_as_before_ascento_not_overdue(): void
    {
        $slug = $this->nueva['company']->slug;
        $html = $this->actingAs($this->nueva['technician'])->get("/{$slug}/buildings?month=9&year=2026")->assertOk()->getContent();

        $this->assertStringContainsString('data-before-start', $html);
        $this->assertStringContainsString('data-progress-before-start', $html); // el resumen tampoco dice «ya se visitó»
        $this->assertStringNotContainsString('ya se visitó en el período', $html);
        $this->assertStringNotContainsString('>Vencido', $html);

        // La empresa que ya usaba Ascento sigue viendo lo vencido.
        $slug = $this->antigua['company']->slug;
        $this->actingAs($this->antigua['technician'])->get("/{$slug}/buildings?month=9&year=2026")->assertOk()->assertSee('Vencido');
    }

    public function test_the_attention_center_does_not_count_months_before_joining(): void
    {
        $this->actingInPanel($this->nueva['admin']);
        $titles = app(AttentionCenter::class)->basic($this->nueva['company'])->pluck('title')->implode(' | ');
        $this->assertStringNotContainsString('vencid', mb_strtolower($titles));

        $this->actingInPanel($this->antigua['admin']);
        $titles = app(AttentionCenter::class)->basic($this->antigua['company'])->pluck('title')->implode(' | ');
        $this->assertStringContainsString('Mantenimientos vencidos', $titles);
    }

    public function test_the_agenda_filters_show_earlier_months_as_before_ascento(): void
    {
        $this->actingInPanel($this->nueva['admin']);

        Livewire::test(Agenda::class)
            ->set('month', '2026-09')
            ->assertSee('Antes de usar Ascento')
            ->assertDontSee('Vencido:')
            ->set('status', 'before_start')
            ->assertSee($this->nueva['building']->name)
            ->set('status', 'overdue')
            ->assertSee('No hay visitas con estos filtros.');
    }

    /** Los cuatro selectores: flecha propia dentro del campo (con la nativa, cada navegador la ubicaba distinto). */
    public function test_the_four_agenda_selects_draw_their_arrow_inside_the_field(): void
    {
        $this->actingInPanel($this->nueva['admin']);
        $html = $this->get('/admin/agenda')->assertOk()->getContent();

        foreach (['Mes', 'Tipo', 'Técnico', 'Estado'] as $label) {
            $this->assertMatchesRegularExpression('#<select wire:model\.live="\w+" class="asc-select" aria-label="'.$label.'">#u', $html);
        }
        $this->assertStringContainsString('<option value="">Todos los tipos</option>', $html);

        preg_match('#\.asc-select \{([^}]*)\}#', $html, $rule);
        $this->assertNotEmpty($rule);
        foreach (['appearance: none', 'padding: .5rem 2.25rem .5rem .7rem', 'background-position: right .75rem center', 'background-repeat: no-repeat'] as $declaration) {
            $this->assertStringContainsString($declaration, $rule[1]);
        }
        // En modo oscuro no se pisa la flecha con el atajo «background».
        $this->assertDoesNotMatchRegularExpression('#\.dark \.asc-select \{[^}]*\bbackground:#', $html);
    }
}
