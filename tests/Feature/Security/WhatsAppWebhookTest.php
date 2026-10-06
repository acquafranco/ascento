<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => self::SECRET,
            'services.whatsapp.verify_token' => 'verify-me',
        ]);

        Http::fake();
    }

    private function payload(string $phone, string $buttonId): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => $phone,
                            'type' => 'interactive',
                            'interactive' => ['button_reply' => ['id' => $buttonId]],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function postSigned(array $payload, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], $headers, $body);
    }

    private function assignedPendingOrder(array $tenant, string $phone): WorkOrder
    {
        $tenant['technician']->update(['phone' => $phone]);

        $workOrder = WorkOrder::factory()->create(['building_id' => $tenant['building']->id]);
        $workOrder->users()->attach($tenant['technician']->id);

        return $workOrder;
    }

    public function test_unsigned_request_is_rejected_and_changes_nothing(): void
    {
        $a = $this->makeTenant();
        $workOrder = $this->assignedPendingOrder($a, '5491111111111');

        $this->postSigned($this->payload('5491111111111', 'take_work_order_'.$workOrder->id), null)
            ->assertForbidden();

        $this->assertSame('pending', $workOrder->fresh()->status);
    }

    public function test_request_signed_with_wrong_secret_is_rejected(): void
    {
        $a = $this->makeTenant();
        $workOrder = $this->assignedPendingOrder($a, '5491111111111');

        $this->postSigned($this->payload('5491111111111', 'take_work_order_'.$workOrder->id), 'otro-secret')
            ->assertForbidden();

        $this->assertSame('pending', $workOrder->fresh()->status);
    }

    public function test_requests_are_rejected_when_secret_is_not_configured(): void
    {
        config(['services.whatsapp.app_secret' => null]);

        $a = $this->makeTenant();
        $workOrder = $this->assignedPendingOrder($a, '5491111111111');

        $this->postSigned($this->payload('5491111111111', 'take_work_order_'.$workOrder->id), '')
            ->assertForbidden();

        $this->assertSame('pending', $workOrder->fresh()->status);
    }

    public function test_assigned_technician_can_take_order_with_valid_signature(): void
    {
        $a = $this->makeTenant();
        $workOrder = $this->assignedPendingOrder($a, '5491111111111');

        $this->postSigned($this->payload('5491111111111', 'take_work_order_'.$workOrder->id))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $workOrder->refresh();
        $this->assertSame('in_progress', $workOrder->status);
        $this->assertTrue($workOrder->participants()->whereKey($a['technician']->id)->exists());
    }

    public function test_technician_of_another_company_with_same_phone_cannot_take_order(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $workOrderB = WorkOrder::factory()->create(['building_id' => $b['building']->id]);
        $workOrderB->users()->attach($b['technician']->id);

        // El técnico de A tiene el teléfono que manda el webhook; B tiene otro.
        $a['technician']->update(['phone' => '5491111111111']);
        $b['technician']->update(['phone' => '5492222222222']);

        $this->postSigned($this->payload('5491111111111', 'take_work_order_'.$workOrderB->id))
            ->assertOk()
            ->assertJson(['status' => 'missing_data']);

        $this->assertSame('pending', $workOrderB->fresh()->status);
    }

    public function test_unassigned_technician_cannot_take_order(): void
    {
        $a = $this->makeTenant();
        $workOrder = $this->assignedPendingOrder($a, '5491111111111');

        $intruder = User::factory()->technician()->create([
            'company_id' => $a['company']->id,
            'phone' => '5493333333333',
        ]);

        $this->postSigned($this->payload($intruder->phone, 'take_work_order_'.$workOrder->id))
            ->assertOk()
            ->assertJson(['status' => 'missing_data']);

        $this->assertSame('pending', $workOrder->fresh()->status);
    }

    public function test_malformed_button_ids_are_ignored(): void
    {
        $a = $this->makeTenant();
        $this->assignedPendingOrder($a, '5491111111111');

        $this->postSigned($this->payload('5491111111111', 'take_work_order_1 OR 1=1'))
            ->assertOk()
            ->assertJson(['status' => 'missing_data']);
    }

    public function test_verify_endpoint_requires_the_configured_token(): void
    {
        $this->get('/api/whatsapp/webhook?hub_verify_token=wrong&hub_challenge=abc')->assertForbidden();
        $this->get('/api/whatsapp/webhook?hub_verify_token=verify-me&hub_challenge=abc')
            ->assertOk()
            ->assertSee('abc');

        config(['services.whatsapp.verify_token' => null]);
        $this->get('/api/whatsapp/webhook?hub_challenge=abc')->assertForbidden();
    }

    public function test_oauth_callback_rejects_tampered_or_foreign_state(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $this->get('/whatsapp/callback?state=x')->assertRedirect('/login');

        $this->actingAs($a['admin'])
            ->get('/whatsapp/callback?state=no-encriptado&code=1')
            ->assertStatus(400);

        // State legítimo de B usado por el admin de A.
        $stateB = encrypt(['company_id' => $b['company']->id, 'user_id' => $b['admin']->id]);

        $this->actingAs($a['admin'])
            ->get('/whatsapp/callback?code=1&state='.urlencode($stateB))
            ->assertForbidden();

        // Técnico con un state "propio" tampoco puede conectar.
        $stateTech = encrypt(['company_id' => $a['company']->id, 'user_id' => $a['technician']->id]);

        $this->actingAs($a['technician'])
            ->get('/whatsapp/callback?code=1&state='.urlencode($stateTech))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertFalse((bool) $b['company']->fresh()->whatsapp_connected);
    }

    public function test_only_admins_can_start_whatsapp_connection(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $this->actingAs($a['technician'])->get("/{$slug}/whatsapp/connect")->assertNotFound();

        $response = $this->actingAs($a['admin'])->get("/{$slug}/whatsapp/connect");
        $response->assertRedirect();
        $this->assertStringStartsWith('https://www.facebook.com/', $response->headers->get('Location'));
    }
}
