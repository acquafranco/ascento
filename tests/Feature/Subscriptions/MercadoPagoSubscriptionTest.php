<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\MercadoPagoApiException;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMercadoPago;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Suscripción mensual con Mercado Pago: el estado y el acceso salen SOLO de
 * lo que informa la API de Mercado Pago, validado contra empresa e importe.
 */
class MercadoPagoSubscriptionTest extends TestCase
{
    use InteractsWithMercadoPago, InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMercadoPago();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    private function expireTrial(array $tenant): void
    {
        $tenant['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();
    }

    private function hasAccess(array $tenant): bool
    {
        return $tenant['company']->fresh()->hasActiveAccess();
    }

    /*
    |--------------------------------------------------------------------------
    | CREACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_admin_starts_a_monthly_subscription_of_149000_ars(): void
    {
        $this->mpApi['POST /preapproval'] = [
            'id' => 'PRE-A1',
            'status' => 'pending',
            'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE-A1',
        ];

        $this->actingInPanel($this->a['admin']);

        Livewire::test(SubscriptionPage::class)
            ->assertSee('$149.000')
            ->assertSee('Suscribirme con Mercado Pago')
            ->call('checkout')
            ->assertRedirect('https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE-A1');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/preapproval')
            && $request['status'] === 'pending'
            && $request['external_reference'] === 'ascento-company-'.$this->a['company']->id
            && $request['auto_recurring']['transaction_amount'] == 149000
            && $request['auto_recurring']['currency_id'] === 'ARS'
            && $request['auto_recurring']['frequency_type'] === 'months'
            && str_contains($request['back_url'], '/admin/subscription?mp=return')
            && ! isset($request['preapproval_plan_id']));

        $subscription = Subscription::where('company_id', $this->a['company']->id)->sole();
        $this->assertSame('PRE-A1', $subscription->provider_subscription_id);
        $this->assertSame(Subscription::PENDING, $subscription->status);
        $this->assertEquals(149000, $subscription->amount);
    }

    public function test_a_recent_pending_checkout_is_reused_instead_of_duplicated(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['checkout_url' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE-A1']);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->call('checkout')
            ->assertRedirect('https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE-A1');

        $this->assertSame(0, $this->mpRequests('POST', '/preapproval'));
    }

    public function test_cannot_subscribe_twice_while_active(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'current_period_end' => now()->addDays(20)]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->assertDontSee('Suscribirme con Mercado Pago')
            ->call('checkout')
            ->assertNoRedirect();

        $this->assertSame(0, $this->mpRequests('POST', '/preapproval'));
    }

    public function test_mercado_pago_errors_do_not_break_the_page(): void
    {
        // POST /preapproval sin respuesta configurada → 500.
        $this->actingInPanel($this->a['admin']);

        Livewire::test(SubscriptionPage::class)
            ->call('checkout')
            ->assertNoRedirect()
            ->assertNotified('No se pudo iniciar el pago');

        $this->assertSame(0, Subscription::count());
    }

    public function test_without_credentials_only_transfer_is_offered(): void
    {
        config(['services.mercadopago.access_token' => null]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->assertDontSee('Suscribirme con Mercado Pago')
            ->assertSee('¿Preferís pagar por transferencia?');
    }

    /*
    |--------------------------------------------------------------------------
    | WEBHOOK: AUTENTICIDAD
    |--------------------------------------------------------------------------
    */

    public function test_signed_webhook_syncs_the_real_state(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1');
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');

        $this->mpWebhook('subscription_preapproval', 'PRE-A1')
            ->assertOk()
            ->assertJson(['status' => 'preapproval_authorized']);

        $subscription = Subscription::sole();
        $this->assertSame(Subscription::AUTHORIZED, $subscription->status);
        $this->assertNotNull($subscription->authorized_at);

        $event = WebhookEvent::sole();
        $this->assertTrue($event->signature_valid);
        $this->assertSame('preapproval_authorized', $event->result);
    }

    public function test_tampered_or_unsigned_webhooks_are_rejected_without_calling_mercado_pago(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1');
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');

        $this->mpWebhook('subscription_preapproval', 'PRE-A1', secret: 'otra-clave')->assertUnauthorized();
        $this->mpWebhook('subscription_preapproval', 'PRE-A1', secret: null)->assertUnauthorized();

        // Firma válida para otro id: no sirve para este.
        $requestId = 'req-1';
        $ts = '1700000000000';
        $signature = hash_hmac('sha256', "id:otro;request-id:{$requestId};ts:{$ts};", self::MP_SECRET);
        $this->withHeaders(['x-request-id' => $requestId, 'x-signature' => "ts={$ts},v1={$signature}"])
            ->postJson('/api/mercadopago/webhook?data.id=PRE-A1&type=subscription_preapproval')
            ->assertUnauthorized();

        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(Subscription::PENDING, Subscription::sole()->status);
        $this->assertSame(3, WebhookEvent::where('result', 'invalid_signature')->count());
    }

    public function test_in_production_a_missing_secret_rejects_every_webhook(): void
    {
        config(['services.mercadopago.webhook_secret' => null]);
        $this->app['env'] = 'production';

        $this->mpWebhook('subscription_preapproval', 'PRE-A1', secret: null)->assertUnauthorized();
        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_the_body_is_never_trusted(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'current_period_end' => now()->addDays(10)]);
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');

        // El body dice "cancelled"; Mercado Pago dice "authorized".
        $this->mpWebhook('subscription_preapproval', 'PRE-A1', body: [
            'type' => 'subscription_preapproval',
            'data' => ['id' => 'PRE-A1'],
            'status' => 'cancelled',
            'action' => 'updated',
        ])->assertOk();

        $this->assertSame(Subscription::AUTHORIZED, Subscription::sole()->status);
    }

