<?php

namespace Tests\Feature\OnCall;

use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Filament\Resources\OnCall\Pages\OnCallHome;
use App\Models\OnCallChange;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OnCall\OnCallPlanner;
use App\Services\OnCall\OnCallService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Portada de «Guardias» (OnCallHome): quién está ahora y hasta cuándo, quién
 * sigue, lo que falta cubrir y los próximos turnos; «Asignar guardia» y
 * «Reemplazar técnico» como asistentes con resumen antes de confirmar.
 * Mismo servicio y mismas reglas: historial, capacidad, permisos y empresa.
 */
class OnCallQuickActionsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 20:00')); // miércoles, dentro de la franja
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->ana = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->actingInPanel($this->a['admin']);
    }

    private function schedule(string $name = 'Guardia nocturna', array $attributes = []): OnCallSchedule
    {
        return OnCallSchedule::create(['name' => $name, 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true, ...$attributes]);
    }

    private function assign(OnCallSchedule $schedule, User $tech, string $from, ?string $to): OnCallShift
    {
        return app(OnCallService::class)->assign($schedule, $tech, Carbon::parse($from), $to ? Carbon::parse($to) : null, $this->a['admin']);
    }

    public function test_without_a_schedule_the_page_says_what_is_missing_and_how_to_fix_it(): void
    {
        $this->get(OnCallScheduleResource::getUrl('index'))->assertOk()
            ->assertSee('data-on-call-setup', false)->assertSee('Configurar el horario de guardia')
            ->assertDontSee('data-on-call-now-card', false);

        Livewire::test(OnCallHome::class)->assertActionHidden('assignOnCall')->assertActionHidden('replaceOnCall');
    }

    public function test_the_page_answers_who_is_on_duty_until_when_and_who_is_next(): void
    {
        $night = $this->schedule();
        $this->assign($night, $this->a['technician'], '2026-10-14 18:00', '2026-10-15 08:00');
        $this->assign($night, $this->ana, '2026-10-15 18:00', '2026-10-16 08:00');

        Livewire::test(OnCallHome::class)
            ->assertSeeInOrder(['De guardia ahora', $this->a['technician']->name, 'Hasta mañana 08:00', 'Desde hoy 18:00', 'Reemplazar a '.$this->a['technician']->name,
                'Próxima guardia', $this->ana->name, 'Empieza mañana 18:00', 'Próximos turnos'])
            ->assertActionVisible('assignOnCall')->assertActionVisible('replaceOnCall')
            // Lo secundario, plegado.
            ->assertSeeHtml('<details class="oc-more" data-on-call-calendar>')
            ->assertSeeHtml('<details class="oc-more" data-on-call-history>')
            ->assertSeeHtml('<details class="oc-more" data-on-call-past>')
            ->assertSeeHtml('<details class="oc-more" data-on-call-advanced>');
    }

    public function test_uncovered_turns_are_warned_with_an_action_that_fills_that_exact_turn(): void
    {
        $night = $this->schedule();

        Livewire::test(OnCallHome::class)
            ->assertSee('Nadie está de guardia')
            ->assertSeeHtml('data-on-call-warning="gap"')
            ->assertSee('Sin técnico: hoy 20:00 a 08:00');

        // «Asignar» desde una franja sin técnico abre el asistente con esa franja ya elegida.
        Livewire::test(OnCallHome::class)
            ->mountAction('assignOnCall', ['schedule' => $night->id, 'start' => '2026-10-15 18:00', 'end' => '2026-10-16 08:00'])
            ->assertSchemaStateSet(['start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-16', 'end_time' => '08:00'], 'mountedActionSchema0');
    }

    public function test_assign_a_night_guard_with_a_summary_before_confirming(): void
    {
        $night = $this->schedule();
        $this->schedule('Fines de semana', ['days' => [6, 7], 'starts_time' => '08:00', 'ends_time' => '08:00']);

        // Paso 1 sin errores → paso 2: el resumen dice exactamente qué va a pasar.
        $summary = Livewire::test(OnCallHome::class)
            ->mountAction('assignOnCall', ['schedule' => $night->id])
            ->setActionData(['schedule_id' => $night->id, 'user_id' => $this->ana->id, 'start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-16', 'end_time' => '08:00'])
            ->goToNextWizardStep()
            ->assertHasNoActionErrors()->assertNotNotified('Revisá estos datos');
        $plan = app(OnCallPlanner::class)->assignment($night, [$this->ana->id], Carbon::parse('2026-10-15 18:00'), Carbon::parse('2026-10-16 08:00'));
        $this->assertSame('Ana Técnica cubrirá la guardia «Guardia nocturna» desde mañana jueves 15/10 a las 18:00 hasta el viernes 16/10 a las 08:00.', $plan['headline']);
        $this->assertStringContainsString('Está de guardia en esta franja: mañana 18:00 a 08:00.', implode(' ', $plan['details']));
        $this->assertStringContainsString($plan['headline'], view('filament.on-call.summary', ['plan' => $plan])->render());
        $this->assertSame(0, OnCallShift::count()); // revisar no guarda

        $summary->callMountedAction()->assertHasNoActionErrors()->assertNotified('Guardia asignada');

        $shift = OnCallShift::sole();
        $this->assertSame([$night->id, $this->ana->id, 'manual', '2026-10-15 18:00', '2026-10-16 08:00'], [$shift->on_call_schedule_id, $shift->user_id, $shift->origin, $shift->starts_at->format('Y-m-d H:i'), $shift->ends_at->format('Y-m-d H:i')]);
        $this->assertSame('assigned', OnCallChange::sole()->action);

        // La guardia nocturna cubre hasta las 08:00 del día siguiente (sin incluirlas).
        $onDuty = fn (string $at) => app(OnCallService::class)->shiftsAt($night, Carbon::parse($at))->map(fn ($s) => $s->user->name)->all();
        $this->assertSame(['Ana Técnica'], $onDuty('2026-10-16 07:59'));
        $this->assertSame([], $onDuty('2026-10-16 08:00'));
    }

    public function test_an_end_before_the_start_is_explained_and_nothing_is_saved(): void
    {
        $night = $this->schedule();

        // Error típico de la guardia nocturna: poner el mismo día como fin.
        Livewire::test(OnCallHome::class)
            ->mountAction('assignOnCall', ['schedule' => $night->id])
            ->setActionData(['user_id' => $this->ana->id, 'start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-15', 'end_time' => '08:00'])
            ->goToNextWizardStep()
            ->assertNotified('Revisá estos datos');
        $this->assertSame(0, OnCallShift::count());

        // Al confirmar igual (pedido armado a mano), el servidor lo rechaza.
        Livewire::test(OnCallHome::class)
            ->callAction('assignOnCall', data: ['schedule_id' => $night->id, 'user_id' => $this->ana->id, 'start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-15', 'end_time' => '08:00'])
            ->assertNotified('No se guardó el cambio');
        $this->assertSame(0, OnCallShift::count());
    }

    public function test_replace_only_one_turn_from_the_upcoming_list_keeps_the_rest_and_the_history(): void
    {
        $night = $this->schedule();
        $week = $this->assign($night, $this->a['technician'], '2026-10-14 18:00', '2026-10-21 18:00');
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'on_call_shift_id' => $week->id, 'type' => 'claim', 'status' => 'completed', 'priority' => 'urgent', 'unit' => 'Ascensor 1']);

        // «Reemplazar» en la franja del viernes: solo esa franja.
        $page = Livewire::test(OnCallHome::class)
            ->mountAction('replaceOnCall', ['shift' => $week->id, 'from' => '2026-10-16 18:00', 'to' => '2026-10-17 08:00'])
            ->assertSchemaStateSet(['scope' => 'segment', 'segment_from' => '2026-10-16 18:00', 'segment_to' => '2026-10-17 08:00'], 'mountedActionSchema0')
            ->setActionData(['user_id' => $this->ana->id, 'reason' => 'Cambio acordado'])
            ->goToNextWizardStep()->assertHasNoActionErrors()->assertNotNotified('Revisá estos datos');
        $plan = app(OnCallPlanner::class)->replacement($week, $this->ana, Carbon::parse('2026-10-16 18:00'), Carbon::parse('2026-10-17 08:00'));
        $this->assertSame('Ana Técnica reemplaza a '.$this->a['technician']->name.' en «Guardia nocturna» desde el viernes 16/10 a las 18:00 hasta el sábado 17/10 a las 08:00.', $plan['headline']);
        $details = implode(' ', $plan['details']);
        foreach (['Hasta vie 16/10 18:00, el turno sigue a nombre de', 'Desde sáb 17/10 08:00, vuelve a cubrir', 'No cambia ningún otro turno', 'La orden de trabajo vinculada a este turno sigue vinculada'] as $text) {
            $this->assertStringContainsString($text, $details);
        }
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Reemplazo registrado');

        // El original queda en el historial; antes y después siguen a su nombre.
        $this->assertSame(OnCallShift::REPLACED, $week->fresh()->status);
        $active = OnCallShift::active()->orderBy('starts_at')->get()->map(fn ($s) => $s->user->name.' '.$s->starts_at->format('d H:i').'-'.$s->ends_at->format('d H:i'))->all();
        $tech = $this->a['technician']->name;
        $this->assertSame(["{$tech} 14 18:00-16 18:00", 'Ana Técnica 16 18:00-17 08:00', "{$tech} 17 08:00-21 18:00"], $active);
        $this->assertSame('Cambio acordado', OnCallChange::where('action', 'replaced')->sole()->reason);
        $this->assertSame($week->id, $order->fresh()->on_call_shift_id);

        // Los dos técnicos reciben el aviso.
        $this->assertContains('Cambió una de tus guardias', $this->a['technician']->notifications()->get()->pluck('data.title')->all());
        $this->assertStringContainsString('reemplazás a', $this->ana->notifications()->sole()->data['body']);
    }

    public function test_replace_the_whole_remaining_turn_from_now(): void
    {
        $night = $this->schedule();
        $shift = $this->assign($night, $this->a['technician'], '2026-10-14 18:00', null); // sin fecha de fin

        $this->assertSame('Ana Técnica reemplaza a '.$this->a['technician']->name.' en «Guardia nocturna» desde hoy miércoles 14/10 a las 20:00, sin fecha de fin.',
            app(OnCallPlanner::class)->replacement($shift, $this->ana, now(), null)['headline']);

        Livewire::test(OnCallHome::class)
            ->mountAction('replaceOnCall', ['shift' => $shift->id])
            ->setActionData(['user_id' => $this->ana->id, 'scope' => 'rest'])
            ->goToNextWizardStep()->assertNotNotified('Revisá estos datos')
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(['Ana Técnica'], app(OnCallService::class)->shiftsAt($night, now())->map(fn ($s) => $s->user->name)->all());
        // Lo cubierto de 18:00 a 20:00 sigue a nombre del primero.
        $this->assertSame($this->a['technician']->id, app(OnCallService::class)->shiftsAt($night, Carbon::parse('2026-10-14 19:00'))->sole()->user_id);
    }

    public function test_a_replacement_in_the_past_is_refused_with_a_clear_reason(): void
    {
        $night = $this->schedule();
        $shift = $this->assign($night, $this->a['technician'], '2026-10-14 18:00', '2026-10-15 08:00');

        Livewire::test(OnCallHome::class)
            ->mountAction('replaceOnCall', ['shift' => $shift->id])
            ->setActionData(['user_id' => $this->ana->id, 'scope' => 'custom', 'from_date' => '2026-10-14', 'from_time' => '18:00', 'to_date' => '2026-10-15', 'to_time' => '08:00'])
            ->goToNextWizardStep()
            ->assertNotified('Revisá estos datos');
        $this->assertSame(OnCallShift::ACTIVE, $shift->fresh()->status);
    }

    public function test_viewers_and_other_companies_cannot_use_the_actions(): void
    {
        $night = $this->schedule();
        $foreign = OnCallSchedule::withoutGlobalScopes()->create(['company_id' => $this->b['company']->id, 'name' => 'Ajena', 'days' => [1], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        $foreign->forceFill(['company_id' => $this->b['company']->id])->saveQuietly();
        $foreignShift = app(OnCallService::class)->createShift($foreign, $this->b['technician']->id, now()->addDay(), now()->addDays(2), 'manual', null, null);
        $foreignShift->forceFill(['company_id' => $this->b['company']->id])->saveQuietly();

        // Una guardia o un turno de otra empresa no se pueden forzar.
        Livewire::test(OnCallHome::class)
            ->callAction('assignOnCall', data: ['schedule_id' => $foreign->id, 'user_id' => $this->ana->id, 'start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-16', 'end_time' => '08:00'])
            ->assertHasActionErrors(['schedule_id']);
        Livewire::test(OnCallHome::class)
            ->callAction('replaceOnCall', data: ['shift_id' => $foreignShift->id, 'user_id' => $this->ana->id, 'scope' => 'rest'])
            ->assertHasActionErrors(['shift_id']);
        $this->assertSame(OnCallShift::ACTIVE, $foreignShift->fresh()->status);
        // Ni un técnico de otra empresa.
        Livewire::test(OnCallHome::class)
            ->callAction('assignOnCall', data: ['schedule_id' => $night->id, 'user_id' => $this->b['technician']->id, 'start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-16', 'end_time' => '08:00'])
            ->assertHasActionErrors(['user_id']);
        $this->assertSame(1, OnCallShift::withoutGlobalScopes()->count());
        $this->assertSame(0, OnCallShift::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());

        // Quien solo puede ver guardias ve la portada, sin acciones.
        $this->assign($night, $this->a['technician'], '2026-10-14 18:00', '2026-10-15 08:00');
        $viewer = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Mira guardias', 'email' => 'mira@demo.test', 'password' => 'clave-segura-123'], ['on_call' => 'view']);
        $this->flushSession();
        $this->actingInPanel($viewer->fresh());
        Livewire::test(OnCallHome::class)->assertSee($this->a['technician']->name)
            ->assertActionHidden('assignOnCall')->assertActionHidden('replaceOnCall')
            ->assertDontSeeHtml('data-on-call-replace=');

        // La otra empresa no ve estas guardias.
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        Livewire::test(OnCallHome::class)->assertDontSee($this->a['technician']->name)->assertDontSee('Guardia nocturna');
    }
}
