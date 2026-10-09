<?php

namespace Tests\Feature\Realtime;

use App\Events\UserNotificationsChanged;
use App\Jobs\SendWorkOrderAssignedNotification;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\AssignmentChangedNotification;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Avisos en tiempo real (Reverb): quién puede escuchar qué canal, qué se emite
 * y qué pasa si el servidor de tiempo real no está.
 */
class RealtimeNotificationsTest extends TestCase
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
        // Antes de arrancar la app: Reverb "configurado" (puerto 1 = servidor caído).
        foreach (self::ENV as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
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

    private function authorize(?User $as, User $channelOwner)
    {
        $as ? $this->actingAs($as) : auth()->logout();

        return $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$channelOwner->id]);
    }

    private function portalUser(array $tenant): User
    {
        auth()->logout();
        $client = Client::factory()->create(['company_id' => $tenant['company']->id]);
        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $tenant['company']->id, 'client_id' => $client->id])->save();
        $user->portalBuildings()->sync([]);

        return $user;
    }

    public function test_each_user_can_only_listen_to_their_own_channel(): void
    {
        $clientA = $this->portalUser($this->a);
        $otherTech = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        foreach ([$this->a['admin'], $this->a['technician'], $clientA] as $user) {
            $this->authorize($user, $user)->assertOk()->assertJsonStructure(['auth']);
        }

        // Otro técnico de la misma empresa, admin de la misma empresa, otra empresa, cliente → técnico.
        $this->authorize($otherTech, $this->a['technician'])->assertForbidden();
        $this->authorize($this->a['admin'], $this->a['technician'])->assertForbidden();
        $this->authorize($this->b['admin'], $this->a['admin'])->assertForbidden();
        $this->authorize($clientA, $this->a['technician'])->assertForbidden();
        $this->authorize(null, $this->a['technician'])->assertForbidden();
    }

    public function test_a_deactivated_user_cannot_subscribe(): void
    {
        $tech = $this->a['technician'];
        $tech->delete();

        $this->authorize($tech, $tech)->assertForbidden();
    }

    public function test_new_notifications_are_pushed_only_to_their_recipient_with_minimal_data(): void
    {
        Event::fake([UserNotificationsChanged::class]);

        app(Notifier::class)->sendTo($this->a['technician'], new AssignmentChangedNotification('assigned', 'el mantenimiento de Torre Norte', '/x'), $this->a['company']->id);

        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
        Event::assertDispatched(UserNotificationsChanged::class, function (UserNotificationsChanged $e) {
            $payload = $e->broadcastWith();

            return $e->broadcastOn()->name === 'private-App.Models.User.'.$this->a['technician']->id
                && $e->broadcastAs() === 'database-notifications.sent'
                && $payload['unread'] === 1
                && $payload['notification']['title'] === 'Nuevo edificio asignado'
                && array_keys($payload['notification']) === ['id', 'title', 'body'];
        });

        // Un aviso que no corresponde (otra empresa) no se guarda ni se emite.
        app(Notifier::class)->sendTo($this->b['technician'], new AssignmentChangedNotification('assigned', 'x', '/x'), $this->a['company']->id);
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
    }

    public function test_existing_database_channel_notifications_are_also_pushed(): void
    {
        Event::fake([UserNotificationsChanged::class]);
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'pending']);
        $order->users()->attach($this->a['technician']->id);

        SendWorkOrderAssignedNotification::dispatchSync($order->id, $this->a['technician']->id);

        Event::assertDispatched(UserNotificationsChanged::class, fn ($e) => $e->user->is($this->a['technician']) && $e->latest !== null);
    }

    public function test_marking_as_read_syncs_other_tabs(): void
    {
        app(Notifier::class)->sendTo($this->a['technician'], new AssignmentChangedNotification('assigned', 'x', '/x'), $this->a['company']->id);
        Event::fake([UserNotificationsChanged::class]);

        $this->actingAs($this->a['technician'])->post(route('notifications.read-all'));

        Event::assertDispatched(UserNotificationsChanged::class, fn ($e) => $e->broadcastWith()['unread'] === 0);
    }

    public function test_if_the_realtime_server_is_down_the_notification_is_still_saved(): void
    {
        Log::spy();

        $row = app(Notifier::class)->sendTo($this->a['technician'], new AssignmentChangedNotification('assigned', 'x', '/x'), $this->a['company']->id);

        $this->assertNotNull($row);
        $this->assertSame(1, DatabaseNotification::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => str_contains($m, 'tiempo real') && ! str_contains(json_encode($c), 'secreto'));
    }

    public function test_every_profile_loads_the_realtime_client_without_secrets(): void
    {
        $clientA = $this->portalUser($this->a);
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');
        $this->assertNotSame('', $secret);

        $tech = $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/dashboard")->assertOk();
        $tech->assertSee('js/filament/filament/echo.js', false)->assertSee('App.Models.User.'.$this->a['technician']->id, false)
            ->assertSee($key, false)->assertDontSee($secret, false);

        $this->actingAs($clientA)->get(route('portal.home'))->assertOk()
            ->assertSee('App.Models.User.'.$clientA->id, false)->assertDontSee($secret, false);

        $this->actingInPanel($this->a['admin'])->get('/admin/atencion')->assertOk()
            ->assertSee('EchoFactory', false)->assertDontSee($secret, false);
    }
}
