<?php

namespace Tests\Feature\WorkOrders;

use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\ActivityLog;
use App\Models\ClientClaim;
use App\Models\DeliveryNote;
use App\Models\OnCallSchedule;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Claims\ClientClaimService;
use App\Services\OnCall\OnCallService;
use App\Services\WorkOrders\AttendanceTimes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Tiempos de atención: AVISO (cargado, nunca deducido de la hora de carga),
 * LLEGADA y SALIDA (hora del servidor, una vez, solo el técnico asignado) y
 * las tres duraciones, sin inventar valores. Nada de esto cierra la orden ni
 * reemplaza la firma del remito.
 */
class AttentionTimesTest extends TestCase
{
    use InteractsWithTenants, MakesSignatures, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        auth()->logout();
        Carbon::setTestNow('2026-10-24 13:25:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function slug(): string
    {
        return $this->a['company']->slug;
    }

    private function open(User $tech, array $overrides = [])
    {
        return $this->actingAs($tech)->post(route('interventions.store', ['company' => $this->slug()]), [
            'building_id' => $this->a['building']->id, 'work_type' => 'claim', 'elevator_number' => 'Ascensor 1', 'channel' => 'whatsapp',
            'kind' => 'urgent', 'description' => 'Ascensor detenido entre pisos.', 'submission_token' => (string) Str::uuid(), ...$overrides,
        ]);
    }

    private function arrive(User $user, WorkOrder $order, array $payload = [])
    {
        return $this->actingAs($user)->post(route('work-orders.arrive', ['company' => $user->company->slug, 'workOrder' => $order->id]), $payload);
    }

    private function leave(User $user, WorkOrder $order)
    {
        return $this->actingAs($user)->post(route('work-orders.leave', ['company' => $user->company->slug, 'workOrder' => $order->id]));
    }

    public function test_the_full_example_aviso_arrival_and_exit_gives_the_three_durations(): void
    {
        $tech = $this->a['technician'];
        // Aviso 13:10 (lo carga el técnico: la hora del mensaje). La orden se carga a las 13:25.
        $this->open($tech, ['call_received_at' => '2026-10-24T13:10'])->assertSessionHasNoErrors();
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $this->assertSame(['2026-10-24 13:10', 'technician', $tech->id], [$order->call_received_at->format('Y-m-d H:i'), $order->call_source, $order->call_recorded_by]);
        $this->assertSame('2026-10-24 13:25', $order->created_at->format('Y-m-d H:i')); // la hora de carga se conserva aparte

        // Abrir la orden no registra la llegada.
        $show = route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]);
        $this->get($show)->assertOk()->assertSee('Llegué al edificio')->assertSee('data-slide-confirm="arrive"', false);
        $this->assertNull($order->fresh()->arrived_at);

        Carbon::setTestNow('2026-10-24 14:00:00');
        $this->arrive($tech, $order, ['arrived_at' => '2026-10-24 13:11:00'])->assertRedirect($show)->assertSessionHas('success', 'Llegada registrada: 24/10 14:00.');
        Carbon::setTestNow('2026-10-24 14:40:00');
        $this->leave($tech, $order)->assertSessionHas('success', 'Visita finalizada. Salida registrada: 24/10 14:40.');

        $order = $order->fresh();
        $this->assertSame(['14:00', $tech->id, '14:40', $tech->id], [$order->arrived_at->format('H:i'), $order->arrived_by, $order->left_at->format('H:i'), $order->left_by]);
        $this->assertSame(['response' => 50, 'on_site' => 40, 'total' => 90], AttendanceTimes::durations($order));
        $this->assertSame(['50 min', '40 min', '1 h 30 min'], array_map([AttendanceTimes::class, 'duration'], [50, 40, 90]));

