<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Models\DeliveryNote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ManualSubscriptionActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Política de acceso por suscripción (Company::hasActiveAccess()):
 * empresa activa → todo funciona; sin acceso → técnicos bloqueados, admin
 * solo ve la pantalla de pago; SuperAdmin siempre entra.
 */
class SubscriptionAccessPolicyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * [trial_ends_at relativo en días, datos de suscripción|null, ¿tiene acceso?]
     */
    public static function states(): array
    {
        return [
            'trial vigente' => [10, null, true],
            'trial vencido' => [-1, null, false],
            'activa (Mercado Pago)' => [-1, ['status' => 'authorized', 'current_period_end' => 30], true],
            'activa (manual) con período vigente' => [-1, ['status' => 'active', 'provider' => 'manual', 'current_period_end' => 30], true],
            'manual vencida aunque el cron no corrió' => [-1, ['status' => 'active', 'provider' => 'manual', 'current_period_end' => -1], false],
            'pausada (aunque quede trial)' => [10, ['status' => 'paused', 'current_period_end' => 30], false],
            'pendiente de pago' => [-1, ['status' => 'pending'], false],
            'pago rechazado, dentro de la tolerancia' => [-1, ['status' => 'past_due', 'current_period_end' => -1], true],
            'pago rechazado, tolerancia vencida' => [-1, ['status' => 'past_due', 'current_period_end' => -6], false],
            'checkout sin terminar con trial vigente' => [10, ['status' => 'pending'], true],
            'autorizada sin período pago (vieja)' => [-1, ['status' => 'authorized'], false],
            'cancelada con período pago vigente' => [-1, ['status' => 'canceled', 'current_period_end' => 5], true],
            'cancelada sin período' => [-1, ['status' => 'canceled', 'current_period_end' => -1], false],
        ];
    }

    private function tenantInState(int $trialDays, ?array $subscription): array
    {
        $tenant = $this->makeTenant();
        $tenant['company']->update(['trial_ends_at' => now()->addDays($trialDays)]);

        if ($subscription) {
            if (isset($subscription['current_period_end'])) {
                $subscription['current_period_end'] = now()->addDays($subscription['current_period_end']);
            }

            Subscription::create(array_merge([
                'company_id' => $tenant['company']->id,
                'provider' => 'mercadopago',
            ], $subscription));
        }

        return $tenant;
    }

    #[DataProvider('states')]
    public function test_access_by_state_and_role(int $trialDays, ?array $subscription, bool $hasAccess): void
    {
        $a = $this->tenantInState($trialDays, $subscription);
        $slug = $a['company']->slug;

        $this->assertSame($hasAccess, $a['company']->fresh()->hasActiveAccess());

        // Técnico: app web operativa.
        foreach (["/{$slug}/dashboard", "/{$slug}/work-orders", "/{$slug}/delivery-notes", "/{$slug}/reports/create"] as $url) {
            $response = $this->actingAs($a['technician']->fresh())->get($url);

            $hasAccess
                ? $response->assertOk()
                : $response->assertForbidden()->assertSee('está suspendido');
        }

        // Técnico: tampoco puede escribir.
        $post = $this->actingAs($a['technician']->fresh())->post("/{$slug}/delivery-notes/store", []);
        $hasAccess ? $post->assertSessionHasErrors() : $post->assertForbidden();

        // Admin: panel operativo y rutas web de admin.
        $panel = $this->actingAs($a['admin']->fresh())->get('/admin/buildings');
        $web = $this->actingAs($a['admin']->fresh())->get("/{$slug}/clients");

        if ($hasAccess) {
            $panel->assertOk();
            $web->assertOk();
        } else {
            $panel->assertRedirect('/admin/subscription');
            $web->assertRedirect('/admin/subscription');
        }

        // Admin: la pantalla de suscripción SIEMPRE abre.
        $this->actingAs($a['admin']->fresh())->get('/admin/subscription')->assertOk();
    }

    public function test_logout_always_works_for_blocked_users(): void
    {
        $a = $this->tenantInState(-1, null);

        $this->actingAs($a['technician'])->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_super_admin_always_has_access(): void
    {
        $a = $this->tenantInState(-1, ['status' => 'paused']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get('/admin/companies')->assertOk();
        $this->actingAs($superAdmin)->get('/admin/buildings')->assertOk();
    }

    public function test_company_deactivated_by_super_admin_loses_access_even_if_paid(): void
    {
        $a = $this->tenantInState(-1, ['status' => 'authorized', 'current_period_end' => 30]);
        $a['company']->update(['is_active' => false]);

        $this->actingAs($a['technician'])->get("/{$a['company']->slug}/dashboard")->assertForbidden();
        $this->actingAs($a['admin']->fresh())->get('/admin/buildings')->assertRedirect('/admin/subscription');
    }

    public function test_blocked_admin_can_still_start_checkout(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-TOKEN']);

        SubscriptionPlan::create([
            'name' => 'Ascento',
            'slug' => 'professional',
            'price' => 1000,
            'currency' => 'ARS',
            'mercadopago_plan_id' => 'PLAN-1',
            'is_active' => true,
        ]);

        Http::fake([
            'api.mercadopago.com/preapproval' => Http::response([
                'id' => 'PRE-NEW',
                'status' => 'pending',
                'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE-NEW',
            ]),
        ]);

        $a = $this->tenantInState(-1, null);

        $this->actingInPanel($a['admin']);

        Livewire::test(SubscriptionPage::class)
            ->call('checkout')
            ->assertRedirect('https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE-NEW');

        $this->assertSame('PRE-NEW', Subscription::sole()->provider_subscription_id);
    }

    public function test_reactivating_restores_access_immediately(): void
    {
        $a = $this->tenantInState(-1, ['status' => 'paused', 'current_period_end' => 10]);

        $this->actingAs($a['technician'])->get("/{$a['company']->slug}/dashboard")->assertForbidden();

        ManualSubscriptionActivator::resume($a['company']);

        $this->actingAs($a['technician']->fresh())->get("/{$a['company']->slug}/dashboard")->assertOk();
    }

    public function test_whatsapp_buttons_do_nothing_for_companies_without_access(): void
    {
        config(['services.whatsapp.app_secret' => 'secret']);
        Http::fake();

        $a = $this->tenantInState(-1, ['status' => 'paused']);
        $a['technician']->update(['phone' => '5491111111111']);

        $workOrder = WorkOrder::factory()->create(['building_id' => $a['building']->id]);
        $workOrder->users()->attach($a['technician']->id);

        $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '5491111111111',
            'type' => 'interactive',
            'interactive' => ['button_reply' => ['id' => 'take_work_order_'.$workOrder->id]],
        ]]]]]]]]);

        $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
        ], $body)->assertOk()->assertJson(['status' => 'missing_data']);

        $this->assertSame('pending', $workOrder->fresh()->status);
    }

    public function test_public_documents_already_sent_to_customers_keep_working(): void
    {
        $a = $this->tenantInState(-1, ['status' => 'paused']);
        $note = DeliveryNote::factory()->create([
            'building_id' => $a['building']->id,
            'user_id' => $a['technician']->id,
        ]);

        $this->get("/{$a['company']->slug}/public/delivery-notes/{$note->public_token}")->assertOk();
    }
}
