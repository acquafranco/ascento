<?php

namespace Tests\Feature\Plans;

use App\Filament\Pages\MarketplaceSettingsPage;
use App\Filament\Pages\PlatformSettingsPage;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSettings;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Support\Billing\BillingStatus;
use App\Support\Billing\MercadoPagoAccount;
use App\Support\Marketplace\SalesReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\VerifiesMercadoPagoAccount;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Los tres planes de las empresas de mantenimiento quedan intactos y separados
 * del Premium de proveedores; los precios objetivo no se cargan solos; y
 * ningún cobro nuevo empieza sin una cuenta de Mercado Pago verificada para el
 * entorno (sin deducirlo del prefijo del token y sin mostrar secretos).
 */
class CommercialSeparationTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase, VerifiesMercadoPagoAccount;

    /** @return array<string, array> lo que define cada plan de empresa */
    private function plans(): array
    {
        return SubscriptionPlan::orderBy('sort_order')->get()->mapWithKeys(fn (SubscriptionPlan $p) => [$p->slug => [
            (float) $p->price, $p->currency, $p->max_buildings, $p->max_clients, $p->max_technicians, $p->max_reports_per_month, (bool) $p->is_active, (bool) $p->is_recommended,
        ]])->all();
    }

    public function test_the_three_company_plans_keep_their_values_and_premium_is_not_one_of_them(): void
    {
        $this->assertSame([
            'inicial' => [69000.0, 'ARS', 20, 50, 3, 15, true, false],
            'profesional' => [119000.0, 'ARS', 120, 150, 10, null, true, true],
            'empresa' => [169000.0, 'ARS', 400, 420, 25, null, true, false],
        ], $this->plans());
        $this->assertSame(30, SubscriptionPlan::TRIAL_DAYS);
        $this->assertSame(['inicial', 'profesional', 'empresa'], SubscriptionPlan::offered()->pluck('slug')->all());

        // El plan de proveedores tiene su propio identificador y nunca es un plan de empresa.
        foreach ([MarketplaceSettings::PREMIUM_PLAN_CODE, MarketplaceSettings::FREE_PLAN_CODE] as $code) {
            $this->assertNull(SubscriptionPlan::findBySlug($code));
        }

        // Guardar la configuración de la plataforma o del marketplace no toca planes ni suscripciones.
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'profesional');
        $subscriptions = Subscription::orderBy('id')->get()->toArray();
        auth()->logout();
        $this->actingInPanel(User::factory()->superAdmin()->create());
        Livewire::test(PlatformSettingsPage::class)->set('data.international_registration_enabled', true)->set('data.registration_countries', ['CL'])->call('save');
        Livewire::test(MarketplaceSettingsPage::class)->call('useTargetPrices')->call('save');
        $this->assertSame([
            'inicial' => [69000.0, 'ARS', 20, 50, 3, 15, true, false],
            'profesional' => [119000.0, 'ARS', 120, 150, 10, null, true, true],
            'empresa' => [169000.0, 'ARS', 400, 420, 25, null, true, false],
        ], $this->plans());
        $this->assertSame($subscriptions, Subscription::orderBy('id')->get()->toArray());
    }

    public function test_the_company_subscription_page_never_offers_the_supplier_premium(): void
    {
        MarketplaceSettings::current()->update(['premium_enabled' => true, 'premium_price' => 19900]);
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);

        $html = $this->get(SubscriptionPage::getUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('19.900', $html);
        $this->assertStringNotContainsString(MarketplaceSettings::PREMIUM_PLAN_CODE, $html);
    }

    public function test_target_prices_only_fill_the_form_and_never_turn_sales_on(): void
    {
        $this->actingInPanel(User::factory()->superAdmin()->create());
        $before = MarketplaceSettings::current()->only(['premium_price', 'featured_price_7', 'featured_price_30', 'premium_enabled', 'featured_enabled']);

        $this->get(MarketplaceSettingsPage::getUrl())->assertOk()->assertSee('Precios objetivo (Argentina, ARS)')->assertSee('ARS 19.900')->assertSee('ARS 4.900')->assertSee('ARS 9.900');
        Livewire::test(MarketplaceSettingsPage::class)->call('useTargetPrices')
            ->assertSet('data.premium_price', 19900.0)->assertSet('data.featured_price_7', 4900.0)->assertSet('data.featured_price_30', 9900.0)
            ->assertSet('data.premium_enabled', false)->assertSet('data.featured_enabled', false);
        $this->assertSame($before, MarketplaceSettings::current()->only(array_keys($before)));

        // Otra moneda: no hay precio objetivo (no se convierte).
        MarketplaceSettings::current()->update(['pricing_currency' => 'CLP']);
        $this->assertNull(MarketplaceSettings::current()->targetPrices());
    }

    public function test_new_supplier_charges_need_a_verified_account_for_the_environment(): void
    {
        config(['services.mercadopago.access_token' => 'APP_USR-123-SECRETO', 'services.mercadopago.webhook_secret' => 'clave-webhook']);
        [$supplier] = $this->makeSupplier();

        // Credenciales completas pero sin verificar: no se cobra (el prefijo APP_USR- no dice si es de prueba).
        $this->assertSame(MercadoPagoAccount::UNVERIFIED, MercadoPagoAccount::mode());
        $this->assertFalse(SupplierBilling::canCharge($supplier));

        // Verificada como cuenta de prueba fuera de producción: sí.
        $this->markMercadoPagoVerified(test: true);
        $this->assertSame(MercadoPagoAccount::TEST, MercadoPagoAccount::mode());
        $this->assertTrue(SupplierBilling::canCharge($supplier));

        // Una cuenta REAL fuera de producción: no (no se hacen pagos de prueba con credenciales reales).
        $this->markMercadoPagoVerified(test: false);
        $this->assertFalse(SupplierBilling::canCharge($supplier));
        $this->assertStringContainsString('es REAL y la aplicación no está en producción', implode(' ', SalesReadiness::gatewayProblems()));

        // En producción, una cuenta de prueba no sirve; una de otro país tampoco.
        $this->markMercadoPagoVerified(test: true);
        $this->app['env'] = 'production';
        config(['app.url' => 'https://ascento.online']);
        $problems = implode(' ', SalesReadiness::gatewayProblems());
        $this->markMercadoPagoVerified(test: false, site: 'MLC');
        $otherSite = implode(' ', SalesReadiness::gatewayProblems());
        $this->markMercadoPagoVerified(test: false);
        $ok = SalesReadiness::gatewayProblems();
        $this->app['env'] = 'testing';
        $this->assertStringContainsString('es de PRUEBA y la aplicación está en producción', $problems);
        $this->assertStringContainsString('no es de Argentina', $otherSite);
        $this->assertSame([], $ok);

        // Otro token: la verificación anterior deja de valer.
        config(['services.mercadopago.access_token' => 'APP_USR-otro-token']);
        $this->assertSame(MercadoPagoAccount::UNVERIFIED, MercadoPagoAccount::mode());
        $this->assertFalse(SupplierBilling::canCharge($supplier));
    }

    public function test_verifying_the_account_only_reads_it_and_never_stores_or_shows_the_token(): void
    {
        config(['services.mercadopago.access_token' => 'APP_USR-123-SECRETO', 'services.mercadopago.webhook_secret' => 'clave-webhook']);
        Http::preventStrayRequests();
        Http::fake(['api.mercadopago.com/users/me' => Http::response(['id' => 1, 'nickname' => 'TESTUSER', 'site_id' => 'MLA', 'tags' => ['test_user', 'normal']])]);
        $this->actingInPanel(User::factory()->superAdmin()->create());

        Livewire::test(PlatformSettingsPage::class)->call('verifyAccount')->assertNotified('Cuenta verificada');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/users/me'));
        $this->assertSame(MercadoPagoAccount::TEST, MercadoPagoAccount::mode());
        $row = PlatformSettings::current();
        $this->assertNotSame('APP_USR-123-SECRETO', $row->mp_token_fingerprint);
        $this->assertStringNotContainsString('APP_USR-123-SECRETO', json_encode($row->toArray()));
        $this->assertStringNotContainsString('SECRETO', (string) json_encode(PlatformAuditLog::where('action', 'platform.mercadopago.verified')->sole()->changes));

        $this->get(PlatformSettingsPage::getUrl())->assertOk()->assertSee('Cuenta de PRUEBA')
            ->assertSee('Suscripciones de empresas')->assertSee('Premium de proveedores')->assertSee('Destacados (7 y 30 días)')
            ->assertDontSee('APP_USR-123-SECRETO')->assertDontSee('clave-webhook');
    }

    public function test_a_test_token_never_starts_a_company_subscription_in_production(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-abc']);
        $this->assertTrue(BillingStatus::companyCanCharge());

        $this->app['env'] = 'production';
        $blocked = BillingStatus::companyCanCharge();
        $text = implode(' ', BillingStatus::companyBlockers());
        $this->app['env'] = 'testing';
        $this->assertFalse($blocked);
        $this->assertStringContainsString('credencial de prueba', $text);
        $this->assertStringNotContainsString('TEST-abc', $text);

        config(['services.mercadopago.access_token' => null]);
        $this->assertSame(['Falta MERCADOPAGO_ACCESS_TOKEN.'], BillingStatus::companyBlockers());
    }
}
