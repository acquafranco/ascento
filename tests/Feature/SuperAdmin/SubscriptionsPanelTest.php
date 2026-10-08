<?php

namespace Tests\Feature\SuperAdmin;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Subscriptions\Widgets\SubscriptionStats;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Panel del SuperAdmin: ver suscripciones y períodos de todas las empresas.
 * Sin transferencias ni "días restantes".
 */
class SubscriptionsPanelTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $super;

    private array $active;

    private array $trial;

    private array $rejected;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::factory()->superAdmin()->create();

        $this->active = $this->makeTenant();
        $this->trial = $this->makeTenant();
        $this->rejected = $this->makeTenant();

        foreach ([$this->active, $this->rejected] as $tenant) {
            $tenant['company']->forceFill(['trial_ends_at' => now()->subMonth()])->save();
        }

        $this->subscription = Subscription::create([
            'company_id' => $this->active['company']->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => 'PRE-ACTIVE',
            'status' => Subscription::AUTHORIZED,
            'amount' => 149000,
            'currency' => 'ARS',
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
            'next_payment_at' => now()->addDays(25),
            'last_payment_status' => 'approved',
            'last_payment_at' => now()->subDays(5),
        ]);

        SubscriptionPayment::create([
            'subscription_id' => $this->subscription->id,
            'company_id' => $this->active['company']->id,
            'provider_payment_id' => 'AP-1',
            'provider_preapproval_id' => 'PRE-ACTIVE',
            'status' => 'approved',
            'amount' => 149000,
            'currency' => 'ARS',
            'paid_at' => now()->subDays(5),
            'period_start' => now()->subDays(5),
            'period_end' => now()->addDays(25),
        ]);

        Subscription::create([
            'company_id' => $this->rejected['company']->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => 'PRE-REJ',
            'status' => Subscription::PAST_DUE,
            'amount' => 149000,
            'current_period_end' => now()->subDay(),
            'last_payment_status' => 'rejected',
        ]);

        $this->actingInPanel($this->super);
    }

    public function test_status_is_derived_from_the_real_access_rules(): void
    {
        $this->assertSame('active', SubscriptionStatus::key($this->active['company']->fresh()));
        $this->assertSame('trial', SubscriptionStatus::key($this->trial['company']->fresh()));
        $this->assertSame('past_due', SubscriptionStatus::key($this->rejected['company']->fresh()));

        $this->trial['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->assertSame('trial_ended', SubscriptionStatus::key($this->trial['company']->fresh()));
    }

    public function test_companies_show_subscription_and_periods_without_transfer_tools(): void
    {
        Livewire::test(ListCompanies::class)
            ->assertSee('Activa')
            ->assertSee('Prueba gratis')
            ->assertSee('Pago rechazado')
            ->assertSee($this->subscription->current_period_end->format('d/m/Y'))
            ->assertDontSee('Activar pago')
            ->assertDontSee('Días restantes')
            ->assertDontSee('Reanudar (sin sumar días)');
    }

    public function test_companies_can_be_filtered_by_subscription_status(): void
    {
        Livewire::test(ListCompanies::class)
            ->filterTable('subscription', 'trial')
            ->assertCanSeeTableRecords([$this->trial['company']])
            ->assertCanNotSeeTableRecords([$this->active['company'], $this->rejected['company']]);
    }

    public function test_subscriptions_list_with_monthly_summary(): void
    {
        Livewire::test(ListSubscriptions::class)
            ->assertCanSeeTableRecords(Subscription::all());

        // El widget de resumen carga en diferido: se prueba directo.
        Livewire::test(SubscriptionStats::class)
            ->assertSee('Suscripciones activas')
            ->assertSee('Pago rechazado')
            ->assertSee('Cobrado este mes')
            ->assertSee('$149.000');
    }

    public function test_subscription_detail_shows_payments(): void
    {
        $this->get(SubscriptionResource::getUrl('view', ['record' => $this->subscription]))
            ->assertOk()
            ->assertSee($this->active['company']->name)
            ->assertSee('Pagado hasta')
            ->assertSee('Período y cobros');

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->subscription,
            'pageClass' => ViewSubscription::class,
        ])->assertCanSeeTableRecords(SubscriptionPayment::all())->assertSee('Aprobado');
    }

    public function test_sync_action_reads_mercado_pago(): void
    {
        config(['services.mercadopago.access_token' => 'APP_USR-TEST']);
        Http::fake(['api.mercadopago.com/*' => Http::response([
            'id' => 'PRE-ACTIVE', 'status' => 'authorized', 'external_reference' => 'ascento-company-'.$this->active['company']->id,
            'auto_recurring' => ['transaction_amount' => 149000, 'currency_id' => 'ARS'], 'results' => [],
        ])]);

        Livewire::test(ViewSubscription::class, ['record' => $this->subscription->getRouteKey()])
            ->callAction('sync')
            ->assertNotified('Sincronizada con Mercado Pago');

        $this->assertNotNull($this->subscription->fresh()->last_synced_at);
    }

    public function test_subscriptions_are_read_only_and_only_for_the_super_admin(): void
    {
        $this->assertFalse(SubscriptionResource::canCreate());
        $this->assertFalse(SubscriptionResource::canEdit($this->subscription));
        $this->assertFalse(SubscriptionResource::canDelete($this->subscription));

        $this->actingInPanel($this->active['admin'])
            ->get(SubscriptionResource::getUrl('index'))
            ->assertForbidden();
    }
}
