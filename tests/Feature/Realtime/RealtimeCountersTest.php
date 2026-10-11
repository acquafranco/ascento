<?php

namespace Tests\Feature\Realtime;

use App\Events\CountersChanged;
use App\Filament\Widgets\AdminStats;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\RealtimeCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Contadores en tiempo real: a quién se avisa (admins de la empresa y
 * técnicos involucrados, nunca otra empresa), un solo aviso por request, sin
 * datos en el evento, y el widget del admin escuchando su propio canal.
 */
class RealtimeCountersTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const ENV = [
        'BROADCAST_CONNECTION' => 'reverb', 'REVERB_APP_ID' => '1001', 'REVERB_APP_KEY' => 'clave-publica-test',
        'REVERB_APP_SECRET' => 'secreto-test', 'REVERB_HOST' => '127.0.0.1', 'REVERB_PORT' => '1', 'REVERB_SCHEME' => 'http',
    ];

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        foreach (self::ENV as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        RealtimeCounters::flush(); // lo que dejó el armado del escenario
        auth()->logout();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (array_keys(self::ENV) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        putenv('BROADCAST_CONNECTION=null');
        $_ENV['BROADCAST_CONNECTION'] = $_SERVER['BROADCAST_CONNECTION'] = 'null';
    }

    private function order(string $status = 'pending'): WorkOrder
    {
        $order = WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'status' => $status]);
        $order->users()->attach($this->a['technician']->id);
        RealtimeCounters::flush();

        return $order;
    }

    public function test_taking_a_work_order_notifies_company_admins_and_the_technician_once(): void
    {
        $order = $this->order();
        Event::fake([CountersChanged::class]);

        $this->actingAs($this->a['technician'])->post(route('work-orders.start', ['company' => $this->a['company']->slug, 'workOrder' => $order]))->assertRedirect();

        Event::assertDispatchedTimes(CountersChanged::class, 1);
        Event::assertDispatched(CountersChanged::class, function (CountersChanged $e) {
            sort($e->userIds);
            $expected = [$this->a['admin']->id, $this->a['technician']->id];
            sort($expected);

            return $e->userIds === $expected
                && ! in_array($this->b['admin']->id, $e->userIds, true)
                && $e->broadcastWith() === []
                && $e->broadcastAs() === 'dashboard.counters'
                && collect($e->broadcastOn())->map->name->sort()->values()->all() === collect($expected)->map(fn ($id) => 'private-App.Models.User.'.$id)->sort()->values()->all();
        });
    }

    public function test_only_changes_that_affect_counters_are_announced_and_grouped(): void
    {
        $order = $this->order();
        Event::fake([CountersChanged::class]);

        $order->update(['notes' => 'Otro detalle']); // no cambia ningún número
        RealtimeCounters::flush();
        Event::assertNotDispatched(CountersChanged::class);

        // Varios cambios juntos: un solo aviso.
        $order->update(['status' => 'in_progress']);
        $order->update(['status' => 'completed']);
        Quote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id]);
        RealtimeCounters::flush();
        Event::assertDispatchedTimes(CountersChanged::class, 1);
        $this->assertNotNull($order->fresh()->finished_at); // cerrada sin hora: se registra
    }

    public function test_a_quote_change_only_reaches_that_companys_admins(): void
    {
        Event::fake([CountersChanged::class]);
        $quote = Quote::factory()->create(['company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id]);
        RealtimeCounters::flush();

        $adminsB = User::where('company_id', $this->b['company']->id)->where('role', 'admin')->pluck('id')->sort()->values()->all();
        Event::assertDispatched(CountersChanged::class, function (CountersChanged $e) use ($adminsB) {
            $ids = collect($e->userIds)->sort()->values()->all();

            return $ids === $adminsB && in_array($this->b['admin']->id, $ids, true)
                && ! in_array($this->a['admin']->id, $ids, true) && ! in_array($this->a['technician']->id, $ids, true);
        });
        $this->assertNotNull($quote);
    }

    public function test_nobody_can_listen_to_someone_elses_counters(): void
    {
        $this->actingAs($this->a['technician'])
            ->post('/broadcasting/auth', ['channel_name' => 'private-App.Models.User.'.$this->b['admin']->id, 'socket_id' => '1234.5678'])
            ->assertForbidden();
        $this->actingAs($this->a['technician'])
            ->post('/broadcasting/auth', ['channel_name' => 'private-App.Models.User.'.$this->a['technician']->id, 'socket_id' => '1234.5678'])
            ->assertOk();
    }

    public function test_admin_widget_listens_to_its_own_channel_and_does_not_poll_every_few_seconds(): void
    {
        $this->actingInPanel($this->a['admin']);

        $this->order('pending');
        $this->order('in_progress');
        $old = $this->order('completed');
        $old->forceFill(['finished_at' => now()->subDays(3)])->saveQuietly();
        $old->forceFill(['updated_at' => now()])->saveQuietly(); // editada hoy: no es "completada hoy"

        $widget = Livewire::test(AdminStats::class);
        $listeners = (fn () => $this->getListeners())->call($widget->instance());
        $this->assertSame(['echo-private:App.Models.User.'.$this->a['admin']->id.',.dashboard.counters' => '$refresh'], $listeners);
        $this->assertSame('120s', (fn () => $this->getPollingInterval())->call($widget->instance())); // respaldo, no cada 5 s

        $stats = collect((fn () => $this->getStats())->call($widget->instance()))->mapWithKeys(fn ($s) => [$s->getLabel() => $s->getValue()]);
        $this->assertSame([1, 1, 0], [$stats['Pendientes'], $stats['En proceso'], $stats['Completados']]);
    }

    public function test_counters_are_not_announced_without_realtime(): void
    {
        config(['broadcasting.default' => 'null']);
        Event::fake([CountersChanged::class]);

        $order = $this->order();
        $order->update(['status' => 'in_progress']);
        RealtimeCounters::flush();

        Event::assertNotDispatched(CountersChanged::class);
        $this->assertInstanceOf(User::class, $this->a['admin']);
    }
}
