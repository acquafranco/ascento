<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Notifications\App\SharedWithClientNotification;
use App\Services\Notifications\ClientShareNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWebPush;
use Tests\TestCase;

/**
 * Push del portal del cliente: alta segura de la suscripción, avisos de lo
 * compartido y limpieza de suscripciones vencidas.
 */
class PortalPushTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWebPush, RefreshDatabase;

    private array $a;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->configureVapid();
        $this->fakePushService();
        $this->a = $this->makeTenant();
        $clientModel = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->update(['client_id' => $clientModel->id]);
        auth()->logout();
        $this->client = User::factory()->create();
        $this->client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $clientModel->id])->save();
        $this->client->portalBuildings()->sync([$this->a['building']->id]);
    }

    private function payload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'], 'contentEncoding' => 'aes128gcm'];
    }

    public function test_a_client_registers_their_device_from_the_portal(): void
    {
        $this->actingAs($this->client)->postJson(route('portal.push.store'), $this->payload())->assertOk()->assertJson(['status' => 'subscribed', 'devices' => 1]);
        $this->assertSame(1, $this->client->pushSubscriptions()->count());

        // Endpoints de servicios no conocidos: rechazados.
        $this->postJson(route('portal.push.store'), $this->payload('https://evil.example.com/push/1'))->assertUnprocessable();

        // Baja: solo la propia.
        $this->deleteJson(route('portal.push.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])->assertOk();
        $this->assertSame(0, $this->client->pushSubscriptions()->count());
    }

    public function test_the_page_shows_the_push_card_and_never_asks_on_load(): void
    {
        $this->actingAs($this->client)->get(route('portal.home'))->assertOk()
            ->assertSee('name="ascento-push"', false)->assertSee('data-push-enable', false)
            ->assertDontSee('Notification.requestPermission', false);
    }

    public function test_shared_documents_send_a_push_to_the_authorized_client_only(): void
    {
        $device = $this->subscribeDevice($this->client);
        $other = User::factory()->create();
        $other->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->client->client_id])->save();
        $otherDevice = $this->subscribeDevice($other); // sin edificios autorizados

        $note = DeliveryNote::factory()->create(['building_id' => $this->a['building']->id]);
        $note->shareWithClient(true);
        app(ClientShareNotifier::class)->shared([$note]);

        $this->assertPushDeliveredTo($device);
        $this->assertNoPushDeliveredTo($otherDevice);
        $this->assertSame(SharedWithClientNotification::class, $this->client->notifications()->sole()->type);
    }

    public function test_expired_subscriptions_are_removed(): void
    {
        $this->subscribeDevice($this->client);
        $this->pushServiceResponds(410); // el servicio de push dice "ya no existe"

        $note = DeliveryNote::factory()->create(['building_id' => $this->a['building']->id]);
        $note->shareWithClient(true);
        app(ClientShareNotifier::class)->shared([$note]);

        $this->assertSame(0, $this->client->fresh()->pushSubscriptions()->count());
        $this->assertSame(1, $this->client->notifications()->count()); // el aviso interno queda
    }
}
