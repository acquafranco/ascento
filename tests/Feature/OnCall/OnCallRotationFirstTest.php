<?php

namespace Tests\Feature\OnCall;

use App\Filament\Resources\OnCall\OnCallAbsences;
use App\Filament\Resources\OnCall\Pages\CreateOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\OnCallHome;
use App\Models\OnCallChange;
use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\RotationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Guardias pensadas como un ciclo que no termina: la rotación es lo
 * recomendado al configurar, la portada muestra el orden («Así se turnan»),
 * las ausencias se cargan a un clic sin romper el orden, y si la tarea de
 * cada noche deja de correr, abrir Guardias extiende la rotación.
 */
class OnCallRotationFirstTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private User $juan;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 12:00')); // miércoles
        $this->a = $this->makeTenant();
        $this->juan = $this->a['technician'];
        $this->juan->forceFill(['name' => 'Juan Pérez'])->save();
        $this->ana = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->luis = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Luis Técnico']);
        $this->actingInPanel($this->a['admin']);
    }

    /** Semanas de lunes 18:00 a lunes 18:00, Juan → Ana → Luis desde el lunes 19/10. */
    private function rotation(array $attributes = []): OnCallSchedule
    {
        $schedule = OnCallSchedule::create(['name' => 'Guardia semanal', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '18:00',
            'technicians_needed' => 1, 'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19', 'is_active' => true, ...$attributes]);
        foreach ([$this->juan, $this->ana, $this->luis] as $i => $tech) {
            OnCallMember::create(['on_call_schedule_id' => $schedule->id, 'user_id' => $tech->id, 'position' => $i]);
        }
        app(RotationService::class)->sync($schedule);

        return $schedule;
    }

    /** Quién cubre la semana que empieza ese lunes. */
    private function weekOf(OnCallSchedule $schedule, string $monday): ?string
    {
        return OnCallShift::where('on_call_schedule_id', $schedule->id)->active()->where('starts_at', Carbon::parse($monday.' 18:00'))->first()?->user?->name;
    }

    public function test_setting_up_recommends_the_rotation_and_says_it_repeats_forever(): void
    {
        Livewire::test(CreateOnCallSchedule::class)
            ->assertFormSet(['mode' => OnCallSchedule::ROTATION])
            ->assertSee('Se turnan siempre en el mismo orden (recomendado)')
            ->assertSee('Vacío = se repite siempre.')
            ->assertDontSee('Configuración avanzada');

        // Sin técnicos cargados, arranca en «a mano» (la rotación no se podría guardar).
        User::where('role', 'technician')->delete();
        Livewire::test(CreateOnCallSchedule::class)->assertFormSet(['mode' => OnCallSchedule::MANUAL]);
    }

    public function test_the_cycle_never_ends_and_the_home_shows_it(): void
    {
        $schedule = $this->rotation();

        // Juan → Ana → Luis → Juan… (vuelve a empezar).
        $this->assertSame(['Juan Pérez', 'Ana Técnica', 'Luis Técnico', 'Juan Pérez'], [
            $this->weekOf($schedule, '2026-10-19'), $this->weekOf($schedule, '2026-10-26'), $this->weekOf($schedule, '2026-11-02'), $this->weekOf($schedule, '2026-11-09'),
        ]);

        // Meses después (con la tarea de cada noche) sigue el mismo ciclo.
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(RotationService::class)->sync($schedule);
        $weeks = (int) floor(Carbon::parse('2026-10-19')->diffInDays(Carbon::parse('2027-03-08')) / 7);
        $this->assertSame(['Juan Pérez', 'Ana Técnica', 'Luis Técnico'][$weeks % 3], $this->weekOf($schedule, '2027-03-08'));

        Livewire::test(OnCallHome::class)
            ->assertSeeHtml('data-on-call-rotation="'.$schedule->id.'"')
            ->assertSeeInOrder(['Así se turnan', 'Juan Pérez', 'Ana Técnica', 'Luis Técnico', 'vuelve a Juan Pérez'])
            ->assertSee('Se repite siempre, sin fecha de fin.')
            ->assertActionVisible('replaceOnCall')
            ->assertActionVisible('markAbsence')
            ->assertSee('Asignar guardia puntual');
    }

    public function test_a_manual_schedule_is_invited_to_set_up_the_rotation(): void
    {
        OnCallSchedule::create(['name' => 'Noches', 'days' => [1, 2, 3, 4, 5], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);

        Livewire::test(OnCallHome::class)
            ->assertSeeHtml('data-on-call-rotation-invite')
            ->assertSee('Armar la rotación')
            ->assertActionVisible('assignOnCall')
            ->assertActionDoesNotExist('markAbsence');
    }

    public function test_an_absence_skips_the_technician_without_changing_the_order(): void
    {
        $schedule = $this->rotation();
        $ana = OnCallMember::where('user_id', $this->ana->id)->sole();

        // Ana de vacaciones la semana del 26/10: antes de confirmar se ve quién cubre (sin guardar nada).
        $summary = OnCallAbsences::summaryFor($ana->id, '2026-10-26', '2026-11-01');
        $this->assertStringContainsString('lun 26/10 18:00 a lun 02/11 18:00: Luis Técnico', $summary);
        $this->assertSame('Ana Técnica', $this->weekOf($schedule, '2026-10-26'));

        Livewire::test(OnCallHome::class)
            ->callAction('markAbsence', data: ['member_id' => $ana->id, 'from' => '2026-10-26', 'until' => '2026-11-01'])
            ->assertHasNoActionErrors();

        $this->assertSame(['Juan Pérez', 'Luis Técnico', 'Luis Técnico', 'Juan Pérez'], [
            $this->weekOf($schedule, '2026-10-19'), $this->weekOf($schedule, '2026-10-26'), $this->weekOf($schedule, '2026-11-02'), $this->weekOf($schedule, '2026-11-09'),
        ]);
        $this->assertSame([0, 1, 2], OnCallMember::orderBy('position')->pluck('position')->all()); // el orden no cambia
        $this->assertTrue(OnCallChange::where('summary', 'like', 'Ausencia de Ana Técnica%')->exists());
        Livewire::test(OnCallHome::class)->assertSee('Ausente del 26/10 al 01/11');

        // Quitar la ausencia: Ana vuelve a su semana.
        Livewire::test(OnCallHome::class)->callAction('clearAbsence', arguments: ['member' => $ana->id]);
        $this->assertSame('Ana Técnica', $this->weekOf($schedule, '2026-10-26'));
        $this->assertNull($ana->fresh()->excluded_from);
    }

    public function test_an_absence_does_not_touch_the_turn_in_progress_and_says_so(): void
    {
        $schedule = $this->rotation();
        $this->travelTo(Carbon::parse('2026-10-21 12:00')); // Juan está de guardia (semana del 19/10)
        $juan = OnCallMember::where('user_id', $this->juan->id)->sole();

        // El turno en curso no se recalcula: se le sugiere «Reemplazar».
        $this->assertStringContainsString('si no puede terminarlo, usá «Reemplazar»', OnCallAbsences::summaryFor($juan->id, '2026-10-21', '2026-10-30'));
        Livewire::test(OnCallHome::class)->callAction('markAbsence', data: ['member_id' => $juan->id, 'from' => '2026-10-21', 'until' => '2026-10-30'])
            ->assertHasNoActionErrors();

        $this->assertSame('Juan Pérez', $this->weekOf($schedule, '2026-10-19'));
    }

    public function test_absences_are_validated_and_isolated(): void
    {
        $this->rotation();
        $ana = OnCallMember::where('user_id', $this->ana->id)->sole();

        // Fechas pasadas o al revés: no se guarda.
        Livewire::test(OnCallHome::class)->callAction('markAbsence', data: ['member_id' => $ana->id, 'from' => '2026-10-01', 'until' => '2026-10-05'])
            ->assertHasActionErrors();
        Livewire::test(OnCallHome::class)->callAction('markAbsence', data: ['member_id' => $ana->id, 'from' => '2026-10-20', 'until' => '2026-10-18'])
            ->assertHasActionErrors();
        $this->assertNull($ana->fresh()->excluded_from);

        // Un participante de otra empresa no se puede tocar.
        auth()->logout(); // si no, la empresa B se crearía dentro de la A
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin']);
        $foreign = OnCallSchedule::create(['name' => 'Ajena', 'days' => [1], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19', 'is_active' => true]);
        OnCallMember::create(['on_call_schedule_id' => $foreign->id, 'user_id' => $b['technician']->id, 'position' => 0]);
        $this->actingInPanel($this->a['admin']);
        $foreignMember = OnCallMember::withoutGlobalScopes()->where('on_call_schedule_id', $foreign->id)->sole();

        Livewire::test(OnCallHome::class)->callAction('markAbsence', data: ['member_id' => $foreignMember->id, 'from' => '2026-10-20', 'until' => '2026-10-25'])
            ->assertHasActionErrors();
        $this->assertNull($foreignMember->fresh()->excluded_from);
    }

    public function test_opening_guardias_extends_the_rotation_if_the_nightly_job_stopped(): void
    {
        $schedule = $this->rotation();
        $lastGenerated = OnCallShift::active()->max('starts_at');

        // Pasan semanas sin que corra la tarea de cada noche.
        $this->travelTo(Carbon::parse('2026-11-20 12:00'));
        Livewire::test(OnCallHome::class);
        $this->assertTrue(Carbon::parse(OnCallShift::active()->max('starts_at'))->gt(Carbon::parse($lastGenerated)));
        $this->assertTrue(Carbon::parse(OnCallShift::active()->max('starts_at'))->gte(now()->addDays(28)));

        // Volver a abrir no genera nada más ni ensucia el historial.
        $shifts = OnCallShift::count();
        $changes = OnCallChange::count();
        Livewire::test(OnCallHome::class);
        $this->assertSame([$shifts, $changes], [OnCallShift::count(), OnCallChange::count()]);
    }
}