        $html = $this->get($show)->assertOk()->getContent();
        foreach (['data-duration="response">50 min', 'data-duration="on_site">40 min', 'data-duration="total">1 h 30 min', 'Cargado por el técnico'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Ni la llegada ni la salida cerraron la orden.
        $this->assertSame('in_progress', $order->status);
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
    }

    public function test_incomplete_records_show_sin_registrar_and_never_invent_durations(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech); // sin hora de aviso
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $this->assertNull($order->call_received_at);

        Carbon::setTestNow('2026-10-24 14:00:00');
        $this->arrive($tech, $order);
        $order = $order->fresh();
        $this->assertSame(['response' => null, 'on_site' => null, 'total' => null], AttendanceTimes::durations($order));
        $html = $this->get(route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]))->getContent();
        $this->assertStringContainsString('data-time="call">Sin registrar', $html);
        $this->assertStringContainsString('data-duration="response">Sin registrar', $html);
        $this->assertStringContainsString('data-duration="total">Sin registrar', $html);

        // Horarios al revés (aviso cargado después de la llegada): se rechaza, no se calcula una duración negativa.
        $this->assertNull(AttendanceTimes::durations(tap(new WorkOrder)->forceFill(['call_received_at' => now(), 'arrived_at' => now()->subHour()]))['response']);
    }

    public function test_arrival_and_exit_are_registered_once_and_cannot_be_forced(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech);
        $order = WorkOrder::withoutGlobalScopes()->sole();

        // Salida antes de la llegada: no.
        $this->leave($tech, $order)->assertSessionHas('warning', 'Primero registrá la llegada al edificio.');
        $this->assertNull($order->fresh()->left_at);

        Carbon::setTestNow('2026-10-24 14:00:00');
        $this->arrive($tech, $order);
        Carbon::setTestNow('2026-10-24 14:05:00');
        $this->arrive($tech, $order)->assertSessionHas('warning', 'La llegada ya estaba registrada: 24/10 14:00.');
        $this->assertSame('14:00', $order->fresh()->arrived_at->format('H:i'));

        Carbon::setTestNow('2026-10-24 14:30:00');
        $this->leave($tech, $order);
        Carbon::setTestNow('2026-10-24 15:30:00');
        $this->leave($tech, $order)->assertSessionHas('warning', 'La salida ya estaba registrada: 24/10 14:30.');
        $this->assertSame('14:30', $order->fresh()->left_at->format('H:i'));

        // Las horas no son asignables desde ningún formulario.
        $order->fill(['arrived_at' => now(), 'left_at' => now(), 'call_received_at' => now()]);
        $this->assertFalse($order->isDirty(['arrived_at', 'left_at', 'call_received_at']));
    }

    public function test_exit_is_allowed_after_signing_the_remito_but_not_days_later_and_the_remito_still_needs_its_signature(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech);
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $job = Report::withoutGlobalScopes()->sole();
        Carbon::setTestNow('2026-10-24 14:00:00');
        $this->arrive($tech, $order);

        // La llegada no habilita un remito sin firma.
        $this->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'elevator_quantity' => 2,
            'freight_elevator_quantity' => 0, 'signature_name' => 'Lucas', 'signature' => '',
            'jobs' => [$job->id => ['work_done' => 'Listo.', 'result' => 'resolved', 'elevator_state' => 'in_service']],
        ])->assertSessionHasErrors('signature');
        $this->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'elevator_quantity' => 2,
            'freight_elevator_quantity' => 0, 'signature_name' => 'Lucas', 'signature' => $this->validSignature(),
            'jobs' => [$job->id => ['work_done' => 'Listo.', 'result' => 'resolved', 'elevator_state' => 'in_service']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        // Firmar el remito no registra la salida ni cierra la visita; el listado de remitos muestra el paso final.
        $this->assertNull($order->fresh()->left_at);
        $this->get(route('delivery-notes.index', ['company' => $this->slug()]))->assertSee('Finalizar visita y retirarme');

        // Después del remito, dentro del plazo: sí. Ya registrada no se puede llegar de nuevo.
        Carbon::setTestNow('2026-10-24 14:20:00');
        $this->leave($tech, $order)->assertSessionHas('success');
        $this->arrive($tech, $order)->assertSessionHas('warning');

        // Otra orden (otra visita): dos días después de la llegada, ya no; se cierra sin salida con motivo.
        $this->open($tech, ['description' => 'Otra urgencia en el mismo edificio.']);
        $second = WorkOrder::withoutGlobalScopes()->latest('id')->first();
        $this->arrive($tech, $second);
        $second->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
        Carbon::setTestNow('2026-10-26 15:00:00');
        $this->leave($tech, $second)->assertSessionHas('warning', 'Pasaron más de 24 h desde la llegada: ya no se registra la salida. Cerrá la visita indicando el motivo.');
        $this->assertNull($second->fresh()->left_at);
    }

    public function test_only_the_assigned_technician_marks_times_on_call_or_not_and_never_across_companies(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech);
        $order = WorkOrder::withoutGlobalScopes()->sole();

        // Otro técnico de la empresa, aunque esté de guardia ahora: no es su orden.
        $peer = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($this->a['admin']);
        $schedule = OnCallSchedule::create(['name' => 'Guardia', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '00:00', 'ends_time' => '00:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        app(OnCallService::class)->assign($schedule, $peer, now()->subDay(), null, $this->a['admin']);
        auth()->logout();
        $this->arrive($peer, $order)->assertSessionHas('warning', 'Esta orden no está asignada a vos.');
        $this->leave($peer, $order)->assertSessionHas('warning');
        $this->get(route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertDontSee('Llegué al edificio');
        $this->assertNull($order->fresh()->arrived_at);

        // Una orden pendiente (sin tomar) todavía no admite la llegada.
        $this->actingAs($this->a['admin']);
        $pending = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'status' => 'pending']);
        $pending->users()->attach($tech->id);
        auth()->logout();
        $this->arrive($tech, $pending)->assertSessionHas('warning', 'Primero tomá el trabajo.');
        $this->assertNull($pending->fresh()->arrived_at);

        // Otra empresa: ni con su URL ni con la propia.
        $foreign = $this->b['technician'];
        $this->actingAs($foreign)->post(route('work-orders.arrive', ['company' => $this->b['company']->slug, 'workOrder' => $order->id]))->assertNotFound();
        $this->assertContains($this->actingAs($foreign)->post(route('work-orders.arrive', ['company' => $this->slug(), 'workOrder' => $order->id]))->status(), [302, 403, 404]);
        $this->assertNull($order->fresh()->arrived_at);
    }

    public function test_the_office_records_the_call_time_with_who_and_validates_it(): void
    {
        $this->actingAs($this->a['admin']);
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'status' => 'in_progress']);
        $order->users()->attach($this->a['technician']->id);
        auth()->logout();
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Tiempos de atención')->assertSee('Sin registrar')
            ->fillForm(['call_received_at' => '2026-10-24 12:50', 'users' => [$this->a['technician']->id]])->call('save')->assertHasNoFormErrors();
        $order = $order->fresh();
        $this->assertSame(['2026-10-24 12:50', 'office', $this->a['admin']->id], [$order->call_received_at->format('Y-m-d H:i'), $order->call_source, $order->call_recorded_by]);
        $this->assertSame('work_order.call_time', ActivityLog::where('subject_id', $order->id)->latest('id')->value('action'));

        // Futura o después de la llegada: no.
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['call_received_at' => '2026-10-24 18:00', 'users' => [$this->a['technician']->id]])->call('save')->assertHasFormErrors(['call_received_at']);
        $order->forceFill(['arrived_at' => '2026-10-24 13:00:00'])->save();
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['call_received_at' => '2026-10-24 13:20', 'users' => [$this->a['technician']->id]])->call('save')->assertHasFormErrors(['call_received_at']);
        $this->assertSame('12:50', $order->fresh()->call_received_at->format('H:i'));

        // El técnico tampoco puede cargar un aviso futuro ni de hace más de una semana.
        auth()->logout();
        $this->flushSession();
        $this->open($this->a['technician'], ['call_received_at' => '2026-10-24T15:00'])->assertSessionHasErrors('call_received_at');
        $this->open($this->a['technician'], ['call_received_at' => '2026-10-10T15:00'])->assertSessionHasErrors('call_received_at');
    }

    public function test_an_order_from_a_portal_claim_takes_the_time_the_claim_arrived_not_when_it_happened(): void
    {
        $this->actingAs($this->a['admin']);
        $claim = new ClientClaim;
        $claim->forceFill([
            'company_id' => $this->a['company']->id, 'number' => 1, 'client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id,
            'category' => 'stopped', 'description' => 'No anda', 'status' => 'new', 'origin' => 'portal',
            'occurred_at' => '2026-10-24 08:00:00', 'created_at' => '2026-10-24 09:15:00', 'updated_at' => '2026-10-24 09:15:00',
        ])->save();
        $this->actingInPanel($this->a['admin']);
        $order = app(ClientClaimService::class)->createWorkOrder($claim, $this->a['admin']);

        $this->assertSame(['2026-10-24 09:15', 'portal', null], [$order->call_received_at->format('Y-m-d H:i'), $order->call_source, $order->call_recorded_by]);
    }

    public function test_the_job_report_records_the_elevator_state_and_what_is_pending(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech);
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $job = Report::withoutGlobalScopes()->sole();
        $post = fn (array $row) => $this->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'elevator_quantity' => 2,
            'freight_elevator_quantity' => 0, 'signature_name' => 'Lucas', 'signature' => $this->validSignature(), 'jobs' => [$job->id => $row],
        ]);

        // Estado del equipo obligatorio; si no quedó resuelto, lo pendiente también.
        $post(['work_done' => 'Se trabó el freno.', 'result' => 'follow_up'])
            ->assertSessionHasErrors(['jobs.'.$job->id.'.elevator_state', 'jobs.'.$job->id.'.pending_work']);
        $post(['work_done' => 'Se trabó el freno.', 'result' => 'follow_up', 'elevator_state' => 'flying'])->assertSessionHasErrors('jobs.'.$job->id.'.elevator_state');
        $post(['work_done' => 'Se liberó el freno; falta la bobina.', 'result' => 'follow_up', 'elevator_state' => 'out_of_service', 'pending_work' => 'Cambiar la bobina del freno.'])
            ->assertSessionHasNoErrors();

        $job = $job->fresh();
        $this->assertSame(['out_of_service', 'Cambiar la bobina del freno.', 'en_revision'], [$job->elevator_state, $job->pending_work, $job->status]);
        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->get(route('delivery-notes.show', ['company' => $this->slug(), 'deliveryNote' => $note]))
            ->assertSeeText('Estado del equipo: Fuera de servicio')->assertSeeText('Pendiente:')->assertSee('Cambiar la bobina del freno.');
    }
}