    public function test_unknown_resources_and_topics_are_ignored(): void
    {
        $this->mpPreapproval('PRE-ZZZ', $this->a['company'], 'authorized');

        $this->mpWebhook('subscription_preapproval', 'PRE-ZZZ')->assertOk()->assertJson(['status' => 'subscription_not_found']);
        $this->mpWebhook('payment', '123')->assertOk()->assertJson(['status' => 'ignored']);
        $this->mpWebhook('subscription_preapproval', 'bad id;drop')->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, Subscription::count());
    }

    /*
    |--------------------------------------------------------------------------
    | COBROS
    |--------------------------------------------------------------------------
    */

    public function test_approved_payment_grants_one_paid_month(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'authorized_at' => now()->subDays(3)]);
        $this->assertFalse($this->hasAccess($this->a));

        $this->mpCharge('AP1', 'PRE-A1', 'approved');
        $this->mpWebhook('subscription_authorized_payment', 'AP1')->assertOk()->assertJson(['status' => 'payment_approved']);

        $subscription = Subscription::sole();
        $this->assertSame(Subscription::AUTHORIZED, $subscription->status);
        $this->assertTrue($subscription->current_period_end->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
        $this->assertSame(SubscriptionPayment::APPROVED, $subscription->last_payment_status);
        $this->assertTrue($this->hasAccess($this->a));

        $payment = SubscriptionPayment::sole();
        $this->assertSame('AP1', $payment->provider_payment_id);
        $this->assertEquals(149000, $payment->amount);
    }

    public function test_repeated_webhooks_never_add_the_same_month_twice(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED]);
        $this->mpCharge('AP1', 'PRE-A1', 'approved');

        $this->mpWebhook('subscription_authorized_payment', 'AP1');
        $end = Subscription::sole()->current_period_end;

        $this->mpWebhook('subscription_authorized_payment', 'AP1');
        $this->mpWebhook('subscription_authorized_payment', 'AP1');

        $this->assertEquals($end, Subscription::sole()->current_period_end);
        $this->assertSame(1, SubscriptionPayment::count());
    }

    public function test_renewal_extends_from_the_end_of_the_paid_period(): void
    {
        $paidUntil = now()->addDays(3)->startOfSecond();
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'current_period_end' => $paidUntil]);

        $this->mpCharge('AP2', 'PRE-A1', 'approved');
        $this->mpWebhook('subscription_authorized_payment', 'AP2')->assertOk();

        $this->assertEquals($paidUntil->copy()->addMonthNoOverflow(), Subscription::sole()->current_period_end);
    }

    public function test_rejected_payment_marks_past_due_with_a_grace_period_then_blocks(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'current_period_end' => now()->subHour()]);

        $this->mpCharge('AP3', 'PRE-A1', 'rejected');
        $this->mpWebhook('subscription_authorized_payment', 'AP3')->assertOk()->assertJson(['status' => 'payment_rejected']);

        $subscription = Subscription::sole();
        $this->assertSame(Subscription::PAST_DUE, $subscription->status);
        $this->assertSame(SubscriptionPayment::REJECTED, $subscription->last_payment_status);
        $this->assertTrue($this->hasAccess($this->a)); // tolerancia

        $this->travel(Subscription::PAST_DUE_GRACE_DAYS + 1)->days();
        $this->assertFalse($this->hasAccess($this->a));

        // MP reintenta y aprueba: vuelve a estar activa.
        $this->mpCharge('AP3', 'PRE-A1', 'approved');
        $this->mpWebhook('subscription_authorized_payment', 'AP3')->assertOk();

        $this->assertSame(Subscription::AUTHORIZED, Subscription::sole()->status);
        $this->assertTrue($this->hasAccess($this->a));
    }

    public function test_preapproval_still_authorized_does_not_hide_a_rejected_payment(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::PAST_DUE, 'current_period_end' => now()->subDay()]);
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');

        $this->mpWebhook('subscription_preapproval', 'PRE-A1')->assertOk();

        $this->assertSame(Subscription::PAST_DUE, Subscription::sole()->status);
    }

    public function test_pending_payment_does_not_grant_access(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'authorized_at' => now()->subDays(5)]);

        $this->mpCharge('AP4', 'PRE-A1', 'in_process');
        $this->mpWebhook('subscription_authorized_payment', 'AP4')->assertOk()->assertJson(['status' => 'payment_pending']);

        $this->assertNull(Subscription::sole()->current_period_end);
        $this->assertFalse($this->hasAccess($this->a));
    }

    /*
    |--------------------------------------------------------------------------
    | ESTADOS / ACCESO
    |--------------------------------------------------------------------------
    */

    public function test_pending_checkout_keeps_trial_but_not_more(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1');
        $this->assertTrue($this->hasAccess($this->a)); // trial vigente

        $this->expireTrial($this->a);
        $this->assertFalse($this->hasAccess($this->a));
    }

    public function test_just_authorized_gets_access_while_the_first_charge_arrives(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1');
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');

        $this->mpWebhook('subscription_preapproval', 'PRE-A1')->assertOk();
        $this->assertTrue($this->hasAccess($this->a));

        $this->travel(Subscription::FIRST_PAYMENT_WAIT_HOURS + 1)->hours();
        $this->assertFalse($this->hasAccess($this->a));
    }

    public function test_company_access_follows_the_real_state(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'authorized_at' => now()->subDays(3)]);

        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/dashboard")->assertForbidden();
        $this->actingAs($this->a['admin'])->get('/admin/buildings')->assertRedirect('/admin/subscription');

        $this->mpCharge('AP1', 'PRE-A1', 'approved');
        $this->mpWebhook('subscription_authorized_payment', 'AP1')->assertOk();

        $this->actingAs($this->a['technician']->fresh())->get("/{$this->a['company']->slug}/dashboard")->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | CANCELACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_admin_cancels_and_keeps_access_until_the_paid_period_ends(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'current_period_end' => now()->addDays(10)]);
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)->callAction('cancel');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/preapproval/PRE-A1')
            && $request['status'] === 'cancelled');

        $subscription = Subscription::sole();
        $this->assertSame(Subscription::CANCELED, $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertTrue($this->hasAccess($this->a));

        $this->travel(11)->days();
        $this->assertFalse($this->hasAccess($this->a));
    }

    public function test_mercado_pago_cancelling_after_failed_charges_is_reflected(): void
    {
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::PAST_DUE, 'current_period_end' => now()->subDays(20)]);
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'cancelled');

        $this->mpWebhook('subscription_preapproval', 'PRE-A1')->assertOk();

        $this->assertSame(Subscription::CANCELED, Subscription::sole()->status);
        $this->assertFalse($this->hasAccess($this->a));
    }

    /*
    |--------------------------------------------------------------------------
    | MANIPULACIÓN / AISLAMIENTO ENTRE EMPRESAS
    |--------------------------------------------------------------------------
    */

    public function test_amount_tampering_never_grants_access(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED, 'authorized_at' => now()->subDays(3)]);

        // Preapproval con otro importe: no se aplica.
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized', ['auto_recurring' => ['transaction_amount' => 10]]);
        $this->mpWebhook('subscription_preapproval', 'PRE-A1')->assertJson(['status' => 'amount_mismatch']);

        // Pago aprobado por menos plata: queda "en revisión", sin mes pago.
        $this->mpCharge('AP1', 'PRE-A1', 'approved', 100);
        $this->mpWebhook('subscription_authorized_payment', 'AP1')->assertJson(['status' => 'payment_amount_mismatch']);

        // En otra moneda tampoco.
        $this->mpCharge('AP2', 'PRE-A1', 'approved', 149000, ['currency_id' => 'USD']);
        $this->mpWebhook('subscription_authorized_payment', 'AP2')->assertJson(['status' => 'payment_amount_mismatch']);

        $this->assertNull(Subscription::sole()->current_period_end);
        $this->assertFalse($this->hasAccess($this->a));
    }

    public function test_a_preapproval_from_another_company_cannot_activate_this_one(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1');

        // MP devuelve, para el id que guardó A, una referencia de la empresa B.
        $this->mpPreapproval('PRE-A1', $this->b['company'], 'authorized');

        $this->mpWebhook('subscription_preapproval', 'PRE-A1')->assertOk()->assertJson(['status' => 'reference_mismatch']);

        $this->assertSame(Subscription::PENDING, Subscription::sole()->status);
        $this->assertFalse($this->hasAccess($this->a));
    }

    public function test_company_a_payments_never_touch_company_b(): void
    {
        $this->expireTrial($this->b);
        $this->mpSubscription($this->a['company'], 'PRE-A1', ['status' => Subscription::AUTHORIZED]);
        $subB = $this->mpSubscription($this->b['company'], 'PRE-B1', ['status' => Subscription::AUTHORIZED, 'authorized_at' => now()->subDays(3)]);

        $this->mpCharge('AP1', 'PRE-A1', 'approved');
        $this->mpWebhook('subscription_authorized_payment', 'AP1')->assertOk();

        $this->assertNull($subB->fresh()->current_period_end);
        $this->assertFalse($this->hasAccess($this->b));
        $this->assertSame(0, SubscriptionPayment::where('company_id', $this->b['company']->id)->count());
    }

    public function test_admin_of_a_cannot_cancel_or_sync_company_b(): void
    {
        $subB = $this->mpSubscription($this->b['company'], 'PRE-B1', ['status' => Subscription::AUTHORIZED, 'current_period_end' => now()->addDays(10)]);
        $this->mpPreapproval('PRE-B1', $this->b['company'], 'authorized');

        $this->actingInPanel($this->a['admin']);

        // A no tiene suscripción: no ve "Cancelar" y la acción no existe para él.
        Livewire::test(SubscriptionPage::class)
            ->assertActionHidden('cancel')
            ->call('refreshStatus');

        // Vuelta del checkout con parámetros falsos apuntando a B.
        $this->get('/admin/subscription?mp=return&preapproval_id=PRE-B1&status=authorized')->assertOk();

        $this->assertSame(0, $this->mpRequests('PUT', '/preapproval'));
        $this->assertSame(Subscription::AUTHORIZED, $subB->fresh()->status);
        $this->assertSame(0, Subscription::where('company_id', $this->a['company']->id)->count());
    }

    public function test_returning_from_checkout_reads_the_state_from_mercado_pago_not_the_url(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1');
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'pending');

        // La URL dice "authorized"; MP dice "pending".
        $this->actingInPanel($this->a['admin'])
            ->get('/admin/subscription?mp=return&status=authorized&preapproval_id=PRE-A1')
            ->assertOk()
            ->assertSee('Pago sin terminar');

        $this->assertSame(Subscription::PENDING, Subscription::sole()->status);
        $this->assertFalse($this->hasAccess($this->a));
    }

    public function test_technicians_cannot_open_the_subscription_page(): void
    {
        $this->actingInPanel($this->a['technician'])
            ->get('/admin/subscription')
            ->assertRedirect(route('dashboard', ['company' => $this->a['company']->slug]));
    }

    public function test_super_admin_has_no_subscription_page(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingInPanel($super)->get('/admin/subscription')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | ERRORES DE CONFIGURACIÓN (explicados)
    |--------------------------------------------------------------------------
    */

    public function test_simulated_notifications_with_fake_ids_answer_200(): void
    {
        // "Simular notificación" del panel de MP manda ids de ejemplo.
        $this->mpWebhook('subscription_preapproval', '123456')->assertOk()->assertJson(['status' => 'not_found']);
        $this->mpWebhook('subscription_authorized_payment', '123456')->assertOk()->assertJson(['status' => 'not_found']);

        // Un 404 no se reintenta (no tiene sentido): una sola consulta por aviso.
        $this->assertSame(2, Http::recorded()->count());
    }

    public function test_the_admin_sees_why_mercado_pago_rejected_the_checkout(): void
    {
        $this->mpApi['POST /preapproval'] = ['__status' => 400, 'message' => 'Both payer and collector must be real or test users'];

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->call('checkout')
            ->assertNoRedirect()
            ->assertNotified(
                Notification::make()
                    ->title('No se pudo iniciar el pago')
                    ->body((new MercadoPagoApiException(400, 'Both payer and collector must be real or test users'))->hint())
                    ->danger()
                    ->persistent()
            );
    }

    public function test_common_mercado_pago_errors_have_actionable_explanations(): void
    {
        $hint = fn (int $status, string $detail) => (new MercadoPagoApiException($status, $detail))->hint();

        $this->assertStringContainsString('MERCADOPAGO_TEST_PAYER_EMAIL', $hint(400, 'Both payer and collector must be real or test users'));
        $this->assertStringContainsString('mismo de la cuenta', $hint(400, 'Payer and collector cannot be the same user'));
        $this->assertStringContainsString('config:clear', $hint(401, 'invalid access token'));
        $this->assertStringContainsString('APP_URL', $hint(400, 'Invalid back_url'));
        $this->assertStringContainsString('temporal', $hint(503, 'Service unavailable'));
    }

    public function test_diagnostic_command_explains_the_setup(): void
    {
        $this->mpApi['/users/me'] = ['id' => 1, 'nickname' => 'TESTUSER', 'site_id' => 'MLA', 'tags' => ['test_user'], 'email' => 'vendedor@test.com'];
        config(['app.url' => 'https://app.ascento.test']);

        $this->artisan('mercadopago:check')
            ->expectsOutputToContain('USUARIO DE PRUEBA')
            ->expectsOutputToContain('MERCADOPAGO_TEST_PAYER_EMAIL')
            ->expectsOutputToContain('https://app.ascento.test/api/mercadopago/webhook')
            ->assertFailed();

        config(['services.mercadopago.test_payer_email' => 'comprador@test.com']);
        $this->artisan('mercadopago:check')->expectsOutputToContain('Todo en orden')->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | RECONCILIACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_reconciliation_recovers_missed_webhooks(): void
    {
        $this->expireTrial($this->a);
        $this->mpSubscription($this->a['company'], 'PRE-A1');
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized');
        $this->mpCharge('AP1', 'PRE-A1', 'approved');
        $this->mpApi['/authorized_payments/search'] = ['results' => [$this->mpApi['/authorized_payments/AP1']]];

        $this->artisan('subscriptions:reconcile-mercadopago')->assertSuccessful();

        $subscription = Subscription::sole();
        $this->assertSame(Subscription::AUTHORIZED, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($this->hasAccess($this->a));
    }
}
