<?php

namespace Tests\Feature\Security;

use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * El webhook de Mercado Pago es público. Su seguridad se apoya en que
 * nunca confía en el body: siempre re-consulta el estado real a la API.
 */
class MercadoPagoWebhookTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mercadopago.access_token' => 'TEST-TOKEN']);
    }

    public function test_forged_payload_cannot_change_status_against_mercado_pago(): void
    {
        $a = $this->makeTenant();

        $subscription = Subscription::create([
            'company_id' => $a['company']->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => 'PRE-123',
            'status' => 'authorized',
        ]);

        Http::fake([
            'api.mercadopago.com/preapproval/PRE-123' => Http::response([
                'id' => 'PRE-123',
                'status' => 'authorized',
            ]),
        ]);

        // El atacante dice "cancelado" en el body; MP dice "authorized".
        $this->postJson('/api/mercadopago/webhook', [
            'type' => 'preapproval',
            'data' => ['id' => 'PRE-123'],
            'status' => 'cancelled',
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame('authorized', $subscription->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/preapproval/PRE-123'));
    }

    public function test_unknown_subscriptions_and_types_are_ignored(): void
    {
        Http::fake([
            'api.mercadopago.com/*' => Http::response(['id' => 'PRE-999', 'status' => 'authorized']),
        ]);

        $this->postJson('/api/mercadopago/webhook', ['type' => 'preapproval', 'data' => ['id' => 'PRE-999']])
            ->assertOk()
            ->assertJson(['status' => 'subscription_not_found']);

        $this->postJson('/api/mercadopago/webhook', ['type' => 'otro', 'data' => ['id' => '1']])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->postJson('/api/mercadopago/webhook', [])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, Subscription::count());
    }
}
