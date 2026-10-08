<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanFeature;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\DeliveryNote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMercadoPago;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Funciones por plan con experiencia de upgrade, empresas existentes
 * (migración) y Mercado Pago con los planes nuevos.
 */
class PlanFeaturesAndMigrationTest extends TestCase
{
    use InteractsWithMercadoPago, InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->setUpMercadoPago();
        $this->a = $this->makeTenant();
    }

    private function onPlan(string $slug): Subscription
    {
        $plan = SubscriptionPlan::findBySlug($slug);

        $subscription = Subscription::updateOrCreate(['company_id' => $this->a['company']->id], [
            'provider' => 'mercadopago',
            'provider_subscription_id' => 'PRE-A1',
            'external_reference' => 'ascento-company-'.$this->a['company']->id,
            'plan' => $slug,
            'status' => Subscription::AUTHORIZED,
            'amount' => $plan->price,
            'currency' => 'ARS',
            'current_period_end' => now()->addMonth(),
        ]);

        $this->a['company']->forgetPlan();

        return $subscription;
    }

    /*
    |--------------------------------------------------------------------------
    | FUNCIONES RESTRINGIDAS → UPGRADE (no 403)
    |--------------------------------------------------------------------------
    */

    public function test_quotes_on_inicial_explain_and_offer_the_upgrade(): void
    {
        $this->onPlan('inicial');
        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListQuotes::class)
            ->assertRedirect(SubscriptionPage::getUrl(['funcion' => 'quotes'], panel: 'ascensores_app').'#planes')
            ->assertNotified('Presupuestos está disponible desde el plan Profesional.');

        $this->assertSame('Profesional', QuoteResource::getNavigationBadge());

        $this->get(SubscriptionPage::getUrl(['funcion' => 'quotes']))
            ->assertOk()
            ->assertSee('Presupuestos está disponible desde el plan Profesional.')
            ->assertSee('Con Profesional obtenés');
    }

    public function test_quotes_work_normally_on_profesional(): void
    {
        $this->onPlan('profesional');
        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListQuotes::class)->assertNoRedirect();
        $this->assertNull(QuoteResource::getNavigationBadge());
    }

    public function test_digital_delivery_notes_are_from_profesional_but_the_signed_record_is_for_everyone(): void
    {
        $note = DeliveryNote::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ]);
        $showUrl = "/{$this->a['company']->slug}/delivery-notes/{$note->number}";
        $pdfUrl = "{$showUrl}/pdf";

        $this->onPlan('inicial');

        // El técnico ve su remito (registro del trabajo) pero no lo comparte.
        $this->actingAs($this->a['technician'])->get($showUrl)
            ->assertOk()
            ->assertSee('disponible desde el plan')
            ->assertDontSee('Compartir remito');

        // El admin que pide el PDF ve el upgrade, no un 403.
        $this->actingAs($this->a['admin'])->get($pdfUrl)
            ->assertRedirect(SubscriptionPage::getUrl(['funcion' => 'digital_delivery_notes'], panel: 'ascensores_app').'#planes');

        // Un link ya enviado a un cliente sigue abriendo.
        auth()->logout();
        $this->get("/{$this->a['company']->slug}/public/delivery-notes/{$note->public_token}")->assertOk();

        $this->onPlan('profesional');

        $this->actingAs($this->a['technician'])->get($showUrl)
            ->assertOk()
            ->assertSee('Compartir remito');
    }

    /*
    |--------------------------------------------------------------------------
    | EMPRESAS EXISTENTES
    |--------------------------------------------------------------------------
    */

    public function test_the_migration_moves_the_149000_plan_to_empresa_keeping_the_price(): void
    {
        $migration = require database_path('migrations/2026_10_13_100000_introduce_three_plans_with_limits.php');

        // Estado previo: plan "professional" de $149.000 y una empresa suscripta a él.
        $migration->down();
        DB::table('subscription_plans')->updateOrInsert(['slug' => 'professional'], ['name' => 'Ascento', 'price' => 149000, 'currency' => 'ARS', 'is_active' => true]);
        DB::table('subscriptions')->insert([
            'company_id' => $this->a['company']->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => 'PRE-LEGACY',
            'external_reference' => 'ascento-company-'.$this->a['company']->id,
            'plan' => 'professional',
            'status' => 'authorized',
            'amount' => 149000,
            'currency' => 'ARS',
            'current_period_end' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $subscription = Subscription::where('company_id', $this->a['company']->id)->sole();
        $this->assertSame('empresa', $subscription->plan);
        $this->assertSame('professional', $subscription->legacy_plan);
        $this->assertEquals(149000, $subscription->amount);           // sigue pagando lo mismo
        $this->assertFalse((bool) SubscriptionPlan::where('slug', 'professional')->value('is_active')); // no se borró
        $this->assertTrue($this->a['company']->fresh()->hasActiveAccess());
        $this->assertSame('empresa', $this->a['company']->fresh()->plan()->slug);

        // Mercado Pago le sigue cobrando $149.000 y la sincronización lo acepta.
        $this->mpPreapproval('PRE-LEGACY', $this->a['company'], 'authorized', ['auto_recurring' => ['transaction_amount' => 149000]]);
        $this->mpWebhook('subscription_preapproval', 'PRE-LEGACY')->assertJson(['status' => 'preapproval_authorized']);
    }

    public function test_every_company_always_has_a_valid_plan(): void
    {
        // Prueba gratis sin suscripción → Profesional.
        $this->assertSame('profesional', $this->a['company']->plan()->slug);

        // Slug desconocido (dato viejo) → Empresa, sin quitarle nada.
        Subscription::create(['company_id' => $this->a['company']->id, 'provider' => 'manual', 'plan' => 'basic', 'status' => 'active', 'current_period_end' => now()->addDays(5)]);
        $this->a['company']->forgetPlan();
        $this->assertSame('empresa', $this->a['company']->plan()->slug);
    }

    /*
    |--------------------------------------------------------------------------
    | MERCADO PAGO
    |--------------------------------------------------------------------------
    */

    public function test_checkout_charges_the_chosen_plan(): void
    {
        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->mpApi['POST /preapproval'] = ['id' => 'PRE-INI', 'status' => 'pending', 'init_point' => 'https://www.mercadopago.com.ar/x'];

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->call('checkout', 'inicial')
            ->assertRedirect('https://www.mercadopago.com.ar/x');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['auto_recurring']['transaction_amount'] == 69000);

        $subscription = Subscription::sole();
        $this->assertSame('inicial', $subscription->plan);
        $this->assertEquals(69000, $subscription->amount);

        // Un plan inexistente o inactivo no se puede elegir por Livewire.
        Livewire::test(SubscriptionPage::class)->call('checkout', 'professional');
        $this->assertSame('inicial', Subscription::sole()->plan);
    }

    public function test_upgrading_updates_mercado_pago_and_the_plan(): void
    {
        $this->onPlan('inicial');
        $this->mpPreapproval('PRE-A1', $this->a['company'], 'authorized', ['auto_recurring' => ['transaction_amount' => 69000]]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->callAction('changePlan', arguments: ['plan' => 'profesional'])
            ->assertNotified('Listo: ahora tenés el plan Profesional');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/preapproval/PRE-A1')
            && $request['reason'] === 'Ascento - Ascento Profesional'
            && $request['auto_recurring']['transaction_amount'] == 119000);

        $subscription = Subscription::sole();
        $this->assertSame('profesional', $subscription->plan);
        $this->assertEquals(119000, $subscription->amount);
        $this->assertEquals(69000, $subscription->previous_amount);
        $this->assertSame('profesional', $this->a['company']->fresh()->plan()->slug);

        // El cobro de este ciclo puede llegar con el importe anterior: se acepta.
        $this->mpCharge('AP-OLD', 'PRE-A1', 'approved', 69000);
        $this->mpWebhook('subscription_authorized_payment', 'AP-OLD')->assertJson(['status' => 'payment_approved']);
    }

    public function test_if_mercado_pago_rejects_the_change_the_plan_stays(): void
    {
        $this->onPlan('inicial');
        $this->mpApi['PUT_FAILS'] = true;

        $this->actingInPanel($this->a['admin']);
        Livewire::test(SubscriptionPage::class)
            ->callAction('changePlan', arguments: ['plan' => 'empresa'])
            ->assertNotified('No se pudo cambiar de plan');

        $this->assertSame('inicial', Subscription::sole()->plan);
    }

    /*
    |--------------------------------------------------------------------------
    | LANDING
    |--------------------------------------------------------------------------
    */

    public function test_the_landing_shows_the_three_plans_and_the_entry_price(): void
    {
        auth()->logout();

        $this->get('/')
            ->assertOk()
            ->assertSee('Gestioná tu empresa de ascensores')
            ->assertSee('Desde $69.000/mes')
            ->assertSeeInOrder(['Inicial', '$69.000', 'Recomendado', 'Profesional', '$119.000', 'Empresa', '$169.000'])
            ->assertSee('Hasta 20 edificios')
            ->assertSee('Hasta 15 reportes por mes')
            ->assertSee('Reportes sin límite mensual')
            ->assertSee('Remitos digitales para el cliente')
            ->assertDontSee('149.000')
            ->assertDontSee('Edificios ilimitados');
    }

    public function test_feature_helper_reads_the_plan(): void
    {
        $this->onPlan('inicial');
        $this->assertFalse($this->a['company']->plan()->allows(PlanFeature::Quotes));
        $this->assertTrue($this->a['company']->plan()->allows(PlanFeature::Map));
    }
}
