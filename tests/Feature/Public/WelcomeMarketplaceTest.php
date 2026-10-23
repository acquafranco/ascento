<?php

namespace Tests\Feature\Public;

use App\Models\Marketplace\MarketplaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inicio (rediseño del marketplace, 23/10/2026): propuesta para empresas y
 * para proveedores, con números de la configuración comercial real y sin
 * anunciar Premium mientras no se pueda contratar de verdad.
 */
class WelcomeMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function section(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('#<section[^>]*\bid="'.$id.'".*?</section>#s', $html, $m), $id);

        return $m[0];
    }

    public function test_both_audiences_get_their_message_and_their_own_registration(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $hero = $this->section($html, 'inicio');

        $this->assertStringContainsString('Donde las empresas de ascensores encuentran sus repuestos', $hero);
        $this->assertStringContainsString('Y los proveedores, a sus próximos clientes.', $hero);
        $this->assertStringContainsString('href="'.route('register').'" data-cta-company', $hero);
        $this->assertStringContainsString('href="'.route('suppliers.register').'" data-cta-supplier', $hero);
        $this->assertStringContainsString(route('login'), $hero);
        $this->assertStringContainsString('Ilustración con datos de ejemplo.', $hero);

        $this->assertStringContainsString('El repuesto que necesitás', $this->section($html, 'repuestos'));
        $this->assertStringContainsString('Ascento no cobra comisiones ni procesa esos pagos.', $this->section($html, 'repuestos'));
        $this->assertStringContainsString('Tu catálogo, presentado como se merece.', $this->section($html, 'proveedores'));
        foreach (['href="#repuestos"', 'href="#proveedores"', 'href="#beneficios"', 'href="#planes"', 'href="#faq"'] as $link) {
            $this->assertStringContainsString($link, $html, $link);
        }
        // Sin promesas de resultados en las secciones del marketplace.
        $market = mb_strtolower($hero.$this->section($html, 'repuestos').$this->section($html, 'proveedores'));
        foreach (['garantiz', 'vas a vender', 'más ventas', 'aumentá tus ventas', 'miles de'] as $claim) {
            $this->assertStringNotContainsString($claim, $market, $claim);
        }
        $this->assertStringNotContainsString('Profesionales y servicios', $html);
    }

    public function test_the_free_limit_comes_from_the_settings_and_premium_only_shows_when_it_is_really_sold(): void
    {
        config(['services.mercadopago.access_token' => null, 'services.mercadopago.webhook_secret' => null]);
        $settings = MarketplaceSettings::current();
        $settings->update(['free_product_limit' => 25]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('hasta 25 productos', $this->section($html, 'inicio'));
        $this->assertStringContainsString('hasta 25 productos publicados a la vez', $this->section($html, 'proveedores'));
        $this->assertStringContainsString('hasta 25 productos publicados a la vez', $this->section($html, 'faq'));
        $this->assertStringNotContainsString('Premium', $this->section($html, 'proveedores'));

        // Encendido con precio pero sin credenciales completas: todavía no se anuncia.
        $settings->update(['premium_enabled' => true, 'premium_price' => 19900]);
        $this->assertStringNotContainsString('Premium', $this->section($this->get('/')->getContent(), 'proveedores'));

        // Con todo lo necesario para cobrar: se informa con el límite y el precio configurados.
        config(['services.mercadopago.access_token' => 'APP_USR-x', 'services.mercadopago.webhook_secret' => 'clave']);
        $this->assertStringContainsString('Premium amplía el límite hasta 1.000 productos por ARS 19.900 por mes.', $this->section($this->get('/')->getContent(), 'proveedores'));
    }

    public function test_the_management_offer_and_its_entry_price_stay(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $anywhere = $this->section($html, 'desde-cualquier-lugar');

        $this->assertStringContainsString('data-price-anchor', $anywhere);
        $this->assertStringContainsString('Empezá a organizar tu empresa desde el primer día', $anywhere);
        $this->assertLessThan(strpos($html, 'id="desde-cualquier-lugar"'), strpos($html, 'id="proveedores"'));
        foreach (['beneficios', 'como-funciona', 'demo', 'planes', 'prueba-gratis', 'faq', 'contacto'] as $id) {
            $this->section($html, $id);
        }
    }
}
