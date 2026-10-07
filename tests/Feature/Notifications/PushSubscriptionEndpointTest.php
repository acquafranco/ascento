<?php

namespace Tests\Feature\Notifications;

use App\Http\Controllers\PushSubscriptionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\PushSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWebPush;
use Tests\TestCase;

/**
 * Endpoint donde el técnico registra su dispositivo: protegido, a nombre del
 * usuario autenticado, y sin aceptar endpoints arbitrarios (SSRF).
 */
class PushSubscriptionEndpointTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWebPush, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    private function url(array $tenant, string $path = ''): string
    {
        return "/{$tenant['company']->slug}/push-subscriptions{$path}";
    }

    private function payload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BPublicKeyValue', 'auth' => 'AuthSecret'],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    public function test_technician_registers_a_device_under_their_own_account(): void
    {
        $this->actingAs($this->a['technician'])
            ->postJson($this->url($this->a), [
                ...$this->payload(),
                // Intentos de elegir el dueño desde el request: se ignoran.
                'subscribable_id' => $this->b['technician']->id,
                'user_id' => $this->b['technician']->id,
            ])
            ->assertOk()
            ->assertJson(['status' => 'subscribed', 'devices' => 1]);

        $subscription = PushSubscription::firstOrFail();
        $this->assertSame($this->a['technician']->id, (int) $subscription->subscribable_id);
        $this->assertSame(0, $this->b['technician']->pushSubscriptions()->count());
    }

    public function test_multiple_devices_per_technician(): void
    {
        $this->actingAs($this->a['technician']);
        $this->postJson($this->url($this->a), $this->payload('https://fcm.googleapis.com/fcm/send/phone'))->assertOk();
        $this->postJson($this->url($this->a), $this->payload('https://web.push.apple.com/QIphone'))->assertOk()->assertJson(['devices' => 2]);

        // Re-enviar el mismo dispositivo no duplica.
        $this->postJson($this->url($this->a), $this->payload('https://fcm.googleapis.com/fcm/send/phone'))->assertJson(['devices' => 2]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->post($this->url($this->a), $this->payload())->assertRedirect('/login');
        $this->postJson($this->url($this->a), $this->payload())->assertUnauthorized();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_admins_and_super_admins_cannot_register_devices(): void
    {
        $this->actingAs($this->a['admin'])->postJson($this->url($this->a), $this->payload())->assertForbidden();

        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->postJson($this->url($this->a), $this->payload())->assertForbidden();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_cannot_register_through_another_company_url(): void
    {
        $this->actingAs($this->a['technician'])
            ->postJson($this->url($this->b), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_company_without_access_cannot_register(): void
    {
        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $this->actingAs($this->a['technician'])
            ->postJson($this->url($this->a), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, PushSubscription::count());
    }

    #[DataProvider('forbiddenEndpoints')]
    public function test_only_real_push_services_are_accepted(string $endpoint): void
    {
        $this->actingAs($this->a['technician'])
            ->postJson($this->url($this->a), $this->payload($endpoint))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertSame(0, PushSubscription::count());
    }

    public static function forbiddenEndpoints(): array
    {
        return [
            'metadata interna' => ['https://169.254.169.254/latest/meta-data'],
            'localhost' => ['https://localhost/admin'],
            'dominio cualquiera' => ['https://evil.example.com/push'],
            'sin https' => ['http://fcm.googleapis.com/fcm/send/x'],
            'truco de usuario@host' => ['https://fcm.googleapis.com@evil.example.com/x'],
            'subdominio falso' => ['https://fcm.googleapis.com.evil.example.com/x'],
            'no es URL' => ['javascript:alert(1)'],
        ];
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        $this->actingAs($this->a['technician']);

        $this->postJson($this->url($this->a), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->postJson($this->url($this->a), [...$this->payload(), 'contentEncoding' => 'gzip'])
            ->assertJsonValidationErrors('contentEncoding');
    }

    public function test_a_shared_phone_moves_to_the_last_technician_who_activated_it(): void
    {
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->actingAs($this->a['technician'])->postJson($this->url($this->a), $this->payload())->assertOk();
        $this->actingAs($other)->postJson($this->url($this->a), $this->payload())->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($other->id, (int) PushSubscription::first()->subscribable_id);
    }

    public function test_a_technician_can_only_remove_their_own_devices(): void
    {
        $theirs = $this->subscribeDevice(User::factory()->technician()->create(['company_id' => $this->a['company']->id]));
        $mine = $this->subscribeDevice($this->a['technician']);

        $this->actingAs($this->a['technician']);

        $this->deleteJson($this->url($this->a), ['endpoint' => $theirs->endpoint])->assertOk();
        $this->assertModelExists($theirs);

        $this->deleteJson($this->url($this->a), ['endpoint' => $mine->endpoint])->assertOk();
        $this->assertModelMissing($mine);
    }

    public function test_logout_unsubscribes_this_device(): void
    {
        $other = $this->subscribeDevice($this->a['technician']);

        $this->actingAs($this->a['technician'])->postJson($this->url($this->a), $this->payload())->assertOk();
        $this->assertSame('https://fcm.googleapis.com/fcm/send/abc123', session(PushSubscriptionController::SESSION_KEY));

        $this->post('/logout')->assertRedirect('/');

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123']);
        // Los otros dispositivos del técnico siguen activos.
        $this->assertModelExists($other);
    }

    public function test_send_test_push_to_own_devices_only(): void
    {
        $this->configureVapid();
        $this->fakePushService();

        $mine = $this->subscribeDevice($this->a['technician']);
        $theirs = $this->subscribeDevice($this->b['technician']);

        $this->actingAs($this->a['technician'])
            ->postJson($this->url($this->a, '/test'))
            ->assertOk()
            ->assertJson(['devices' => 1]);

        $this->assertPushDeliveredTo($mine);
        $this->assertNoPushDeliveredTo($theirs);
    }

    public function test_test_push_is_rate_limited(): void
    {
        $this->actingAs($this->a['technician']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url($this->a, '/test'))->assertOk();
        }

        $this->postJson($this->url($this->a, '/test'))->assertTooManyRequests();
    }
}
