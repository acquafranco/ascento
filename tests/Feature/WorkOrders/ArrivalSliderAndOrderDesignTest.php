<?php

namespace Tests\Feature\WorkOrders;

use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\SiteVisit;
use App\Models\WorkOrder;
use App\Support\WorkOrderLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Un único deslizador «Llegué al edificio» (sin botón aparte), «Aviso recibido»
 * como lo único de los tiempos que carga la oficina, «Tiempos de atención» de
 * solo lectura y los colores de los estados (sin cambiar estados ni transiciones).
 */
class ArrivalSliderAndOrderDesignTest extends TestCase
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

    private function officeOrder(array $attributes = []): WorkOrder
    {
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'pending', ...$attributes]);
        $order->users()->attach($this->a['technician']->id);

        return $order;
    }

    private function admin(): static
    {
        auth()->logout();
        $this->flushSession();

        return $this->actingInPanel($this->a['admin']);
    }

    // 1–4 · Tomar → un solo deslizador → llegada una vez → remito.
    public function test_after_taking_the_job_there_is_a_single_arrival_slider_and_nothing_else(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $list = route('work-orders.index', ['company' => $this->slug(), 'status' => 'in_progress']);
        $show = route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]);

        // Tomar lleva al listado en curso: ahí está el deslizador (no un botón que lleve a otro deslizador).
        $this->actingAs($tech)->post(route('work-orders.start', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertRedirect($list);
        $html = $this->get($list)->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-slide-confirm="'));
        $this->assertStringContainsString('data-slide-confirm="arrive-'.$order->id.'"', $html);
        $this->assertStringNotContainsString('#llegada', $html);
        $this->assertStringNotContainsString('Hacer remito de esta orden', $html);
        // Ningún otro formulario apunta a la llegada.
        $this->assertSame(1, substr_count($html, '/work-orders/'.$order->id.'/llegada'));

        // En el detalle, también uno solo (el de «Tiempos de atención»).
        $detail = $this->get($show)->assertOk()->getContent();
        $this->assertSame(1, substr_count($detail, 'data-slide-confirm="'));
        $this->assertSame(1, substr_count($detail, '/work-orders/'.$order->id.'/llegada'));
        // Inicio: un recordatorio que lleva a la orden, sin acción propia.
        $home = $this->get(route('dashboard', ['company' => $this->slug()]))->assertSee('deslizá «Llegué al edificio» en la orden')->getContent();
        $this->assertStringNotContainsString('/work-orders/'.$order->id.'/llegada', $home);

        // Deslizar desde el listado: una sola llegada, hora del servidor.
        $this->post(route('work-orders.arrive', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertSessionHas('success', 'Llegada registrada: 24/10 09:00.');
        Carbon::setTestNow('2026-10-24 09:05:00');
        $this->post(route('work-orders.arrive', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertSessionHas('warning');
        $this->assertSame(1, SiteVisit::withoutGlobalScopes()->count());
        $this->assertSame('09:00', $order->fresh()->arrived_at->format('H:i'));

        // Después: ningún deslizador de llegada; el indicador y el remito.
        $after = $this->get($list)->assertSee('Llegada registrada · 09:00')->assertSee('Hacer remito de esta orden')->getContent();
        $this->assertStringNotContainsString('data-slide-confirm="arrive-', $after);
        $this->get($show)->assertSee('Llegada registrada · 09:00')->assertDontSee('data-slide-confirm="arrive"', false);
    }

    // 5 · La salida sigue siendo «Finalizar visita y retirarme».
    public function test_the_exit_is_still_the_finish_visit_slider(): void
    {
        $tech = $this->a['technician'];
        $order = $this->officeOrder();
        $this->actingAs($tech)->post(route('work-orders.start', ['company' => $this->slug(), 'workOrder' => $order->id]));
        $this->post(route('work-orders.arrive', ['company' => $this->slug(), 'workOrder' => $order->id]));
        $this->get(route('dashboard', ['company' => $this->slug()]))->assertSee('Finalizar visita y retirarme');
        $visit = SiteVisit::withoutGlobalScopes()->sole();
        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->post(route('site-visits.leave', ['company' => $this->slug(), 'siteVisit' => $visit->id]))->assertSessionHas('success');
        $this->assertSame('10:00', $visit->fresh()->left_at->format('H:i'));
    }

    // 6 · La oficina carga solo el aviso: no hay campos de llegada, salida ni duraciones.
    public function test_the_office_records_only_the_call_time_when_creating_an_order(): void
    {
        $this->admin();
        Livewire::test(CreateWorkOrder::class)
            ->assertFormFieldExists('call_received_at')
            ->assertFormFieldDoesNotExist('arrived_at')
            ->assertFormFieldDoesNotExist('left_at')
            ->assertSee('¿Cuándo recibiste el aviso?')->assertSee('Sirve para calcular el tiempo de respuesta')
            ->assertDontSee('Tiempos de atención') // en una orden nueva no hay nada que mostrar
            ->fillForm([
                'building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->a['technician']->id],
                'type' => 'claim', 'priority' => 'medium', 'status' => 'pending', 'call_received_at' => '2026-10-24 08:40',
            ])
            ->call('create')->assertHasNoFormErrors();
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $this->assertSame(['2026-10-24 08:40', 'office', $this->a['admin']->id], [$order->call_received_at->format('Y-m-d H:i'), $order->call_source, $order->call_recorded_by]);
        $this->assertNull($order->arrived_at);
        $this->assertNull($order->left_at);

        // Sin conocer la hora: se deja vacío y queda «Sin registrar».
        Livewire::test(CreateWorkOrder::class)->fillForm([
            'building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->a['technician']->id],
            'type' => 'claim', 'priority' => 'medium', 'status' => 'pending',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertNull(WorkOrder::withoutGlobalScopes()->latest('id')->first()->call_received_at);

        // Lo que ya valida el aviso sigue igual (no futuro).
        Livewire::test(CreateWorkOrder::class)->fillForm([
            'building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'users' => [$this->a['technician']->id],
            'type' => 'claim', 'priority' => 'medium', 'status' => 'pending', 'call_received_at' => '2026-10-25 08:40',
        ])->call('create')->assertHasFormErrors(['call_received_at']);
    }

    // 7–8 · Lo del técnico aparece solo en la orden de la oficina; pendiente ≠ sin registrar; sin duraciones inventadas.
    public function test_the_office_sees_the_technician_times_as_they_happen_without_invented_durations(): void
    {
        $order = $this->officeOrder(['call_received_at' => '2026-10-24 08:30:00', 'call_source' => 'office']);
        $edit = fn () => $this->admin()->get(WorkOrderResource::getUrl('edit', ['record' => $order]))->assertOk();

        // Antes de la llegada: una línea compacta, sin tarjetas vacías ni duraciones.
        $html = $edit()->getContent();
        $this->assertStringContainsString('data-attention="technician" data-state="pending"', $html);
        $this->assertStringContainsString('Pendiente de llegada', $html);
        $this->assertStringNotContainsString('data-attention-durations', $html);

        // El técnico llega: aparece la llegada; la salida queda pendiente; solo la respuesta se calcula.
        auth()->logout();
        $this->flushSession();
        $tech = $this->a['technician'];
        $this->actingAs($tech)->post(route('work-orders.start', ['company' => $this->slug(), 'workOrder' => $order->id]));
        Carbon::setTestNow('2026-10-24 09:10:00');
        $this->post(route('work-orders.arrive', ['company' => $this->slug(), 'workOrder' => $order->id]));
        $html = $edit()->getContent();
        $this->assertStringContainsString('24/10 09:10', $html);
        $this->assertStringContainsString('data-attention="left" data-state="pending"', $html);
        $this->assertStringContainsString('Pendiente de salida', $html);
        $this->assertMatchesRegularExpression('/data-duration="response">Respuesta: <strong class="">40 min</', $html);
        $this->assertMatchesRegularExpression('/data-duration="on_site">En el edificio: <strong class="is-pending">Pendiente</', $html);

        // Remito y salida: todo calculado.
        auth()->logout();
        $this->flushSession();
        $this->actingAs($tech)->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $order->building_id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'description' => 'Hecho.',
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0, 'signature_name' => 'T', 'signature' => $this->validSignature(),
            'visit_flow' => 1, 'submission_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-24 09:55:00');
        $this->post(route('site-visits.leave', ['company' => $this->slug(), 'siteVisit' => SiteVisit::withoutGlobalScopes()->sole()->id]));
        $html = $edit()->getContent();
        foreach (['24/10 09:55', 'Respuesta: <strong class="">40 min', 'En el edificio: <strong class="">45 min', 'Total: <strong class="">1 h 25 min'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // Orden cerrada sin aviso ni llegada: «Sin registrar», no «Pendiente», y sin duraciones.
        $closed = $this->officeOrder(['status' => 'completed', 'finished_at' => now()]);
        $html = $this->admin()->get(WorkOrderResource::getUrl('edit', ['record' => $closed]))->assertOk()->getContent();
        $this->assertStringContainsString('data-attention="technician" data-state="missing"', $html);
        $this->assertStringNotContainsString('Pendiente de llegada', $html);
        $this->assertStringNotContainsString('data-attention-durations', $html);
    }

    // 9 · Estados: mismos valores y reglas; ahora con color, icono y texto.
    public function test_statuses_keep_their_values_and_are_now_visually_distinct(): void
    {
        $this->assertSame(['pending', 'in_progress', 'completed', 'failed'], array_keys(WorkOrderLabels::STATUSES));
        $this->assertSame(['warning', 'info', 'success', 'danger'], array_values(WorkOrderLabels::STATUS_COLORS));
        $this->assertCount(4, array_unique(WorkOrderLabels::STATUS_CHIPS));
        $this->assertNotSame(WorkOrderLabels::statusChip('in_progress'), 't-chip-progress'); // ya no igual a la prioridad alta

        $order = $this->officeOrder(['priority' => 'high']);
        $this->admin();
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('En progreso')->assertSee('No realizado')->assertSee('Orden #'.$order->id)->assertDontSee('Editar pending')
            ->fillForm(['status' => 'in_progress', 'users' => [$this->a['technician']->id]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('in_progress', $order->fresh()->status);
        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['status' => 'cancelled', 'users' => [$this->a['technician']->id]])->call('save')->assertHasFormErrors(['status']);
        $this->assertSame('in_progress', $order->fresh()->status);

        // App del técnico: el estado se lee como texto con su color.
        auth()->logout();
        $this->flushSession();
        $this->actingAs($this->a['technician'])->get(route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]))
            ->assertSee('class="t-chip-info" data-status="in_progress">En progreso</span>', false);
    }

    // 10 · Aislamiento: la orden de otra empresa no se ve ni se le registra la llegada.
    public function test_company_isolation_is_unchanged(): void
    {
        $order = $this->officeOrder();
        $this->actingAs($this->a['technician'])->post(route('work-orders.start', ['company' => $this->slug(), 'workOrder' => $order->id]));
        auth()->logout();
        $this->flushSession();
        $foreign = $this->b['technician'];
        $this->actingAs($foreign)->get(route('work-orders.index', ['company' => $this->b['company']->slug, 'status' => 'in_progress']))
            ->assertOk()->assertDontSee('arrive-'.$order->id, false);
        $this->actingAs($foreign)->post(route('work-orders.arrive', ['company' => $this->b['company']->slug, 'workOrder' => $order->id]))->assertNotFound();
        $this->assertNull($order->fresh()->arrived_at);

        auth()->logout();
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $this->get(WorkOrderResource::getUrl('edit', ['record' => $order]))->assertNotFound();
    }
}
