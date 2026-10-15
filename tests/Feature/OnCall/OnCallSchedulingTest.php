<?php

namespace Tests\Feature\OnCall;

use App\Models\OnCallChange;
use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\RotationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Guardias técnicas: horarios (medianoche, cambios de mes y año, límites exactos),
 * asignaciones manuales, permanentes y por período, rotación, cambios de guardia,
 * cancelaciones, huecos, superposiciones y concurrencia.
 */
class OnCallSchedulingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private User $juan;

    private User $ana;

    private User $luis;

    private OnCallService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-14 12:00')); // miércoles
        $this->a = $this->makeTenant();
        $this->juan = $this->a['technician'];
        $this->ana = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->luis = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Luis Técnico']);
        $this->actingInPanel($this->a['admin']);
        $this->service = app(OnCallService::class);
    }

    /** Noches de lunes a viernes, 18:00 a 08:00 del día siguiente. */
    private function nights(array $attributes = []): OnCallSchedule
    {
        return OnCallSchedule::create(['name' => 'Guardia nocturna', 'days' => [1, 2, 3, 4, 5], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true, ...$attributes]);
    }

    private function onDuty(Carbon|string $at): array
    {
        return collect($this->service->onDutyAt($this->a['company']->id, Carbon::parse($at)))
            ->mapWithKeys(fn ($row) => [$row['schedule']->name => $row['shifts']->map(fn ($s) => $s->user->name)->all()])->all();
    }

    public function test_a_guard_with_one_technician_and_who_is_on_duty_at_a_moment(): void
    {
        $schedule = $this->nights();
        $shift = $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-21 18:00'), $this->a['admin']);

        $this->assertSame([$this->a['company']->id, OnCallShift::ACTIVE, 'manual'], [$shift->company_id, $shift->status, $shift->origin]);
        $this->assertSame([], $this->onDuty('2026-10-14 12:00'));                                     // fuera de horario
        $this->assertSame(['Guardia nocturna' => [$this->juan->name]], $this->onDuty('2026-10-14 22:00'));
        $this->assertSame(['Guardia nocturna' => [$this->juan->name]], $this->onDuty('2026-10-15 07:59')); // cruza la medianoche
        $this->assertSame([], $this->onDuty('2026-10-15 08:00'));                                     // el fin no está incluido
        $this->assertSame([], $this->onDuty('2026-10-17 22:00'));                                     // sábado: no hay franja
        $this->assertSame(['Guardia nocturna' => [$this->juan->name]], $this->onDuty('2026-10-17 07:00')); // franja del viernes, sábado a la mañana
        $this->assertSame('assigned', OnCallChange::sole()->action);
    }

    public function test_windows_crossing_month_and_year_and_ending_at_midnight(): void
    {
        // Todos los días 20:00 a 00:00 (medianoche) y otra guardia de 24 h.
        $evenings = $this->nights(['name' => 'Tarde-noche', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '20:00', 'ends_time' => '00:00']);
        $full = $this->nights(['name' => 'Todo el día', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '08:00', 'ends_time' => '08:00']);
        $nights = $this->nights(['name' => 'Fin de año', 'days' => [1, 2, 3, 4, 5, 6, 7]]);

        $this->service->assign($evenings, $this->juan, Carbon::parse('2026-12-31 00:00'), Carbon::parse('2027-01-02 00:00'));
        $this->service->assign($full, $this->ana, Carbon::parse('2026-10-31 08:00'), Carbon::parse('2026-11-01 08:00'));
        $this->service->assign($nights, $this->luis, Carbon::parse('2026-12-31 18:00'), Carbon::parse('2027-01-01 08:00'));

        $this->assertSame([$this->juan->name], $this->onDuty('2026-12-31 23:59')['Tarde-noche']);
        $this->assertArrayNotHasKey('Tarde-noche', $this->onDuty('2027-01-01 00:00'));                // termina a las 00:00
        $this->assertSame([$this->luis->name], $this->onDuty('2027-01-01 03:00')['Fin de año']);      // cambio de año
        $this->assertSame([$this->ana->name], $this->onDuty('2026-11-01 07:59')['Todo el día']);      // cambio de mes, 24 h
        $this->assertSame([], $this->onDuty('2026-11-01 08:00')['Todo el día'] ?? []);
        $this->assertSame('Todos los días · 20:00 a 00:00 (medianoche)', $evenings->coverageLabel());
        $this->assertSame('Todos los días · 24 h desde las 08:00', $full->coverageLabel());
    }

    public function test_several_technicians_at_once_and_overlap_rules(): void
    {
        $pair = $this->nights(['name' => 'Guardia doble', 'technicians_needed' => 2]);
        $this->service->assign($pair, $this->juan, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-21 18:00'));
        $this->service->assign($pair, $this->ana, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-21 18:00'));
        $this->assertSame([$this->juan->name, $this->ana->name], $this->onDuty('2026-10-15 02:00')['Guardia doble']);

        // Un tercero no entra, y el mismo técnico tampoco dos veces.
        foreach ([$this->luis, $this->juan] as $tech) {
            try {
                $this->service->assign($pair, $tech, Carbon::parse('2026-10-16 18:00'), Carbon::parse('2026-10-17 18:00'));
                $this->fail('Se superpuso una asignación incompatible.');
            } catch (ValidationException) {
            }
        }

        // En una guardia de un solo técnico: períodos contiguos sí; superpuestos no.
        $single = $this->nights();
        $this->service->assign($single, $this->juan, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-16 18:00'));
        $this->service->assign($single, $this->ana, Carbon::parse('2026-10-16 18:00'), Carbon::parse('2026-10-18 18:00')); // límite exacto
        $this->expectException(ValidationException::class);
        $this->service->assign($single, $this->luis, Carbon::parse('2026-10-15 18:00'), Carbon::parse('2026-10-17 18:00'));
    }

    public function test_permanent_assignment_can_be_ended_keeping_history(): void
    {
        $schedule = $this->nights();
        $permanent = $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-01 18:00'), null);
        $this->assertSame([$this->juan->name], $this->onDuty('2027-03-03 22:00')['Guardia nocturna']);

        $this->service->endAt($permanent, Carbon::parse('2026-10-20 08:00'), $this->a['admin'], 'Cambio de esquema');

        $this->assertSame(OnCallShift::REPLACED, $permanent->fresh()->status);
        $kept = OnCallShift::active()->sole();
        $this->assertSame(['2026-10-01 18:00', '2026-10-20 08:00'], [$kept->starts_at->format('Y-m-d H:i'), $kept->ends_at->format('Y-m-d H:i')]);
        $this->assertSame([$this->juan->name], $this->onDuty('2026-10-05 22:00')['Guardia nocturna']); // el pasado sigue igual
        $this->assertSame([], $this->onDuty('2026-10-21 22:00')['Guardia nocturna'] ?? []);
        $this->assertSame(['assigned', 'ended'], OnCallChange::orderBy('id')->pluck('action')->all());
    }

    public function test_partial_replacement_keeps_the_original_and_its_history(): void
    {
        $schedule = $this->nights(['days' => [1, 2, 3, 4, 5, 6, 7]]);
        $week = $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-12 18:00'), Carbon::parse('2026-10-19 18:00'));

        // Juan no puede el sábado: lo cubre Ana (desde el sábado 18:00 hasta el domingo 18:00).
        $pieces = $this->service->replace($week, Carbon::parse('2026-10-17 18:00'), Carbon::parse('2026-10-18 18:00'), $this->ana, $this->a['admin'], 'Casamiento');

        $week->refresh();
        $this->assertSame([OnCallShift::REPLACED, 'Casamiento', $this->a['admin']->id], [$week->status, $week->close_reason, $week->closed_by]);
        $this->assertSame($this->juan->id, $week->user_id); // la asignación original se conserva
        $this->assertSame([[$this->juan->id, 'manual'], [$this->ana->id, 'replacement'], [$this->juan->id, 'manual']], collect($pieces)->map(fn ($s) => [$s->user_id, $s->origin])->all());

        $this->assertSame([$this->juan->name], $this->onDuty('2026-10-13 02:00')['Guardia nocturna']);   // ya pasó: Juan
        $this->assertSame([$this->ana->name], $this->onDuty('2026-10-17 23:00')['Guardia nocturna']);   // sábado: Ana
        $this->assertSame([$this->juan->name], $this->onDuty('2026-10-18 22:00')['Guardia nocturna']);   // domingo: vuelve Juan

        $change = OnCallChange::where('action', 'replaced')->sole();
        $this->assertSame([$this->juan->id, $this->ana->id, '2026-10-17 18:00', '2026-10-18 18:00', 'Casamiento', $this->a['admin']->id],
            [$change->from_user_id, $change->to_user_id, $change->period_start->format('Y-m-d H:i'), $change->period_end->format('Y-m-d H:i'), $change->reason, $change->user_id]);

        // El historial no se edita ni se borra.
        $this->assertFalse($change->update(['reason' => 'otro']));
        $this->assertSame('Casamiento', $change->fresh()->reason);

        // Lo ya pasado no se cambia.
        $current = OnCallShift::active()->where('user_id', $this->juan->id)->orderBy('starts_at')->first();
        $this->expectException(ValidationException::class);
        $this->service->replace($current, Carbon::parse('2026-10-13 18:00'), null, $this->luis);
    }

    public function test_cancel_only_future_shifts_and_detect_gaps(): void
    {
        $schedule = $this->nights(['technicians_needed' => 1]);
        $running = $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-12 18:00'), Carbon::parse('2026-10-15 18:00'));
        $future = $this->service->assign($schedule, $this->ana, Carbon::parse('2026-10-15 18:00'), Carbon::parse('2026-10-17 18:00'));

        try {
            $this->service->cancel($running);
            $this->fail('Se canceló un turno ya empezado.');
        } catch (ValidationException) {
        }

        $this->service->cancel($future, $this->a['admin'], 'Licencia');
        $this->assertSame([OnCallShift::CANCELLED, 'Licencia'], [$future->fresh()->status, $future->fresh()->close_reason]);
        $this->assertSame(0, OnCallShift::where('parent_shift_id', $future->id)->count());

        // Huecos de jueves a lunes: jueves y viernes a la noche sin nadie (el turno de Juan termina el
        // jueves 18:00; el de Ana se canceló). Sábado y domingo no empiezan franjas: lunes 00-08 no es hueco.
        $gaps = $this->service->gaps($schedule, Carbon::parse('2026-10-15 00:00'), Carbon::parse('2026-10-19 12:00'));
        $this->assertSame([['2026-10-15 18:00', '2026-10-16 08:00', 0], ['2026-10-16 18:00', '2026-10-17 08:00', 0]],
            collect($gaps)->map(fn ($g) => [$g['start']->format('Y-m-d H:i'), $g['end']->format('Y-m-d H:i'), $g['assigned']])->all());

        // Cobertura incompleta en una guardia de 2.
        $pair = $this->nights(['name' => 'Doble', 'technicians_needed' => 2]);
        $this->service->assign($pair, $this->luis, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-15 08:00'));
        $this->assertSame([['2026-10-14 18:00', '2026-10-15 08:00', 1]],
            collect($this->service->gaps($pair, Carbon::parse('2026-10-14 12:00'), Carbon::parse('2026-10-15 12:00')))->map(fn ($g) => [$g['start']->format('Y-m-d H:i'), $g['end']->format('Y-m-d H:i'), $g['assigned']])->all());
    }

    public function test_a_deactivated_technician_does_not_cover(): void
    {
        $schedule = $this->nights();
        $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-16 18:00'));
        $this->juan->delete();

        $this->assertSame([], $this->onDuty('2026-10-14 22:00')['Guardia nocturna']);
        $this->assertCount(2, $this->service->gaps($schedule, Carbon::parse('2026-10-14 12:00'), Carbon::parse('2026-10-16 12:00')));
        $this->expectException(ValidationException::class);
        $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-20 18:00'), Carbon::parse('2026-10-21 18:00'));
    }

    public function test_rotation_generates_future_shifts_and_changes_never_touch_the_past(): void
    {
        $schedule = $this->nights(['name' => 'Rotativa', 'days' => [1, 2, 3, 4, 5, 6, 7], 'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-12']);
        foreach ([$this->juan, $this->ana, $this->luis] as $i => $tech) {
            OnCallMember::create(['on_call_schedule_id' => $schedule->id, 'user_id' => $tech->id, 'position' => $i]);
        }
        $rotations = app(RotationService::class);
        $result = $rotations->sync($schedule, $this->a['admin'], Carbon::parse('2026-11-20'));

        // Turnos semanales desde el lunes 19/10 18:00 (el del 12/10 ya empezó: no se genera hacia atrás).
        $order = fn () => OnCallShift::active()->where('on_call_schedule_id', $schedule->id)->orderBy('starts_at')->get()
            ->map(fn ($s) => $s->starts_at->format('d/m').' '.$s->user->name)->all();
        $this->assertSame(['19/10 '.$this->ana->name, '26/10 '.$this->luis->name, '02/11 '.$this->juan->name, '09/11 '.$this->ana->name, '16/11 '.$this->luis->name], $order());
        $this->assertSame(5, $result['created']);
        $this->assertSame(0, $rotations->sync($schedule, null, Carbon::parse('2026-11-20'))['created']); // idempotente

        // Ana de vacaciones del 25/10 al 15/11, y se cambia el orden.
        OnCallMember::where('user_id', $this->ana->id)->update(['excluded_from' => '2026-10-25', 'excluded_until' => '2026-11-15']);
        $this->travelTo(Carbon::parse('2026-10-21 10:00')); // el turno de Ana del 19/10 ya está en curso
        OnCallMember::where('user_id', $this->luis->id)->update(['position' => 0]);
        OnCallMember::where('user_id', $this->juan->id)->update(['position' => 1]);
        OnCallMember::where('user_id', $this->ana->id)->update(['position' => 2]);
        $rotations->sync($schedule, $this->a['admin'], Carbon::parse('2026-11-20'), 'Vacaciones de Ana');

        $this->assertSame('19/10 '.$this->ana->name, $order()[0]); // en curso: no se toca
        $this->assertSame(['26/10', '02/11', '09/11', '16/11'], collect(array_slice($order(), 1))->map(fn ($r) => substr($r, 0, 5))->all());
        $this->assertNotContains($this->ana->name, collect(array_slice($order(), 1, 3))->map(fn ($r) => substr($r, 6))->all()); // excluida: se saltea
        $this->assertGreaterThan(0, OnCallShift::where('on_call_schedule_id', $schedule->id)->where('status', OnCallShift::CANCELLED)->where('close_reason', 'Vacaciones de Ana')->count());
        $this->assertSame('rotation_updated', OnCallChange::latest('id')->first()->action);
    }

    public function test_rotation_without_available_technicians_reports_instead_of_inventing(): void
    {
        $schedule = $this->nights(['name' => 'Rotativa', 'days' => [1, 2, 3, 4, 5, 6, 7], 'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19']);
        OnCallMember::create(['on_call_schedule_id' => $schedule->id, 'user_id' => $this->juan->id, 'position' => 0, 'excluded_from' => '2026-10-01', 'excluded_until' => '2026-12-31']);

        $result = app(RotationService::class)->sync($schedule, null, Carbon::parse('2026-11-10'));

        $this->assertSame(0, $result['created']);
        $this->assertCount(4, $result['uncovered']); // 19/10, 26/10, 02/11 y 09/11 sin técnico
        $this->assertSame(0, OnCallShift::count());
        $this->assertNotEmpty(app(OnCallService::class)->gaps($schedule, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-26')));
    }

    public function test_a_manual_change_inside_a_rotation_period_is_respected(): void
    {
        $schedule = $this->nights(['name' => 'Rotativa', 'days' => [1, 2, 3, 4, 5, 6, 7], 'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19']);
        OnCallMember::create(['on_call_schedule_id' => $schedule->id, 'user_id' => $this->juan->id, 'position' => 0]);
        OnCallMember::create(['on_call_schedule_id' => $schedule->id, 'user_id' => $this->ana->id, 'position' => 1]);
        $rotations = app(RotationService::class);
        $rotations->sync($schedule, null, Carbon::parse('2026-11-01'));

        $first = OnCallShift::active()->orderBy('starts_at')->first(); // Juan, 19/10
        $this->service->replace($first, Carbon::parse('2026-10-24 18:00'), null, $this->luis, $this->a['admin'], 'Cambio acordado');

        // Se invierte el orden: el período con el cambio manual queda como está.
        OnCallMember::where('user_id', $this->juan->id)->update(['position' => 5]);
        $rotations->sync($schedule, null, Carbon::parse('2026-11-01'));

        $this->assertSame([$this->luis->name], $this->onDuty('2026-10-24 22:00')['Rotativa']);
        $this->assertSame([$this->juan->name], $this->onDuty('2026-10-20 22:00')['Rotativa']);
    }

    public function test_concurrent_or_repeated_operations_do_not_duplicate(): void
    {
        $schedule = $this->nights();
        $shift = $this->service->assign($schedule, $this->juan, Carbon::parse('2026-10-20 18:00'), Carbon::parse('2026-10-27 18:00'));

        // Dos cambios sobre el mismo turno (el segundo llega con datos viejos): solo el primero.
        $stale = OnCallShift::find($shift->id);
        $this->service->replace($shift, Carbon::parse('2026-10-20 18:00'), null, $this->ana);
        try {
            $this->service->replace($stale, Carbon::parse('2026-10-20 18:00'), null, $this->luis);
            $this->fail('Se aplicó un segundo cambio sobre un turno ya modificado.');
        } catch (ValidationException) {
        }

        // Dos altas iguales seguidas (doble clic): la segunda se rechaza.
        try {
            $this->service->assign($schedule, $this->luis, Carbon::parse('2026-10-20 18:00'), Carbon::parse('2026-10-27 18:00'));
            $this->fail('Se superpuso una segunda asignación.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, OnCallShift::active()->count());
        $this->assertSame($this->ana->id, OnCallShift::active()->sole()->user_id);
    }
}
