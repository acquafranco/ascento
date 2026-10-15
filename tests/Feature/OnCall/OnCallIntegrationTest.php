<?php

namespace Tests\Feature\OnCall;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Filament\Resources\OnCall\Pages\CreateOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\EditOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\ListOnCallSchedules;
use App\Filament\Resources\OnCall\Pages\ViewOnCallSchedule;
use App\Filament\Resources\OnCall\RelationManagers\ShiftsRelationManager;
use App\Filament\Resources\OnCall\Widgets\OnCallOverview;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\MaintenanceService;
use App\Models\OnCallChange;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Companies\CompanyEraser;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\OnCall\OnCallService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Guardias en el resto de Ascento: panel administrativo, permisos, aislamiento,
 * órdenes de trabajo y remitos, pestaña «Guardias» del técnico, avisos,
 * mantenimientos periódicos, exportación y borrado de empresas.
 */
class OnCallIntegrationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 12:00')); // miércoles
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->ana = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->actingInPanel($this->a['admin']);
    }

    private function schedule(array $attributes = []): OnCallSchedule
    {
        return OnCallSchedule::create(['name' => 'Guardia nocturna', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true, ...$attributes]);
    }

    private function assign(OnCallSchedule $schedule, User $tech, string $from, ?string $to): OnCallShift
    {
        return app(OnCallService::class)->assign($schedule, $tech, Carbon::parse($from), $to ? Carbon::parse($to) : null, $this->a['admin']);
    }

    private function techPage(User $tech)
    {
        $this->flushSession();

        return $this->actingAs($tech)->get(route('on-call.index', ['company' => $tech->company->slug]));
    }

    /*
    |--------------------------------------------------------------------------
    | PANEL ADMINISTRATIVO
    |--------------------------------------------------------------------------
    */

    public function test_the_admin_creates_a_guard_assigns_and_sees_who_is_on_duty_now(): void
    {
        Livewire::test(CreateOnCallSchedule::class)
            ->fillForm(['name' => 'Guardia nocturna', 'days' => [1, 2, 3, 4, 5], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual'])
            ->call('create')->assertHasNoFormErrors();
        $schedule = OnCallSchedule::sole();
        $this->assertSame([$this->a['company']->id, [1, 2, 3, 4, 5], '18:00:00', '08:00:00'], [$schedule->company_id, $schedule->days, $schedule->starts_time, $schedule->ends_time]);

        Livewire::test(ViewOnCallSchedule::class, ['record' => $schedule->getRouteKey()])
            ->callAction('assign', data: ['user_id' => $this->a['technician']->id, 'starts_at' => '2026-10-14 18:00', 'ends_at' => null, 'reason' => null])
            ->assertHasNoActionErrors();
        $this->assertNull(OnCallShift::sole()->ends_at); // permanente

        $this->travelTo(Carbon::parse('2026-10-14 23:00'));
        Livewire::test(OnCallOverview::class)->assertSeeHtml('data-on-call-now')->assertSee($this->a['technician']->name);
        Livewire::test(OnCallOverview::class)->set('at', '2026-10-17T22:00')->assertSee('ninguna guardia cubre ese horario'); // sábado
        Livewire::test(ListOnCallSchedules::class)->assertTableColumnStateSet('on_duty', $this->a['technician']->name, $schedule);
        $this->get(OnCallScheduleResource::getUrl('index'))->assertOk()->assertSee('De guardia ahora');
    }

    public function test_replacements_and_cancellations_from_the_panel_with_reason(): void
    {
        $schedule = $this->schedule();
        $shift = $this->assign($schedule, $this->a['technician'], '2026-10-19 18:00', '2026-10-26 18:00');
        $rm = fn () => Livewire::test(ShiftsRelationManager::class, ['ownerRecord' => $schedule, 'pageClass' => ViewOnCallSchedule::class]);

        $rm()->callTableAction('replace', $shift, data: ['user_id' => $this->ana->id, 'from' => '2026-10-24 18:00', 'to' => '2026-10-25 18:00', 'reason' => 'Enfermedad'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['Enfermedad', $this->a['admin']->id], [OnCallChange::where('action', 'replaced')->sole()->reason, OnCallChange::where('action', 'replaced')->sole()->user_id]);

        $after = OnCallShift::active()->where('user_id', $this->a['technician']->id)->orderByDesc('starts_at')->first(); // 25/10 → 26/10
        $rm()->callTableAction('cancel', $after, data: ['reason' => 'Ya no hace falta']);
        $this->assertSame(OnCallShift::CANCELLED, $after->fresh()->status);

        // Un cambio inválido no se guarda y lo explica.
        $ana = OnCallShift::active()->where('user_id', $this->ana->id)->sole();
        $rm()->callTableAction('replace', $ana, data: ['user_id' => $this->a['technician']->id, 'from' => '2026-10-01 18:00', 'to' => null, 'reason' => null])
            ->assertNotified('No se guardó el cambio');
        $this->assertSame(OnCallShift::ACTIVE, $ana->fresh()->status);
    }

    public function test_finishing_a_guard_cancels_the_future_and_keeps_history(): void
    {
        $schedule = $this->schedule();
        $past = $this->assign($schedule, $this->a['technician'], '2026-10-01 18:00', '2026-10-15 18:00');
        $future = $this->assign($schedule, $this->ana, '2026-10-20 18:00', '2026-10-27 18:00');

        Livewire::test(ViewOnCallSchedule::class, ['record' => $schedule->getRouteKey()])->callAction('finishSchedule', data: ['reason' => 'Fin del contrato']);

        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertSame(OnCallShift::CANCELLED, $future->fresh()->status);
        $this->assertSame([$this->a['technician']->name], collect(app(OnCallService::class)->onDutyAt($this->a['company']->id, Carbon::parse('2026-10-05 22:00')))->first()['shifts']->map->user->map->name->all());
        $this->assertSame('schedule_finished', OnCallChange::latest('id')->first()->action);
    }

    public function test_coverage_hours_are_locked_once_shifts_started(): void
    {
        $schedule = $this->schedule();
        $this->assign($schedule, $this->a['technician'], '2026-10-13 18:00', '2026-10-20 18:00');

        Livewire::test(EditOnCallSchedule::class, ['record' => $schedule->getRouteKey()])
            ->assertFormFieldIsDisabled('starts_time')->assertFormFieldIsDisabled('days')
            ->fillForm(['name' => 'Guardia nocturna (zona norte)'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['Guardia nocturna (zona norte)', '18:00:00'], [$schedule->fresh()->name, $schedule->fresh()->starts_time]);
        $this->assertSame('schedule_updated', OnCallChange::latest('id')->first()->action);
    }

    public function test_rotation_created_from_the_panel(): void
    {
        Livewire::test(CreateOnCallSchedule::class)
            ->fillForm(['name' => 'Rotativa', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1,
                'mode' => 'rotation', 'rotation_days' => 7, 'rotation_starts_on' => '2026-10-19',
                'members' => [['user_id' => $this->a['technician']->id, 'excluded_from' => null, 'excluded_until' => null], ['user_id' => $this->ana->id, 'excluded_from' => null, 'excluded_until' => null]]])
            ->call('create')->assertHasNoFormErrors();

        $shifts = OnCallShift::active()->orderBy('starts_at')->get();
        $this->assertGreaterThanOrEqual(7, $shifts->count());
        $this->assertSame([$this->a['technician']->id, $this->ana->id, $this->a['technician']->id], $shifts->take(3)->pluck('user_id')->all());
        $this->assertTrue($shifts->every(fn ($s) => $s->origin === 'rotation' && $s->starts_at->format('H:i') === '18:00'));

        Artisan::call('oncall:rotations'); // idempotente
        $this->assertSame($shifts->count(), OnCallShift::active()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | PERMISOS Y AISLAMIENTO
    |--------------------------------------------------------------------------
    */

    public function test_staff_permissions_and_technicians_cannot_reach_the_admin(): void
    {
        $schedule = $this->schedule();
        $staff = fn (array $levels, string $name) => app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
        $viewer = $staff(['on_call' => 'view'], 'Mira guardias');
        $none = $staff(['clients' => 'view'], 'Sin guardias');

        $this->flushSession();
        $this->actingInPanel($viewer);
        $this->get(OnCallScheduleResource::getUrl('index'))->assertOk();
        $this->get(OnCallScheduleResource::getUrl('create'))->assertForbidden();
        Livewire::test(ViewOnCallSchedule::class, ['record' => $schedule->getRouteKey()])->assertActionHidden('assign')->assertActionHidden('finishSchedule');
        $this->assertSame(0, OnCallShift::count());

        $this->flushSession();
        $this->actingInPanel($none);
        $this->get(OnCallScheduleResource::getUrl('index'))->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->a['technician'])->get(OnCallScheduleResource::getUrl('index'))->assertRedirect(route('dashboard', ['company' => $this->a['company']->slug]));
    }

    public function test_companies_are_isolated(): void
    {
        $mine = $this->schedule();
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $theirs = $this->schedule(['name' => 'Guardia B']);

        // B no ve la guardia de A ni puede usarla, ni asignar un técnico de A.
        $this->get(OnCallScheduleResource::getUrl('view', ['record' => $mine]))->assertNotFound();
        Livewire::test(ListOnCallSchedules::class)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$mine]);
        foreach ([[$mine, $this->b['technician']], [$theirs, $this->a['technician']]] as [$schedule, $tech]) {
            try {
                app(OnCallService::class)->assign($schedule, $tech, now()->addDay(), now()->addDays(2), $this->b['admin']);
                $this->fail('Se asignó entre empresas.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, OnCallShift::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | TRABAJOS Y REMITOS
    |--------------------------------------------------------------------------
    */

    public function test_a_work_order_registered_during_a_guard_and_its_delivery_note(): void
    {
        $schedule = $this->schedule();
        $shift = $this->assign($schedule, $this->a['technician'], '2026-10-14 18:00', '2026-10-21 18:00');
        $this->travelTo(Carbon::parse('2026-10-14 23:30'));

        Livewire::test(CreateWorkOrder::class)
            ->assertSee('De guardia ahora: '.$this->a['technician']->name)
            ->fillForm(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->a['technician']->id], 'on_call_shift_id' => $shift->id, 'type' => 'claim', 'priority' => 'urgent', 'status' => 'pending', 'notes' => 'Persona atrapada'])
            ->call('create')->assertHasNoFormErrors();

        $order = WorkOrder::sole();
        $this->assertSame([$shift->id, $this->a['building']->id], [$order->on_call_shift_id, $order->building_id]);
        $this->assertSame($order->id, $schedule->workOrders()->sole()->id);

        // Un trabajo nocturno sin indicar guardia NO se vuelve de guardia.
        Livewire::test(CreateWorkOrder::class)
            ->fillForm(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->ana->id], 'type' => 'claim', 'priority' => 'medium', 'status' => 'pending'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertNull(WorkOrder::latest('id')->first()->on_call_shift_id);

        // Remito firmado de esa orden: el panel lo muestra discretamente.
        $note = DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order']);
        $before = $note->fresh()->toArray();
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))->assertOk()->assertSee('de guardia: '.$this->a['technician']->name);

        // Cambiar la guardia después (reemplazo futuro) no reescribe el remito ni la orden.
        $this->travelTo(Carbon::parse('2026-10-16 10:00'));
        app(OnCallService::class)->replace($shift, Carbon::parse('2026-10-16 18:00'), null, $this->ana, $this->a['admin'], 'Cambio');
        $this->assertSame($before, $note->fresh()->toArray());
        $this->assertSame([$shift->id, $this->a['technician']->id], [$order->fresh()->on_call_shift_id, $order->fresh()->onCallShift->user_id]);

        // Con remito, la guardia de la orden ya no se cambia.
        $other = OnCallShift::active()->where('user_id', $this->ana->id)->sole();
        $order->update(['on_call_shift_id' => $other->id]);
        $this->assertSame($shift->id, $order->fresh()->on_call_shift_id);
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])->assertFormFieldIsDisabled('on_call_shift_id');
    }

    public function test_a_work_order_cannot_point_to_another_companys_guard(): void
    {
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $foreign = app(OnCallService::class)->assign($this->schedule(['name' => 'B']), $this->b['technician'], now(), now()->addDay(), $this->b['admin']);

        $this->flushSession();
        $this->actingInPanel($this->a['admin']);
        Livewire::test(CreateWorkOrder::class)
            ->fillForm(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->a['technician']->id], 'on_call_shift_id' => $foreign->id, 'type' => 'claim', 'priority' => 'medium', 'status' => 'pending'])
            ->call('create')->assertHasFormErrors(['on_call_shift_id']);
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_periodic_maintenance_keeps_working_the_same(): void
    {
        $service = MaintenanceService::create(['client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id, 'description' => 'Abono', 'amount' => 1000, 'frequency' => 'monthly', 'start_date' => '2026-10-01', 'payment_due_day' => 20, 'status' => 'active']);
        $rowsBefore = app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance')->count();

        $this->assign($this->schedule(), $this->a['technician'], '2026-10-14 18:00', '2026-10-21 18:00');
        $visit = BuildingVisit::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'visited_at' => now()]);

        $this->assertSame($service->id, $visit->maintenance_service_id);
        $this->assertSame($rowsBefore, app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | PESTAÑA «GUARDIAS» DEL TÉCNICO
    |--------------------------------------------------------------------------
    */

    public function test_the_tab_is_in_the_technician_navigation_and_shows_a_clear_empty_state(): void
    {
        $page = $this->techPage($this->a['technician'])->assertOk();

        $page->assertSee('href="'.route('on-call.index', ['company' => $this->a['company']->slug]).'"', false)
            ->assertSeeHtml('data-on-call-empty')->assertSee('No tenés guardias programadas');
        $this->actingAs($this->a['technician'])->get(route('dashboard', ['company' => $this->a['company']->slug]))
            ->assertOk()->assertSee('Guardias')->assertDontSeeHtml('data-on-call-banner');
    }

    public function test_the_technician_sees_current_upcoming_past_and_changes_only_their_own(): void
    {
        $schedule = $this->schedule(['notes' => 'Llamar a la oficina al 4000-1000']);
        $this->assign($schedule, $this->a['technician'], '2026-10-01 18:00', '2026-10-08 18:00');   // pasada
        $current = $this->assign($schedule, $this->a['technician'], '2026-10-14 08:00', '2026-10-15 18:00');
        $this->assign($schedule, $this->a['technician'], '2026-10-22 18:00', '2026-10-29 18:00');   // próxima
        $this->assign($schedule, $this->ana, '2026-10-16 18:00', '2026-10-21 18:00');               // de otro técnico
        $this->travelTo(Carbon::parse('2026-10-14 21:00'));

        $this->techPage($this->a['technician'])->assertOk()
            ->assertSeeHtml('data-on-call-active')->assertSee('Estás de guardia ahora')->assertSee('Llamar a la oficina al 4000-1000')
            ->assertSee('22/10/2026 18:00')->assertSee('01/10/2026 18:00')
            ->assertDontSee('16/10/2026 18:00'); // el turno de Ana no se ve

        $this->actingAs($this->a['technician'])->get(route('dashboard', ['company' => $this->a['company']->slug]))->assertSeeHtml('data-on-call-banner');

        // Un cambio de guardia se refleja para los dos, sin tocar el pasado.
        $this->travelTo(Carbon::parse('2026-10-15 09:00'));
        app(OnCallService::class)->replace(OnCallShift::active()->where('user_id', $this->a['technician']->id)->where('starts_at', Carbon::parse('2026-10-22 18:00'))->sole(),
            Carbon::parse('2026-10-24 18:00'), Carbon::parse('2026-10-25 18:00'), $this->ana, $this->a['admin'], 'Cambio acordado');

        $this->techPage($this->a['technician'])->assertSee('Te reemplaza '.$this->ana->name)->assertSee('01/10/2026 18:00');
        $this->techPage($this->ana)->assertSee('Reemplazás a '.$this->a['technician']->name)->assertSee('Cambio de guardia: reemplazás a');
        $this->assertSame(OnCallShift::ACTIVE, $current->fresh()->status);
    }

    public function test_the_tab_lists_only_guard_work_assigned_to_the_technician_and_is_read_only(): void
    {
        $schedule = $this->schedule();
        $shift = $this->assign($schedule, $this->a['technician'], '2026-10-14 18:00', '2026-10-21 18:00');
        $mine = WorkOrder::create(['building_id' => $this->a['building']->id, 'on_call_shift_id' => $shift->id, 'type' => 'claim', 'status' => 'completed', 'priority' => 'urgent', 'unit' => 'Ascensor 1']);
        $mine->users()->attach($this->a['technician']->id);
        $notMine = WorkOrder::create(['building_id' => $this->a['building']->id, 'on_call_shift_id' => $shift->id, 'type' => 'claim', 'status' => 'pending', 'priority' => 'low', 'unit' => 'Ascensor 2']);
        $notMine->users()->attach($this->ana->id);
        $regular = WorkOrder::create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'status' => 'pending', 'priority' => 'low', 'unit' => 'Ascensor 1']);
        $regular->users()->attach($this->a['technician']->id);

        $page = $this->techPage($this->a['technician'])->assertOk()->assertSeeHtml('data-on-call-work-orders');
        $page->assertSee(route('work-orders.show', ['company' => $this->a['company']->slug, 'workOrder' => $mine->id]), false)->assertSee('Realizado')
            ->assertDontSee(route('work-orders.show', ['company' => $this->a['company']->slug, 'workOrder' => $notMine->id]), false)
            ->assertDontSee(route('work-orders.show', ['company' => $this->a['company']->slug, 'workOrder' => $regular->id]), false);
        // Solo consulta: los únicos formularios son los del menú (cerrar sesión), igual que una pestaña vacía.
        $empty = $this->techPage($this->b['technician'])->getContent();
        $this->assertSame(substr_count($empty, '<form'), substr_count($page->getContent(), '<form'));

        // No hay rutas para modificar la planificación desde la app del técnico.
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('on-call.index')->methods());
        $this->assertSame(['on-call.index'], collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_ends_with($r->uri(), '}/guardias') || str_contains($r->uri(), '}/guardias/'))->map->getName()->values()->all());

        // Otra empresa: su técnico no ve nada de esta.
        $this->techPage($this->b['technician'])->assertOk()->assertSee('No tenés guardias programadas')->assertDontSee($schedule->name);
    }

    /*
    |--------------------------------------------------------------------------
    | AVISOS, EXPORTACIÓN Y BORRADO
    |--------------------------------------------------------------------------
    */

    public function test_notifications_for_assignment_change_and_upcoming_guard(): void
    {
        $schedule = $this->schedule();
        $shift = $this->assign($schedule, $this->a['technician'], '2026-10-15 08:00', '2026-10-22 18:00');
        $this->assertSame('Nueva guardia asignada', $this->a['technician']->notifications()->sole()->data['title']);

        app(OnCallService::class)->replace($shift, Carbon::parse('2026-10-16 18:00'), Carbon::parse('2026-10-17 18:00'), $this->ana, $this->a['admin']);
        $this->assertSame(['Nueva guardia asignada', 'Cambió una de tus guardias'], $this->a['technician']->notifications()->oldest()->get()->pluck('data.title')->all());
        $this->assertStringContainsString('reemplazás a', $this->ana->notifications()->sole()->data['body']);

        // Recordatorio: una sola vez por turno, para los que empiezan en las próximas 24 h.
        Artisan::call('oncall:remind');
        Artisan::call('oncall:remind');
        $this->assertSame(1, $this->a['technician']->notifications()->where('data->title', 'Tu guardia empieza pronto')->count());
        $this->assertSame(0, $this->ana->notifications()->where('data->title', 'Tu guardia empieza pronto')->count()); // el suyo empieza en más de 24 h
    }

    public function test_company_erasure_includes_guards(): void
    {
        $schedule = $this->schedule();
        $shift = $this->assign($schedule, $this->a['technician'], '2026-10-14 18:00', '2026-10-21 18:00');
        app(OnCallService::class)->replace($shift, Carbon::parse('2026-10-16 18:00'), null, $this->ana, $this->a['admin']);
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'on_call_shift_id' => $shift->id, 'type' => 'claim', 'status' => 'pending', 'priority' => 'low', 'unit' => 'Ascensor 1']);
        $this->assertNotNull($order->id);

        $companyId = $this->a['company']->id;
        $this->flushSession();
        auth()->logout(); // el SuperAdmin se crea sin sesión (un admin no puede crear uno)
        $root = User::factory()->superAdmin()->create(['company_id' => null]);
        $this->actingAs($root);
        app(CompanyEraser::class)->erase($this->a['company']->fresh(), $root, $this->a['company']->name);

        foreach (['on_call_schedules', 'on_call_shifts', 'on_call_changes', 'on_call_members', 'work_orders'] as $table) {
            $this->assertSame(0, \DB::table($table)->where('company_id', $companyId)->count(), $table);
        }
        $this->assertGreaterThan(0, \DB::table('companies')->count()); // la otra empresa sigue
    }
}
