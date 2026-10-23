<?php

namespace Tests\Feature\Public;

use App\Models\Marketplace\MarketplaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inicio: la Welcome sigue presentando la gestión de la empresa (la versión
 * anterior al rediseño del 23/10/2026, df9085a) y suma el marketplace como una
 * sección adicional, que lleva a su página propia (/marketplace).
 */
class WelcomeMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function section(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('#<section[^>]*\bid="'.$id.'".*?</section>#s', $html, $m), $id);

        return $m[0];
    }

    public function test_the_welcome_keeps_presenting_the_management_software(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $hero = $this->section($html, 'inicio');

        $this->assertStringContainsString('Gestioná tu empresa de ascensores', $hero);
        $this->assertStringContainsString('Software para empresas de mantenimiento de ascensores', $hero);
        $this->assertStringContainsString('id="floorDigit"', $hero);
        $this->assertStringContainsString('class="elevator-doors" aria-hidden="true"', $hero);
        $this->assertStringNotContainsString('repuesto', mb_strtolower($hero));
        $this->assertStringContainsString('<title>Ascento — Software de gestión para empresas de mantenimiento de ascensores</title>', $html);

        // Las secciones originales siguen, en su orden.
        $ids = ['inicio', 'desde-cualquier-lugar', 'conecta', 'beneficios', 'como-funciona', 'demo', 'planes', 'prueba-gratis', 'faq', 'contacto'];
        $positions = array_map(fn ($id) => strpos($html, 'id="'.$id.'"'), $ids);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        // El menú superior es el original: el marketplace no lo desplaza.
        foreach (['href="#beneficios"', 'href="#como-funciona"', 'href="#planes"', 'href="#faq"'] as $link) {
            $this->assertStringContainsString($link, $html, $link);
        }
    }

    public function test_the_marketplace_is_an_additional_section_after_the_management_content(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $market = $this->section($html, 'marketplace');

        $this->assertStringContainsString('Los repuestos que necesitás, más cerca.', $market);
        $this->assertStringContainsString('Las empresas que buscan tus productos, en un mismo lugar.', $market);
        $this->assertStringContainsString('Para empresas de ascensores', $market);
        $this->assertStringContainsString('Para proveedores', $market);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('marketplace'), '#').'" data-marketplace-cta#', $market);
        $this->assertStringContainsString('Conocé el marketplace', $market);
        $this->assertStringContainsString('images/marketplace/catalogo.jpg', $market);
        $this->assertStringContainsString('Capturas del marketplace real de Ascento con datos de ejemplo.', $market);

        // Después de la presentación del panel y antes de los planes; una sola vez.
        $this->assertGreaterThan(strpos($html, 'id="demo"'), strpos($html, 'id="marketplace"'));
        $this->assertLessThan(strpos($html, 'id="planes"'), strpos($html, 'id="marketplace"'));
        $this->assertSame(1, substr_count($html, 'data-marketplace-teaser'));

        foreach (['garantiz', 'vas a vender', 'más ventas', 'aumentá tus ventas', 'miles de'] as $claim) {
            $this->assertStringNotContainsString($claim, mb_strtolower($market), $claim);
        }
        $this->assertStringNotContainsString('Profesionales y servicios', $html);
    }

    public function test_the_free_limit_in_the_section_comes_from_the_settings(): void
    {
        MarketplaceSettings::current()->update(['free_product_limit' => 25]);

        $this->assertStringContainsString('Gratis hasta 25 productos publicados.', $this->section($this->get('/')->getContent(), 'marketplace'));
    }

    public function test_the_top_bar_links_to_the_marketplace_on_desktop_and_mobile_without_replacing_login(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<header.*?</header>#s', $html, $m));
        $header = $m[0];

        $this->assertStringContainsString('href="'.route('marketplace').'" data-nav-marketplace ', $header);
        $this->assertStringContainsString('href="'.route('marketplace').'" data-nav-marketplace-mobile', $header);
        // Una vez en cada menú, y «Entrar» sigue siendo el botón principal.
        $this->assertSame(2, substr_count($header, 'href="'.route('marketplace').'"'));
        $this->assertSame(2, substr_count($header, 'href="'.route('login').'"'));
        $this->assertStringContainsString('#beneficios', $header);
        $this->assertStringContainsString('#planes', $header);
    }

    public function test_the_section_offers_the_public_catalog_without_an_account(): void
    {
        $section = $this->section($this->get('/')->getContent(), 'marketplace');
        $this->assertStringContainsString('href="'.route('marketplace.catalog').'" data-marketplace-catalog', $section);

        MarketplaceSettings::current()->update(['public_catalog_enabled' => false]);
        $this->assertStringNotContainsString('data-marketplace-catalog', $this->section($this->get('/')->getContent(), 'marketplace'));
    }

    public function test_the_app_screenshots_respect_reduced_motion_and_have_alt_texts(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('prefers-reduced-motion', $html);
        preg_match_all('#<img src="[^"]*/images/landing/([a-z-]+)\.jpg" alt="([^"]*)"#', $html, $m);
        $this->assertContains('tecnico-remito', $m[1]);
        $this->assertContains('tecnico-orden', $m[1]);
        $this->assertContains('tecnico-paracaidas', $m[1]);
        foreach ($m[2] as $alt) {
            $this->assertNotSame('', trim($alt));
        }
        foreach (array_unique($m[1]) as $file) {
            $this->assertFileExists(public_path('images/landing/'.$file.'.jpg'));
        }
    }
}
