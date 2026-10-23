<?php

namespace Tests\Feature\Public;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las páginas legales dicen lo mismo que hace la aplicación: registro solo
 * desde Argentina por defecto, los países habilitados cuando el switch está
 * encendido (sin prometer pagos ni cobertura en ellos), el catálogo público
 * si está encendido, y siempre el aviso de borrador sin revisión legal.
 */
class LegalCountriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['legal.reviewed' => false, 'legal.owner_name' => null, 'legal.owner_tax_id' => null, 'legal.owner_address' => null]);
    }

    public function test_by_default_the_texts_say_argentina_only_and_promise_no_international_payments(): void
    {
        $terms = $this->get(route('legal.terms'))->assertOk()->getContent();
        $privacy = $this->get(route('legal.privacy'))->assertOk()->getContent();

        $this->assertStringContainsString('las cuentas nuevas de empresas y de proveedores se registran solo desde la República Argentina', $terms);
        $this->assertStringContainsString('Las cuentas nuevas de proveedores se registran solo desde Argentina', $terms);
        $this->assertStringContainsString('Hoy, las cuentas nuevas se registran solo desde Argentina.', $privacy);
        $this->assertStringContainsString('Ascento no ofrece cobros en la moneda de otros países', $terms);
        foreach ([$terms, $privacy] as $html) {
            $this->assertStringNotContainsString('en otros países de Latinoamérica. El servicio', $html);
            $this->assertStringNotContainsString('toda Latinoamérica', $html);
            $this->assertStringNotContainsString('pagos internacionales disponibles', $html);
        }
    }

    public function test_with_enabled_countries_the_texts_name_them_and_disclaim_payments_and_coverage(): void
    {
        PlatformSettings::row()->forceFill(['international_registration_enabled' => true, 'registration_countries' => ['UY']])->save();

        $terms = $this->get(route('legal.terms'))->getContent();
        $this->assertStringContainsString('Hoy se aceptan registros nuevos de empresas y de proveedores desde estos países: Argentina, Uruguay.', $terms);
        $this->assertStringContainsString('no significa que estén disponibles ahí el pago de las suscripciones, la facturación ni todas las funciones comerciales', $terms);
        $this->assertStringContainsString('ni que Ascento tenga presencia, representación o cobertura legal propia en ese país', $terms);
        $this->assertStringNotContainsString('Chile', $terms);
        $this->assertStringContainsString('Hoy se aceptan registros nuevos desde Argentina, Uruguay.', $this->get(route('legal.privacy'))->getContent());
    }

    public function test_the_public_catalog_is_disclosed_only_while_it_is_on(): void
    {
        $terms = $this->get(route('legal.terms'))->getContent();
        $this->assertStringContainsString('Catálogo público:', $terms);
        $this->assertStringContainsString('No se muestran públicamente sus correos, teléfonos ni su identificación fiscal', $terms);
        $this->assertStringContainsString('para cualquier visitante sin cuenta', $this->get(route('legal.privacy'))->getContent());

        MarketplaceSettings::current()->update(['public_catalog_enabled' => false]);
        $this->assertStringNotContainsString('Catálogo público:', $this->get(route('legal.terms'))->getContent());
    }

    public function test_prices_are_not_announced_as_current_while_they_can_not_be_charged(): void
    {
        config(['services.mercadopago.access_token' => null, 'services.mercadopago.webhook_secret' => null]);
        MarketplaceSettings::current()->update(['premium_enabled' => true, 'premium_price' => 19900, 'featured_enabled' => true, 'featured_price_7' => 4900]);

        $terms = $this->get(route('legal.terms'))->getContent();
        $this->assertStringNotContainsString('Precio vigente:', $terms);
        $this->assertStringNotContainsString('Precios vigentes:', $terms);
    }

    public function test_the_legal_review_is_never_presented_as_done_and_owner_data_stays_pending(): void
    {
        foreach (['legal.terms', 'legal.privacy', 'legal.cookies', 'legal.data-deletion'] as $page) {
            $this->get(route($page))->assertOk()->assertSee('Borrador pendiente de revisión legal')->assertSee('pendiente de completar');
        }
        $this->assertFalse((bool) env('LEGAL_REVIEWED', false));
    }
}
