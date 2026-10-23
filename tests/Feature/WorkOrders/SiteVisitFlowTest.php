<?php

namespace Tests\Feature\WorkOrders;

use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Schemas\WorkOrderForm;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\OnCallSchedule;
use App\Models\Report;
use App\Models\SiteVisit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OnCall\OnCallService;
use App\Services\Technicians\TechnicianSheet;
use App\Services\WorkOrders\AttendanceTimes;
use App\Services\WorkOrders\SiteVisits;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Visita técnica (SiteVisits): un técnico en un edificio, de «Llegué al
 * edificio» a «Finalizar visita y retirarme», con varios trabajos y remitos
 * adentro. El remito no registra la salida ni cierra la visita; la salida no
 * cierra órdenes. Horas del servidor, una vez, sin inventar nada.
 */
class SiteVisitFlowTest extends TestCase
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
        Carbon::setTestNow('2026-10-24 09:00:00');
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

    /** Orden de la oficina, pendiente y asignada al técnico. */
    private function officeOrder(?Building $building = null, ?User $tech = null, array $attributes = []): WorkOrder
    {
        $order = WorkOrder::factory()->create(['building_id' => ($building ?? $this->a['building'])->id, 'status' => 'pending', ...$attributes]);
        $order->users()->attach(($tech ?? $this->a['technician'])->id);

        return $order;
    }

    private function take(User $tech, WorkOrder $order)
    {
        return $this->actingAs($tech)->post(route('work-orders.start', ['company' => $tech->company->slug, 'workOrder' => $order->id]));
    }

    private function arrive(User $tech, Building $building, ?WorkOrder $order = null)
    {
        return $this->actingAs($tech)->from(route('dashboard', ['company' => $tech->company->slug]))
            ->post(route('site-visits.arrive', ['company' => $tech->company->slug]), ['building_id' => $building->id, 'work_order_id' => $order?->id]);
    }

    private function leave(User $tech, SiteVisit $visit)
    {
        return $this->actingAs($tech)->from(route('dashboard', ['company' => $tech->company->slug]))
            ->post(route('site-visits.leave', ['company' => $tech->company->slug, 'siteVisit' => $visit->id]));
    }

    private function remitoForOrder(User $tech, WorkOrder $order, array $overrides = [])
    {
        $jobs = Report::withoutGlobalScopes()->where('work_order_id', $order->id)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['work_done' => 'Hecho.', 'result' => 'resolved', 'elevator_state' => 'in_service']])->all();

        return $this->actingAs($tech)->post("/{$tech->company->slug}/delivery-notes/store", [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order',
            'description' => 'Trabajo de la orden.', 'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'signature_name' => 'Técnico', 'signature' => $this->validSignature(), 'jobs' => $jobs,
            'visit_flow' => 1, 'submission_token' => (string) Str::uuid(), ...$overrides,
        ]);
    }

    private function periodicRemito(User $tech, Building $building, string $type = 'maintenance', array $overrides = [])
    {
        return $this->actingAs($tech)->post("/{$tech->company->slug}/delivery-notes/store", [
            'building_id' => $building->id, 'assignment_type' => $type, 'description' => 'Visita del mes.', 'month' => 10, 'year' => 2026,
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0, 'signature_name' => 'Técnico', 'signature' => $this->validSignature(),
            'visit_flow' => 1, 'submission_token' => (string) Str::uuid(), ...$overrides,
        ]);
    }

    private function openIntervention(User $tech, Building $building, string $description = 'Ascensor detenido.')
    {
        return $this->actingAs($tech)->post(route('interventions.store', ['company' => $tech->company->slug]), [
            'building_id' => $building->id, 'work_type' => 'claim', 'elevator_number' => 'Ascensor 1', 'channel' => 'whatsapp',
            'kind' => 'urgent', 'description' => $description, 'submission_token' => (string) Str::uuid(),
        ]);
    }

    private function visit(): SiteVisit
    {
        return SiteVisit::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    // 1 · Una orden con un solo trabajo y un remito; 3 · la llegada antes del remito; 5 · la salida después.
    public function test_one_order_one_remito_in_order_take_arrive_remito_leave(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder(attributes: ['call_received_at' => null]);

        // Sin tomar: no hay llegada. Tomarla no registra la llegada ni abre la orden.
        $this->arrive($tech, $this->a['building'], $order)->assertSessionHas('warning', 'Primero tomá el trabajo.');
        Carbon::setTestNow('2026-10-24 09:10:00');
        $this->take($tech, $order);
        $order->refresh();
        $this->assertSame('in_progress', $order->status);
        $this->assertNull($order->arrived_at);
        $this->assertSame(0, SiteVisit::withoutGlobalScopes()->count());

        // La orden muestra el paso 2, no el remito; el listado también.
        $show = route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]);
        $this->get($show)->assertSee('Llegué al edificio')->assertDontSee('Hacer remito de esta orden')->assertDontSee('Finalizar visita y retirarme');
        $this->get(route('work-orders.index', ['company' => $this->slug(), 'status' => 'in_progress']))->assertSee('data-next-step="arrive"', false);

        // 3 · Sin llegada, el remito no se firma (salvo con motivo explícito).
        $this->remitoForOrder($tech, $order)->assertSessionHasErrors('arrival_exception');
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
        $this->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]))
            ->assertSee('Primero, la llegada')->assertSee('data-slide-confirm="arrive-remito"', false);

        // Llegada: hora del servidor, en la visita y en la orden.
        Carbon::setTestNow('2026-10-24 09:40:00');
        $this->arrive($tech, $this->a['building'], $order)->assertSessionHas('success');
        $visit = $this->visit();
        $order->refresh();
        $this->assertSame(['09:40', $visit->id, $tech->id], [$visit->arrived_at->format('H:i'), $order->site_visit_id, $order->arrived_by]);
        $this->assertSame('09:40', $order->arrived_at->format('H:i'));
        $this->get($show)->assertDontSee('data-slide-confirm="arrive"', false)->assertSee('Hacer remito de esta orden');
        $this->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertSee('data-arrived-chip', false)->assertDontSee('Primero, la llegada');

        // Remito: completa la orden, NO registra la salida ni cierra la visita.
        Carbon::setTestNow('2026-10-24 10:30:00');
        $this->remitoForOrder($tech, $order)->assertSessionHasNoErrors()->assertSessionHas('success', 'Remito generado correctamente.');
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNull($order->left_at);
        $this->assertTrue($visit->fresh()->isOpen());
        $this->assertSame($visit->id, BuildingVisit::withoutGlobalScopes()->where('work_order_id', $order->id)->value('site_visit_id'));
        $this->get(route('delivery-notes.index', ['company' => $this->slug()]))->assertSee('Finalizar visita y retirarme')->assertSee('data-site-visit="'.$visit->id.'"', false);

        // 5 · Salida: hora del servidor; duraciones en la orden.
        Carbon::setTestNow('2026-10-24 10:45:00');
        $this->leave($tech, $visit)->assertSessionHas('success', 'Visita finalizada. Salida registrada: 24/10 10:45.');
        $order->refresh();
        $this->assertSame('10:45', $order->left_at->format('H:i'));
        $this->assertSame(65, AttendanceTimes::durations($order)['on_site']);
        $this->assertNull(AttendanceTimes::durations($order)['response']); // sin aviso: «Sin registrar»
        $this->get(route('dashboard', ['company' => $this->slug()]))->assertDontSee('Finalizar visita y retirarme');
    }

    // 2 · Varios ascensores y varios remitos en la misma visita; 4 · ningún remito registra la salida.
    public function test_one_visit_with_several_elevators_orders_and_remitos(): void
    {
        $tech = $this->a['technician'];
        $first = $this->officeOrder(attributes: ['unit' => 'Ascensor 1']);
        $second = $this->officeOrder(attributes: ['unit' => 'Ascensor 2']);
        $this->take($tech, $first);
        $this->take($tech, $second);

        // Una llegada para el edificio: las dos órdenes en curso entran en la visita.
        $this->arrive($tech, $this->a['building'])->assertSessionHas('success');
        $visit = $this->visit();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], WorkOrder::withoutGlobalScopes()->where('site_visit_id', $visit->id)->pluck('id')->all());

        // Una urgencia abierta estando ahí entra en la misma visita, sin repetir la llegada.
        Carbon::setTestNow('2026-10-24 09:30:00');
        $this->openIntervention($tech, $this->a['building'], 'Puerta de Ascensor 2 no cierra.');
        $urgent = WorkOrder::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($visit->id, $urgent->site_visit_id);
        $this->assertSame('09:30', $urgent->arrived_at->format('H:i')); // nunca antes de que existiera

        // Remitos: cada orden el suyo, más el mantenimiento del mes. La visita sigue abierta.
        $this->remitoForOrder($tech, $first)->assertSessionHasNoErrors();
        $this->remitoForOrder($tech, $second)->assertSessionHasNoErrors();
        $this->periodicRemito($tech, $this->a['building'])->assertSessionHasNoErrors();
        $this->assertSame(3, DeliveryNote::withoutGlobalScopes()->count());
        $this->assertSame(3, BuildingVisit::withoutGlobalScopes()->where('site_visit_id', $visit->id)->count());
        $this->assertTrue($visit->fresh()->isOpen());
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->whereNotNull('left_at')->count());

        // Queda la urgencia sin remito: se muestra, no bloquea la salida y no se cierra sola.
        $this->get(route('dashboard', ['company' => $this->slug()]))->assertSee('Queda un trabajo sin remito')->assertSee('Orden #'.$urgent->id);
        $this->leave($tech, $visit)->assertSessionHas('success', fn ($m) => str_contains($m, 'Una orden quedó en curso, sin remito.'));
        $this->assertSame('in_progress', $urgent->fresh()->status);
        // Las terminadas llevan la salida; la pendiente, no («Sin registrar» hasta que se termine).
        $this->assertSame(2, WorkOrder::withoutGlobalScopes()->whereNotNull('left_at')->count());
        $this->assertNull($urgent->fresh()->left_at);
    }

    // 6 · Dos edificios, visitas y horarios independientes (y nunca dos abiertas a la vez).
    public function test_two_buildings_have_independent_visits_and_times(): void
    {
        $tech = $this->a['technician'];
        $other = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $other->users()->attach($tech->id, ['type' => 'maintenance']);
        $here = $this->officeOrder();
        $there = $this->officeOrder($other);
        $this->take($tech, $here);
        $this->take($tech, $there);

        $this->arrive($tech, $this->a['building'])->assertSessionHas('success');
        $first = $this->visit();
        $this->assertNull($there->fresh()->site_visit_id); // la del otro edificio no entra

        // Con una visita abierta acá, no se llega a otro edificio (no se cierra sola ni se inventa la salida).
        $this->arrive($tech, $other, $there)->assertSessionHas('warning', fn ($m) => str_contains($m, 'Finalizala antes'));
        $this->assertSame(1, SiteVisit::withoutGlobalScopes()->count());
        // El remito del otro edificio tampoco entra en esta visita.
        $this->remitoForOrder($tech, $there)->assertSessionHasErrors('arrival_exception');

        $this->remitoForOrder($tech, $here)->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->leave($tech, $first);
        Carbon::setTestNow('2026-10-24 10:30:00');
        $this->arrive($tech, $other, $there)->assertSessionHas('success');
        $second = $this->visit();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(['09:00', '10:00', '10:30'], [$here->fresh()->arrived_at->format('H:i'), $here->fresh()->left_at->format('H:i'), $there->fresh()->arrived_at->format('H:i')]);
        $this->assertNull($there->fresh()->left_at);
        // Salir de la segunda no toca la primera.
        Carbon::setTestNow('2026-10-24 11:00:00');
        $this->leave($tech, $second);
        $this->assertSame('10:00', $first->fresh()->left_at->format('H:i'));
    }

    // 7 · Mantenimientos e inspecciones del mes: la misma visita, sin orden.
    public function test_maintenance_and_inspection_remitos_need_the_arrival_and_share_the_visit(): void
    {
        $tech = $this->a['technician'];
        $this->a['building']->users()->attach($tech->id, ['type' => 'inspection']);

        $this->periodicRemito($tech, $this->a['building'])->assertSessionHasErrors('arrival_exception');
        $this->get(route('delivery-notes.building', ['company' => $this->slug(), 'building' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026]))
            ->assertSee('Primero, la llegada');

        $this->arrive($tech, $this->a['building'])->assertSessionHas('success');
        $visit = $this->visit();
        $this->periodicRemito($tech, $this->a['building'])->assertSessionHasNoErrors();
        $this->periodicRemito($tech, $this->a['building'], 'inspection')->assertSessionHasNoErrors();
        $this->assertSame(['maintenance', 'inspection'], BuildingVisit::withoutGlobalScopes()->where('site_visit_id', $visit->id)->orderBy('id')->pluck('assignment_type')->all());
        $this->assertTrue($visit->fresh()->isOpen());

        // Un edificio que no tiene asignado: ni llegada ni visita.
        $foreignBuilding = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->leave($tech, $visit);
        $this->arrive($tech, $foreignBuilding)->assertSessionHas('warning', 'Este edificio no está asignado a vos.');
        $this->assertSame(1, SiteVisit::withoutGlobalScopes()->count());
    }

    // 8 · Urgencias y guardia: la guardia no abre visitas en cualquier edificio; su intervención sí.
    public function test_on_call_urgencies_use_the_visit_but_on_call_alone_gives_no_access(): void
    {
        $peer = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $unassigned = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($this->a['admin']);
        $schedule = OnCallSchedule::create(['name' => 'Guardia', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '00:00', 'ends_time' => '00:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        app(OnCallService::class)->assign($schedule, $peer, now()->subDay(), null, $this->a['admin']);
        auth()->logout();

        // De guardia, sin trabajo ahí: no hay llegada.
        $this->arrive($peer, $unassigned)->assertSessionHas('warning', 'Este edificio no está asignado a vos.');

        // Abre la urgencia (regla de guardias existente) → la orden es suya → llegada, remito, salida.
        $this->openIntervention($peer, $unassigned)->assertSessionHasNoErrors();
        $urgent = WorkOrder::withoutGlobalScopes()->sole();
        $this->assertNotNull($urgent->on_call_shift_id);
        $this->arrive($peer, $unassigned, $urgent)->assertSessionHas('success');
        $visit = $this->visit();
        $this->remitoForOrder($peer, $urgent)->assertSessionHasNoErrors();
        $this->leave($peer, $visit)->assertSessionHas('success');
        $this->assertNotNull($urgent->fresh()->left_at);

        // Las órdenes de otro técnico no entran en su visita.
        $this->arrive($peer, $unassigned, $urgent)->assertSessionHas('warning'); // ya cerrada
        $theirs = $this->officeOrder($this->a['building']);
        $this->take($this->a['technician'], $theirs);
        $this->arrive($this->a['technician'], $this->a['building'])->assertSessionHas('success');
        $this->assertNotSame($visit->id, $theirs->fresh()->site_visit_id);
    }

    // 9 · Aviso conocido y desconocido: las duraciones solo con los dos horarios.
    public function test_known_and_unknown_call_times(): void
    {
        $tech = $this->a['technician'];
        $known = $this->officeOrder(attributes: ['call_received_at' => '2026-10-24 08:30:00', 'call_source' => 'office']);
        $unknown = $this->officeOrder(attributes: ['unit' => 'Ascensor 2']);
        $this->take($tech, $known);
        $this->take($tech, $unknown);
        $this->arrive($tech, $this->a['building']);
        $this->remitoForOrder($tech, $known)->assertSessionHasNoErrors();
        $this->remitoForOrder($tech, $unknown)->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->leave($tech, $this->visit());

        $this->assertSame(['response' => 30, 'on_site' => 60, 'total' => 90], AttendanceTimes::durations($known->fresh()));
        $this->assertSame(['response' => null, 'on_site' => 60, 'total' => null], AttendanceTimes::durations($unknown->fresh()));
        $this->assertSame('Sin registrar', AttendanceTimes::duration(AttendanceTimes::durations($unknown->fresh())['response']));
    }

    // 10 · Llegada y salida no se duplican.
    public function test_arrival_and_exit_are_not_duplicated(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $this->arrive($tech, $this->a['building'])->assertSessionHas('success');
        Carbon::setTestNow('2026-10-24 09:20:00');
        $this->arrive($tech, $this->a['building'])->assertSessionHas('warning', 'La llegada ya estaba registrada: 24/10 09:00.');
        $this->actingAs($tech)->post(route('work-orders.arrive', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertSessionHas('warning');
        $this->assertSame(1, SiteVisit::withoutGlobalScopes()->count());
        $this->assertSame('09:00', $this->visit()->arrived_at->format('H:i'));

        $visit = $this->visit();
        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->leave($tech, $visit)->assertSessionHas('success');
        Carbon::setTestNow('2026-10-24 10:05:00');
        $this->leave($tech, $visit)->assertSessionHas('warning', 'La salida ya estaba registrada: 24/10 10:00.');
        $this->assertSame('10:00', $visit->fresh()->left_at->format('H:i'));

        // Las horas no son asignables desde un formulario.
        try {
            $visit->fill(['arrived_at' => now(), 'left_at' => now()]);
        } catch (MassAssignmentException) {
        }
        $this->assertFalse($visit->isDirty());

        // Pasado el plazo: no hay salida; cierre explícito, auditado, sin inventar la hora.
        $late = $this->officeOrder(attributes: ['unit' => 'Ascensor 2']);
        $this->take($tech, $late);
        $this->arrive($tech, $this->a['building']);
        $open = $this->visit();
        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->leave($tech, $open)->assertSessionHas('warning', fn ($m) => str_contains($m, 'Pasaron más de 24 h'));
        $this->actingAs($tech)->post(route('site-visits.close', ['company' => $this->slug(), 'siteVisit' => $open->id]), ['close_note' => 'corto'])->assertSessionHas('warning');
        $this->actingAs($tech)->post(route('site-visits.close', ['company' => $this->slug(), 'siteVisit' => $open->id]), ['close_note' => 'Me olvidé de registrar la salida.'])->assertSessionHas('success');
        $open->refresh();
        $this->assertNotNull($open->closed_at);
        $this->assertNull($open->left_at);
        $this->assertNull($late->fresh()->left_at);
        $this->assertSame('in_progress', $late->fresh()->status);
        // Queda quién, cuándo y por qué (la actividad del panel registra además los cierres de la oficina).
        $this->assertSame([$tech->id, 'Me olvidé de registrar la salida.'], [$open->closed_by, $open->close_note]);
    }

    // 11 · Permisos y aislamiento entre empresas.
    public function test_permissions_and_company_isolation(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $this->arrive($tech, $this->a['building']);
        $visit = $this->visit();

        // Otra empresa: ni su edificio, ni su visita, ni con su URL.
        $foreign = $this->b['technician'];
        $this->actingAs($foreign)->from('/')->post(route('site-visits.arrive', ['company' => $this->b['company']->slug]), ['building_id' => $this->a['building']->id])->assertNotFound();
        $this->actingAs($foreign)->post(route('site-visits.leave', ['company' => $this->b['company']->slug, 'siteVisit' => $visit->id]))->assertNotFound();
        $this->assertContains($this->actingAs($foreign)->post(route('site-visits.leave', ['company' => $this->slug(), 'siteVisit' => $visit->id]))->status(), [302, 403, 404]);
        $this->assertContains($this->actingAs($foreign)->post(route('site-visits.close', ['company' => $this->slug(), 'siteVisit' => $visit->id]), ['close_note' => 'Intento de otra empresa.'])->status(), [302, 403, 404]);

        // El motivo «sin llegada» no saltea permisos: sin asignación, nada se firma.
        auth()->logout();
        $this->flushSession();
        $intruder = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->remitoForOrder($intruder, $order, ['arrival_exception' => 'Quiero firmar una orden que no es mía.'])->assertForbidden();
        $this->periodicRemito($intruder, $this->a['building'], overrides: ['arrival_exception' => 'Edificio que no tengo asignado.'])->assertForbidden();
        $this->periodicRemito($intruder, $this->a['building'], overrides: ['visit_flow' => null])->assertForbidden();
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());

        // Otro técnico de la misma empresa: no cierra ni finaliza una visita ajena.
        auth()->logout(); // (crear un usuario con sesión iniciada lo deja en la empresa de esa sesión)
        $this->flushSession();
        $peer = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($peer)->post(route('site-visits.leave', ['company' => $this->slug(), 'siteVisit' => $visit->id]))->assertNotFound();
        $this->actingAs($peer)->post(route('site-visits.close', ['company' => $this->slug(), 'siteVisit' => $visit->id]), ['close_note' => 'No es mía pero la cierro.'])->assertNotFound();
        $visit->refresh();
        $this->assertTrue($visit->isOpen());
        $this->assertNull($visit->left_at);

        // La oficina (Documentos) no registra llegadas: su formulario de remito no pide la llegada.
        $order->users()->attach($this->a['admin']->id);
        auth()->logout();
        $this->flushSession();
        $this->actingAs($this->a['admin'])->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]))
            ->assertOk()->assertDontSee('Primero, la llegada')->assertDontSee('name="visit_flow"', false);
        $this->assertFalse(SiteVisits::canManage($peer));
        $this->assertTrue(SiteVisits::canManage($this->a['admin']));
        // Panel: la orden muestra la visita y la oficina la cierra sin salida, con motivo.
        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Visita técnica')->assertSee('en curso')
            ->callAction('closeSiteVisit', data: ['close_note' => 'corto'])->assertHasActionErrors(['close_note']);
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('closeSiteVisit', data: ['close_note' => 'Cerrada por la oficina: el técnico avisó que se fue.'])->assertHasNoActionErrors();
        $this->assertStringContainsString('cerrada sin salida', WorkOrderForm::siteVisitSummary($order->fresh()));
        $this->assertSame($this->a['admin']->id, $visit->fresh()->closed_by);
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('action', 'site_visit.closed_without_exit')->exists());
        $this->assertNull($visit->fresh()->left_at);
    }

    // 12 · Recordatorios: una vez cada uno, solo el paso que falta.
    public function test_reminders_are_sent_once_and_only_for_the_missing_step(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $reminders = fn (string $kind) => DatabaseNotification::where('notifiable_id', $tech->id)->get()->filter(fn ($n) => str_contains(json_encode($n->data), $kind === 'arrival' ? 'Ya llegaste' : 'Terminaste la visita'))->count();

        // Recién tomada: nada. Una hora después: el de llegada, una sola vez.
        $this->artisan('site-visits:remind')->assertSuccessful();
        $this->assertSame(0, $reminders('arrival'));
        $this->get(route('dashboard', ['company' => $this->slug()]))->assertSee('data-awaiting-arrival="'.$order->id.'"', false);
        Carbon::setTestNow('2026-10-24 10:05:00');
        $this->artisan('site-visits:remind');
        $this->artisan('site-visits:remind');
        $this->assertSame(1, $reminders('arrival'));

        // Con la llegada registrada: ya no se recuerda la llegada.
        $this->arrive($tech, $this->a['building']);
        $this->get(route('dashboard', ['company' => $this->slug()]))->assertDontSee('data-awaiting-arrival', false)->assertSee('Finalizar visita y retirarme');

        // Con trabajos pendientes: no se recuerda la salida. Con el remito hecho hace 30 min: una vez.
        $this->artisan('site-visits:remind');
        $this->assertSame(0, $reminders('exit'));
        $this->remitoForOrder($tech, $order)->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-24 10:20:00');
        $this->artisan('site-visits:remind');
        $this->assertSame(0, $reminders('exit'));
        Carbon::setTestNow('2026-10-24 10:40:00');
        $this->artisan('site-visits:remind');
        $this->artisan('site-visits:remind');
        $this->assertSame(1, $reminders('exit'));

        // Finalizada: ningún aviso nuevo.
        $this->leave($tech, $this->visit());
        Carbon::setTestNow('2026-10-24 18:00:00');
        $this->artisan('site-visits:remind');
        $this->assertSame([1, 1], [$reminders('arrival'), $reminders('exit')]);
    }

    // 13 · Un solo envío genera el remito; 14 · un reintento no lo duplica (ni da error).
    public function test_one_submit_creates_the_remito_and_a_retry_does_not_duplicate_it(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $this->arrive($tech, $this->a['building']);
        $token = (string) Str::uuid();

        // El formulario lleva su identidad también en las órdenes.
        $this->actingAs($tech)->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]))
            ->assertSee('name="submission_token"', false)->assertSee('name="visit_flow"', false);

        $this->remitoForOrder($tech, $order, ['submission_token' => $token])->assertSessionHasNoErrors()
            ->assertRedirect(route('delivery-notes.index', ['company' => $this->slug()]))->assertSessionHas('success', 'Remito generado correctamente.');
        $this->assertSame(1, DeliveryNote::withoutGlobalScopes()->count());

        // Reintento del MISMO formulario (respuesta perdida, otro toque): la misma confirmación, sin 409.
        $this->remitoForOrder($tech, $order, ['submission_token' => $token])->assertRedirect(route('delivery-notes.index', ['company' => $this->slug()]))
            ->assertSessionHas('success', 'Remito generado correctamente.');
        // Otro formulario para la misma orden: no hay un segundo remito.
        $this->remitoForOrder($tech, $order)->assertStatus(409);
        $this->assertSame(1, DeliveryNote::withoutGlobalScopes()->count());

        // Periódico: igual.
        $periodicToken = (string) Str::uuid();
        $this->periodicRemito($tech, $this->a['building'], overrides: ['submission_token' => $periodicToken])->assertSessionHasNoErrors();
        $this->periodicRemito($tech, $this->a['building'], overrides: ['submission_token' => $periodicToken])->assertSessionHas('success');
        $this->assertSame(2, DeliveryNote::withoutGlobalScopes()->count());
    }

    // 15 · Si falla la validación, vuelve lo que el técnico escribió (sin la firma, que se vuelve a hacer).
    public function test_validation_errors_keep_what_the_technician_entered(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $form = route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]);

        // Sin llegada ni motivo: error en el motivo; lo escrito vuelve.
        $payload = [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'visit_flow' => 1,
            'description' => 'Cambié el contactor de la puerta.', 'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'signature_name' => 'Técnico', 'signature' => $this->validSignature(), 'client_signature_name' => 'Marta',
            'arrival_exception' => 'sin', 'submission_token' => $token = (string) Str::uuid(),
        ];
        $this->actingAs($tech)->from($form)->post("/{$this->slug()}/delivery-notes/store", $payload)->assertRedirect($form)->assertSessionHasErrors('arrival_exception');
        $page = $this->actingAs($tech)->from($form)->followingRedirects()->post("/{$this->slug()}/delivery-notes/store", $payload);
        $this->assertMatchesRegularExpression('/id="arrival-exception-box"\s+open/', $page->getContent());
        $page->assertSee('Cambié el contactor de la puerta.')->assertSee('value="Marta"', false)->assertSee($token)
            ->assertSee('Primero registrá la llegada al edificio');
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());

        // Con motivo explícito: se firma, queda el motivo y la auditoría; la llegada sigue «Sin registrar».
        $this->remitoForOrder($tech, $order, ['arrival_exception' => 'Llegué sin señal y no pude registrarla.'])->assertSessionHasNoErrors();
        $this->assertSame('Llegué sin señal y no pude registrarla.', BuildingVisit::withoutGlobalScopes()->where('work_order_id', $order->id)->value('arrival_exception'));
        $this->assertNull($order->fresh()->arrived_at);
    }

    // 16 · Registros históricos: órdenes con llegada propia, remitos viejos y formularios anteriores.
    public function test_historical_records_keep_working(): void
    {
        $tech = $this->a['technician'];

        // Orden con llegada por orden (d777b75), sin visita: su remito y su salida siguen como antes.
        $legacy = $this->officeOrder();
        $this->take($tech, $legacy);
        $legacy->forceFill(['arrived_at' => now(), 'arrived_by' => $tech->id])->save();
        $this->remitoForOrder($tech, $legacy)->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-24 09:30:00');
        $this->get(route('delivery-notes.index', ['company' => $this->slug()]))->assertSee('Registrá la salida');
        $this->actingAs($tech)->post(route('work-orders.leave', ['company' => $this->slug(), 'workOrder' => $legacy->id]))->assertSessionHas('success', 'Salida registrada: 24/10 09:30.');
        $this->assertSame(0, SiteVisit::withoutGlobalScopes()->count());

        // Formulario anterior a las visitas (sin visit_flow): se acepta como antes, sin visita,
        // pero nunca en silencio: queda marcado (quién y cuándo: los de la visita del remito).
        $this->periodicRemito($tech, $this->a['building'], overrides: ['visit_flow' => null])->assertSessionHasNoErrors();
        $note = BuildingVisit::withoutGlobalScopes()->latest('id')->first();
        $this->assertNull($note->site_visit_id);
        $this->assertSame(SiteVisits::LEGACY_FORM_EXCEPTION, $note->arrival_exception);
        $this->assertSame([$tech->id, '2026-10-24 09:30'], [$note->user_id, $note->created_at->format('Y-m-d H:i')]);
        $this->assertNull(SiteVisit::withoutGlobalScopes()->first());

        // Remitos y órdenes viejas se ven igual en el panel y en el listado.
        $this->get(route('delivery-notes.index', ['company' => $this->slug()]))->assertOk();
        $this->assertSame(2, DeliveryNote::withoutGlobalScopes()->count());
        $this->assertSame('Llegada registrada en la orden (antes de las visitas técnicas).', WorkOrderForm::siteVisitSummary($legacy->fresh()));
    }

    // Revisión · dos técnicos en la misma orden: cada uno su visita; la orden guarda la llegada
    // del primero y la salida de quien la cerró; la planilla no le atribuye al otro horarios ajenos.
    public function test_two_technicians_on_the_same_order_never_take_each_others_times(): void
    {
        [$first, $second] = [$this->a['technician'], $this->secondTechnician()];
        $order = $this->officeOrder();
        $order->users()->attach($second->id);
        $this->take($first, $order);

        $this->arrive($first, $this->a['building'])->assertSessionHas('success');
        $firstVisit = $this->visit();
        Carbon::setTestNow('2026-10-24 09:30:00');
        $this->arrive($second, $this->a['building'])->assertSessionHas('success');
        $secondVisit = $this->visit();
        $this->assertNotSame($firstVisit->id, $secondVisit->id);
        $this->assertSame($firstVisit->id, $order->fresh()->site_visit_id); // entra una sola vez, en la del primero

        // El primero se va con la orden pendiente: la orden NO recibe su salida.
        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->leave($first, $firstVisit)->assertSessionHas('success');
        $this->assertNull($order->fresh()->left_at);

        // El segundo firma y se va: la salida de la orden es la suya.
        Carbon::setTestNow('2026-10-24 11:00:00');
        $this->remitoForOrder($second, $order)->assertSessionHasNoErrors();
        $this->assertSame($secondVisit->id, BuildingVisit::withoutGlobalScopes()->where('work_order_id', $order->id)->value('site_visit_id'));
        Carbon::setTestNow('2026-10-24 11:15:00');
        $this->leave($second, $secondVisit)->assertSessionHas('success');
        $order->refresh();
        $this->assertSame(['09:00', $first->id, '11:15', $second->id], [$order->arrived_at->format('H:i'), $order->arrived_by, $order->left_at->format('H:i'), $order->left_by]);
        $this->assertSame('10:00', $firstVisit->fresh()->left_at->format('H:i'));

        // Planillas: cada uno con SUS horarios.
        $sheet = app(TechnicianSheet::class);
        $this->assertSame(['09:30 a 11:15'], $sheet->day($second, now())->pluck('time')->all());
        $this->assertNotContains('09:00 a 11:15', $sheet->day($first, now())->pluck('time')->all());
    }

    // Revisión · una orden pendiente al salir no recibe salida y, otro día, pide la nueva llegada.
    public function test_a_job_left_pending_has_no_exit_and_needs_the_next_arrival(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $this->arrive($tech, $this->a['building']);
        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->leave($tech, $this->visit());
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertNull($order->fresh()->left_at);

        // Al día siguiente, sin llegada: ni el remito ni la llegada «vieja» alcanzan.
        Carbon::setTestNow('2026-10-25 09:00:00');
        $this->remitoForOrder($tech, $order)->assertSessionHasErrors('arrival_exception');
        $this->get(route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertSee('data-slide-confirm="arrive"', false);
        $this->arrive($tech, $this->a['building'], $order)->assertSessionHas('success');
        $next = $this->visit();
        $this->remitoForOrder($tech, $order)->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-25 09:40:00');
        $this->leave($tech, $next);
        $order->refresh();
        // Respuesta: la primera llegada. Salida: la de la visita que la terminó.
        $this->assertSame(['24 09:00', '25 09:40'], [$order->arrived_at->format('d H:i'), $order->left_at->format('d H:i')]);
        $this->assertSame($next->id, BuildingVisit::withoutGlobalScopes()->where('work_order_id', $order->id)->value('site_visit_id'));
    }

    // Revisión · una visita olvidada abierta no suma trabajos de otro día ni recibe una llegada nueva.
    public function test_a_forgotten_open_visit_does_not_absorb_work_from_other_days(): void
    {
        $tech = $this->a['technician'];
        $this->arrive($tech, $this->a['building'])->assertSessionHas('success');
        $forgotten = $this->visit();

        Carbon::setTestNow('2026-10-26 15:00:00');
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $this->assertNull($order->fresh()->site_visit_id);
        $this->assertNull($order->fresh()->arrived_at);
        $this->periodicRemito($tech, $this->a['building'])->assertSessionHasErrors('arrival_exception');
        $this->arrive($tech, $this->a['building'], $order)->assertSessionHas('warning', fn ($m) => str_contains($m, 'Cerrala desde Inicio'));
        $this->get(route('dashboard', ['company' => $this->slug()]))->assertSee('No puedo registrar la salida')->assertDontSee('data-slide-confirm="leave-visit-', false);

        $this->actingAs($tech)->post(route('site-visits.close', ['company' => $this->slug(), 'siteVisit' => $forgotten->id]), ['close_note' => 'Me olvidé de finalizarla el sábado.']);
        $this->arrive($tech, $this->a['building'], $order)->assertSessionHas('success');
        $this->assertSame('26 15:00', $order->fresh()->arrived_at->format('d H:i'));
        $this->assertNotSame($forgotten->id, $order->fresh()->site_visit_id);
        $this->assertNull($forgotten->fresh()->left_at);
    }

    // Revisión · el remito sin llegada se ve en el panel: motivo, quién y cuándo.
    public function test_the_office_sees_why_a_remito_was_signed_without_arrival(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->take($tech, $order);
        $this->remitoForOrder($tech, $order, ['arrival_exception' => 'Llegué sin señal y no pude registrarla.'])->assertSessionHasNoErrors();
        $summary = WorkOrderForm::siteVisitSummary($order->fresh());
        $this->assertStringContainsString('Remito sin llegada registrada ('.$tech->name.', 24/10 09:00): Llegué sin señal y no pude registrarla.', $summary);

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        auth()->logout();
        $this->flushSession();
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ViewDeliveryNote::class, ['record' => $note->getRouteKey()])
            ->assertSee('Sin llegada registrada: Llegué sin señal y no pude registrarla.');
    }

    private function secondTechnician(): User
    {
        $second = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($second->id, ['type' => 'maintenance']);

        return $second;
    }
}
