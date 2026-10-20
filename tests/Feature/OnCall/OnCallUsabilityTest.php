<?php

namespace Tests\Feature\OnCall;

use App\Filament\Resources\OnCall\Pages\CreateOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\EditOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\OnCallHome;
use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\OnCallBoard;
use App\Services\OnCall\OnCallPlanner;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\RotationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lo que hace entendible el módulo de Guardias: la revisión previa explica
 * cada error en palabras simples (fechas, horario de la guardia, capacidad,
 * superposiciones), la portada parte las franjas cuando hay reemplazos y
 * avisa lo que falta, varios técnicos se asignan juntos o ninguno, y la
 * rotación muestra una vista previa, explica qué falta y respeta la fecha
 * hasta la que se genera.
 */
class OnCallUsabilityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private User $juan;

    private User $ana;

    private User $luis;

    private OnCallService $service;

    private OnCallPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 12:00')); // miércoles
        $this->a = $this->makeTenant();
        $this->juan = $this->a['technician'];
        $this->ana = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->luis = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Luis Técnico']);
        $this->actingInPanel($this->a['admin']);
        $this->service = app(OnCallService::class);
        $this->planner = app(OnCallPlanner::class);
    }

    /** Noches de lunes a viernes, 18:00 a 08:00 del día siguiente. */
    private function nights(array $attributes = []): OnCallSchedule
    {
        return OnCallSchedule::create(['name' => 'Guardia nocturna', 'days' => [1, 2, 3, 4, 5], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true, ...$attributes]);
    }

    private function plan(OnCallSchedule $schedule, array $users, string $from, ?string $to): array
    {
        return $this->planner->assignment($schedule, array_map(fn (User $u) => $u->id, $users), Carbon::parse($from), $to ? Carbon::parse($to) : null, $to === null);
    }

    public function test_plain_language_coverage_and_dates(): void
    {
        $this->assertSame('De lunes a viernes, de 18:00 a 08:00 del día siguiente.', $this->nights()->coverageSentence());
        $this->assertSame('Sábados y domingos, 24 horas desde las 08:00.', $this->nights(['name' => 'Finde', 'days' => [6, 7], 'starts_time' => '08:00', 'ends_time' => '08:00'])->coverageSentence());
        $this->assertSame('Todos los días, de 20:00 a medianoche.', $this->nights(['name' => 'Tarde', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '20:00', 'ends_time' => '00:00'])->coverageSentence());
    }

    public function test_the_review_explains_errors_before_saving(): void
    {
        $nights = $this->nights();

        // Fin antes del inicio (guardia nocturna con el mismo día como fin).
        $this->assertStringContainsString('elegí el día siguiente', $this->plan($nights, [$this->juan], '2026-10-15 18:00', '2026-10-15 08:00')['errors'][0]);
        // Período ya pasado: no se inventan turnos históricos.
        $this->assertStringContainsString('ya pasó', $this->plan($nights, [$this->juan], '2026-10-10 18:00', '2026-10-11 08:00')['errors'][0]);
        // Fuera del horario de la guardia (sábado de día): nadie quedaría de guardia.
        $outside = $this->plan($nights, [$this->juan], '2026-10-17 09:00', '2026-10-17 17:00')['errors'][0];
        $this->assertStringContainsString('no tiene horario de cobertura', $outside);
        $this->assertStringContainsString('De lunes a viernes, de 18:00 a 08:00 del día siguiente', $outside);
        // Nada se guardó.
        $this->assertSame(0, OnCallShift::count());
    }

    public function test_the_review_warns_about_a_partial_turn_and_an_open_ended_assignment(): void
    {
        $nights = $this->nights();

        $late = $this->plan($nights, [$this->juan], '2026-10-15 20:00', '2026-10-16 08:00');
        $this->assertSame([], $late['errors']);
        $this->assertStringContainsString('de 18:00 a 20:00 no la cubre '.$this->juan->name, implode(' ', $late['warnings']));

        $open = $this->plan($nights, [$this->juan], '2026-10-15 18:00', null);
        $this->assertSame([], $open['errors']);
        $this->assertStringContainsString('sin fecha de fin', $open['headline']);
        $this->assertStringContainsString('cubre todas las franjas de la guardia hasta que lo cambies', implode(' ', $open['details']));
        $this->assertStringContainsString('Fuera de ese horario no queda de guardia', implode(' ', $open['details']));
    }

    public function test_overlaps_say_who_already_covers_and_how_to_fix_it(): void
    {
        $nights = $this->nights();
        $this->service->assign($nights, $this->juan, Carbon::parse('2026-10-15 18:00'), Carbon::parse('2026-10-16 08:00'));

        $error = $this->plan($nights, [$this->ana], '2026-10-15 22:00', '2026-10-16 08:00')['errors'][0];
        $this->assertStringContainsString('ya la cubre '.$this->juan->name, $error);
        $this->assertStringContainsString('«Reemplazar técnico»', $error);

        $same = $this->plan($nights, [$this->juan], '2026-10-15 22:00', '2026-10-16 08:00')['errors'][0];
        $this->assertStringContainsString('Ese técnico ya tiene esta guardia en parte de ese período', $same);
    }

    public function test_the_same_technician_in_two_guards_at_once_is_warned(): void
    {
        $nights = $this->nights();
        $north = $this->nights(['name' => 'Zona norte']);
        $this->service->assign($nights, $this->juan, Carbon::parse('2026-10-15 18:00'), Carbon::parse('2026-10-16 08:00'));

        $plan = $this->plan($north, [$this->juan], '2026-10-15 18:00', '2026-10-16 08:00');
        $this->assertSame([], $plan['errors']); // puede ser a propósito: se advierte, no se bloquea
        $this->assertStringContainsString($this->juan->name.' también está de guardia en «Guardia nocturna»', implode(' ', $plan['warnings']));

        $this->service->assign($north, $this->juan, Carbon::parse('2026-10-15 18:00'), Carbon::parse('2026-10-16 08:00'));
        $warnings = collect(app(OnCallBoard::class)->build($this->a['company']->id)['warnings']);
        $this->assertTrue($warnings->contains(fn ($w) => $w['type'] === 'overlap' && str_contains($w['text'], $this->juan->name)));
    }

    public function test_several_technicians_are_assigned_together_or_not_at_all(): void
    {
        $pair = $this->nights(['name' => 'Guardia de a dos', 'technicians_needed' => 2]);
        $from = Carbon::parse('2026-10-15 18:00');
        $to = Carbon::parse('2026-10-16 08:00');

        $this->assertStringContainsString('cubrirán', $this->plan($pair, [$this->juan, $this->ana], '2026-10-15 18:00', '2026-10-16 08:00')['headline']);
        $this->service->assignMany($pair, [$this->juan, $this->ana], $from, $to);
        $this->assertSame(2, OnCallShift::count());

        // Tres en una guardia de dos: se explica cómo cambiarlo y no se guarda ninguno.
        $nextFrom = Carbon::parse('2026-10-19 18:00');
        $this->assertStringContainsString('Esta guardia es de 2 técnicos a la vez y elegiste 3', $this->plan($pair, [$this->juan, $this->ana, $this->luis], '2026-10-19 18:00', '2026-10-20 08:00')['errors'][0]);
        try {
            $this->service->assignMany($pair, [$this->juan, $this->ana, $this->luis], $nextFrom, $nextFrom->copy()->addHours(14));
            $this->fail('Se asignaron más técnicos de los que admite la guardia.');
        } catch (ValidationException) {
        }
        $this->assertSame(2, OnCallShift::count());

        // Si el segundo choca, tampoco queda el primero.
        $this->service->assign($pair, $this->luis, Carbon::parse('2026-10-20 18:00'), Carbon::parse('2026-10-21 08:00'));
        try {
            $this->service->assignMany($pair, [$this->juan, $this->luis], Carbon::parse('2026-10-20 18:00'), Carbon::parse('2026-10-21 08:00'));
            $this->fail('Se guardó una asignación a medias.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, OnCallShift::where('user_id', $this->juan->id)->where('starts_at', '2026-10-20 18:00:00')->count());
    }

    public function test_replacing_from_now_works_in_the_middle_of_a_minute(): void
    {
        // El formulario manda «20:15» y el reloj está en 20:15:30.5: antes se
        // rechazaba como «lo que ya pasó» por los segundos y microsegundos.
        $nights = $this->nights();
        $shift = $this->service->assign($nights, $this->juan, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-15 08:00'));
        $this->travelTo(Carbon::parse('2026-10-14 20:15:30.500000'));

        $this->assertSame([], $this->planner->replacement($shift, $this->ana, Carbon::parse('2026-10-14 20:15'), null)['errors']);
        $this->service->replace($shift, Carbon::parse('2026-10-14 20:15'), null, $this->ana, $this->a['admin']);

        $this->assertSame([$this->ana->id], $this->service->shiftsAt($nights, now())->pluck('user_id')->all());
    }

    public function test_the_board_splits_a_turn_when_someone_replaces_part_of_it(): void
    {
        $nights = $this->nights();
        $shift = $this->service->assign($nights, $this->juan, Carbon::parse('2026-10-14 18:00'), Carbon::parse('2026-10-15 08:00'));
        $this->service->replace($shift, Carbon::parse('2026-10-15 02:00'), null, $this->ana, $this->a['admin'], 'Se descompuso el auto');

        $upcoming = app(OnCallBoard::class)->build($this->a['company']->id)['upcoming'];
        $first = $upcoming->take(2)->map(fn ($s) => $s['start']->format('d H:i').'-'.$s['end']->format('d H:i').' '.$s['shifts']->map->user->pluck('name')->implode(','))->all();
        $this->assertSame(['14 18:00-15 02:00 '.$this->juan->name, '15 02:00-15 08:00 Ana Técnica'], $first);

        // A las 03:00: está Ana, hasta las 08:00; la próxima guardia, sin técnico.
        $this->travelTo(Carbon::parse('2026-10-15 03:00'));
        Livewire::test(OnCallHome::class)
            ->assertSeeInOrder(['De guardia ahora', 'Ana Técnica', 'Hasta hoy 08:00', 'Próxima guardia', 'Sin técnico', 'Empieza hoy 18:00']);
    }

    public function test_rotation_preview_shows_how_technicians_alternate_before_saving(): void
    {
        $members = fn (array $users) => collect($users)->map(fn (User $u) => ['user_id' => $u->id, 'excluded_from' => null, 'excluded_until' => null])->values()->all();
        $form = ['name' => 'Rotativa', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1,
            'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19'];

        // Sin participantes: explica qué falta y no genera nada.
        Livewire::test(CreateOnCallSchedule::class)->fillForm($form + ['members' => []])
            ->assertSee('Para armar la rotación falta')->assertSee('Agregá al menos un técnico a la rotación.');

        // Con participantes: ejemplo concreto y próximos turnos, sin guardar.
        Livewire::test(CreateOnCallSchedule::class)->fillForm($form + ['members' => $members([$this->juan, $this->ana, $this->luis])])
            ->assertSeeHtml('data-on-call-rotation-preview')
            ->assertSee('Así se alternan: '.$this->juan->name.' lun 19/10 18:00 a lun 26/10 18:00; después, Ana Técnica lun 26/10 18:00 a lun 02/11 18:00; después, Luis Técnico')
            ->assertSee('vuelve a empezar');
        $this->assertSame(0, OnCallSchedule::count() + OnCallShift::count());

        // Un inicio en el pasado no genera turnos hacia atrás, y lo dice.
        Livewire::test(CreateOnCallSchedule::class)->fillForm(['rotation_starts_on' => '2026-10-05'] + $form + ['members' => $members([$this->juan, $this->ana])])
            ->assertSee('no se crean turnos hacia atrás');

        // Más técnicos a la vez que participantes: explica qué falta.
        Livewire::test(CreateOnCallSchedule::class)->fillForm(['technicians_needed' => 2] + $form + ['members' => $members([$this->juan])])
            ->assertSee('agregá más participantes');
    }

    public function test_rotation_stops_at_the_chosen_end_date_and_editing_warns_what_changes(): void
    {
        $schedule = $this->nights(['name' => 'Rotativa', 'days' => [1, 2, 3, 4, 5, 6, 7], 'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19', 'rotation_ends_on' => '2026-11-02']);
        foreach ([$this->juan, $this->ana] as $i => $tech) {
            OnCallMember::create(['on_call_schedule_id' => $schedule->id, 'user_id' => $tech->id, 'position' => $i]);
        }
        app(RotationService::class)->sync($schedule);

        // 19/10, 26/10 y 02/11 (el último que empieza en la fecha de fin); nada después.
        $this->assertSame(['19/10', '26/10', '02/11'], OnCallShift::active()->orderBy('starts_at')->get()->map(fn ($s) => $s->starts_at->format('d/m'))->all());

        // Cambiar el orden: la vista previa avisa qué turnos ya generados cambian, antes de guardar.
        $record = $schedule->fresh();
        $component = Livewire::test(EditOnCallSchedule::class, ['record' => $record->getRouteKey()]);
        $items = $component->get('data.members');
        $component->set('data.members', array_reverse($items, true))
            ->assertSee('turnos ya generados cambian de técnico y se avisa a los afectados');
        $this->assertSame($this->juan->id, OnCallShift::active()->orderBy('starts_at')->first()->user_id); // todavía sin guardar

        // Pasada la fecha de fin, la portada lo advierte.
        $this->travelTo(Carbon::parse('2026-10-30 12:00'));
        $warnings = collect(app(OnCallBoard::class)->build($this->a['company']->id)['warnings']);
        $this->assertTrue($warnings->contains(fn ($w) => $w['type'] === 'rotation_ending'));
    }

    public function test_assigning_from_the_home_page_reaches_the_technician_tab(): void
    {
        $nights = $this->nights();
        Livewire::test(OnCallHome::class)
            ->callAction('assignOnCall', data: ['schedule_id' => $nights->id, 'user_id' => $this->ana->id, 'start_date' => '2026-10-15', 'start_time' => '18:00', 'end_date' => '2026-10-16', 'end_time' => '08:00'])
            ->assertHasNoActionErrors();

        $this->assertSame('Nueva guardia asignada', $this->ana->notifications()->sole()->data['title']);
        $this->flushSession();
        $this->actingAs($this->ana)->get(route('on-call.index', ['company' => $this->a['company']->slug]))
            ->assertOk()->assertSee('Próximas guardias')->assertSee('15/10/2026 18:00');
    }
}
