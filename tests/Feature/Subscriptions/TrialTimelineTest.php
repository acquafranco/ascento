<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MercadoPagoSubscriptionSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * Prueba gratis de 30 días, de punta a punta: registro (día 1), días 29, 30
 * y 31, contratación y que nunca se regalen otros 30 días.
 */
class TrialTimelineTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->setUpMercadoPago();
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));

        $this->post('/register', [
            'company_name' => 'Ascensores Prueba',
            'name' => 'Dueña',
            'email' => 'duena@prueba.test',
            'password' => 'clave-segura-123',
            'password_confirmation' => 'clave-segura-123',
        ])->assertSessionHasNoErrors();

        $this->admin = User::where('email', 'duena@prueba.test')->sole();
        $this->company = $this->admin->company;
        auth()->logout();
    }

    private function access(): bool
    {
        return $this->company->fresh()->hasActiveAccess();
    }

    public function test_day_1_starts_a_30_day_trial_on_the_profesional_plan(): void
    {
        $this->assertSame('2026-10-31 10:00', $this->company->trial_ends_at->format('Y-m-d H:i'));
        $this->assertTrue($this->company->onTrial());
        $this->assertTrue($this->access());
        $this->assertSame(SubscriptionPlan::TRIAL_PLAN, $this->company->plan()->slug);
        $this->assertSame(0, Subscription::count());
    }

    public function test_days_29_30_and_31(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 23:00'));
        $this->assertTrue($this->access());

        $this->travelTo(Carbon::parse('2026-10-31 09:59'));   // último minuto
        $this->assertTrue($this->access());

        $this->travelTo(Carbon::parse('2026-10-31 10:01'));   // venció
        $this->assertFalse($this->access());

        $this->travelTo(Carbon::parse('2026-11-01 10:00'));   // día 31
        $this->assertFalse($this->access());

        // El admin no ve un error: lo lleva a contratar. El técnico queda afuera.
        $technician = User::factory()->technician()->create(['company_id' => $this->company->id]);
        $this->actingAs($technician)->get("/{$this->company->slug}/dashboard")->assertForbidden()->assertViewIs('subscription-inactive');
        $this->actingAs($this->admin)->get("/{$this->company->slug}/dashboard")->assertRedirect('/admin/subscription');
    }

    public function test_cannot_pay_during_the_trial_but_can_after_and_never_gets_another_free_month(): void
    {
        $plan = SubscriptionPlan::findBySlug('profesional');
        $sync = app(MercadoPagoSubscriptionSync::class);

        // Durante la prueba: no se cobra nada.
        try {
            $sync->startCheckout($this->company, $this->admin, $plan, 'https://ascento.test/vuelta');
            $this->fail('Se pudo iniciar un pago durante la prueba gratis.');
        } catch (RuntimeException) {
            // esperado
        }

        // Vencida la prueba: contrata.
        $this->travelTo(Carbon::parse('2026-11-03 12:00'));
        $this->mpApi['POST /preapproval'] = ['id' => 'PRE-T1', 'status' => 'pending', 'init_point' => 'https://www.mercadopago.com.ar/checkout'];
        $sync->startCheckout($this->company->fresh(), $this->admin, $plan, 'https://ascento.test/vuelta');

        $subscription = Subscription::sole();
        $this->assertSame(Subscription::PENDING, $subscription->status);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertFalse($this->access()); // checkout sin pagar no da acceso con la prueba vencida

        // Mercado Pago autoriza y cobra: un mes desde el pago, sin días gratis.
        $this->mpPreapproval('PRE-T1', $this->company, 'authorized', ['auto_recurring' => ['transaction_amount' => 119000]]);
        $this->mpWebhook('subscription_preapproval', 'PRE-T1')->assertOk();
        $this->mpCharge('AP-T1', 'PRE-T1', 'approved', 119000);
        $this->mpWebhook('subscription_authorized_payment', 'AP-T1')->assertOk();

        $subscription->refresh();
        $this->assertTrue($this->access());
        $this->assertSame('2026-12-03', $subscription->current_period_end->toDateString());
        $this->assertSame('2026-10-31', $this->company->fresh()->trial_ends_at->toDateString()); // la prueba no se renueva
        $this->assertFalse($this->company->fresh()->onTrial());

        // Sin el cobro siguiente, al terminar el mes pago (y la tolerancia) se corta.
        $this->travelTo(Carbon::parse('2026-12-20 12:00'));
        $this->mpPreapproval('PRE-T1', $this->company, 'cancelled');
        $this->mpWebhook('subscription_preapproval', 'PRE-T1')->assertOk();
        $this->assertFalse($this->access());
    }
}
