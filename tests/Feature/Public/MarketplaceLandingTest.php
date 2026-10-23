<?php

namespace Tests\Feature\Public;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Página comercial del marketplace (/marketplace): usa las rutas reales del
 * sistema, los números de la configuración comercial y capturas reales con
 * datos de ejemplo; no promete ventas ni ofrece Premium si no se puede cobrar.
 */
class MarketplaceLandingTest extends TestCase
{
    use RefreshDatabase;

    private function section(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('#<section[^>]*\bid="'.$id.'".*?</section>#s', $html, $m), $id);

        return $m[0];
    }

    public function test_the_page_is_public_and_its_buttons_go_to_real_routes(): void
    {
        $html = $this->get(route('marketplace'))->assertOk()->getContent();
        $this->assertSame(url('/marketplace'), route('marketplace'));
        $hero = $this->section($html, 'inicio');

        $this->assertStringContainsString('El punto de encuentro entre las empresas de ascensores', $hero);
        $this->assertStringContainsString('y los proveedores de repuestos', $hero);
        $this->assertStringContainsString('Descubrí productos, explorá catálogos y conectá con proveedores del sector.', $hero);
        $this->assertStringContainsString('href="'.PartsCatalog::getUrl(panel: 'ascensores_app').'" data-cta-explore', $hero);
        $this->assertStringContainsString('href="'.route('suppliers.register').'" data-cta-publish', $hero);
        $this->assertStringContainsString('href="'.route('login').'" data-cta-login', $hero);
        foreach (['Explorar repuestos', 'Publicar mi catálogo', 'Ya tengo cuenta'] as $cta) {
            $this->assertStringContainsString($cta, $hero);
        }

        // Cierre con una llamada para cada público.
        $close = $this->section($html, 'empezar');
        $this->assertStringContainsString('data-close-companies', $close);
        $this->assertStringContainsString('data-close-suppliers', $close);
        $this->assertStringContainsString(route('register'), $close);
        $this->assertStringContainsString(route('suppliers.register'), $close);

        // Enlaces legales y vuelta a la gestión.
        foreach (['legal.terms', 'legal.privacy', 'legal.data-deletion', 'legal.cookies'] as $name) {
            $this->assertStringContainsString(route($name), $html, $name);
        }
        $this->assertStringContainsString(route('legal.terms').'#proveedores', $html);
        $this->assertStringContainsString('href="'.url('/').'"', $html);
    }

    public function test_the_explore_button_asks_for_an_account_and_logged_users_can_see_the_page(): void
    {
        $this->get(PartsCatalog::getUrl(panel: 'ascensores_app'))->assertRedirect(route('login'));

        $owner = User::factory()->admin()->create();
        $this->actingAs($owner)->get('/marketplace')->assertOk();
    }

    public function test_it_shows_real_screenshots_that_exist_are_light_and_are_labelled_as_example_data(): void
    {
        $html = $this->get('/marketplace')->getContent();

        preg_match_all('#/images/marketplace/([a-z0-9-]+\.jpg)#', $html, $m);
        $files = array_unique($m[1]);
        $this->assertEqualsCanonicalizing(['catalogo.jpg', 'catalogo-filtros.jpg', 'ficha.jpg', 'ficha-movil.jpg', 'proveedor-fotos.jpg'], array_values($files));
        foreach ($files as $file) {
            $path = public_path('images/marketplace/'.$file);
            $this->assertFileExists($path);
            $this->assertLessThan(200 * 1024, filesize($path), "{$file} pesa demasiado");
        }
        $this->assertStringContainsString('la empresa, los proveedores, las marcas y las imágenes de los productos son ficticios', $this->section($html, 'capturas'));
        $this->assertStringContainsString('role="tablist"', $this->section($html, 'capturas'));
    }

    public function test_the_free_limit_comes_from_the_settings_and_premium_only_shows_when_it_is_really_sold(): void
    {
        config(['services.mercadopago.access_token' => null, 'services.mercadopago.webhook_secret' => null]);
        $settings = MarketplaceSettings::current();
        $settings->update(['free_product_limit' => 25]);

        $html = $this->get('/marketplace')->getContent();
        $this->assertStringContainsString('Gratis: hasta 25 productos publicados a la vez.', $this->section($html, 'proveedores'));
        $this->assertStringContainsString('hasta 25 productos', $this->section($html, 'inicio'));
        $this->assertStringNotContainsString('Premium', $html);

        // Encendido con precio pero sin credenciales completas: todavía no se anuncia.
        $settings->update(['premium_enabled' => true, 'premium_price' => 19900]);
        $this->assertStringNotContainsString('Premium', $this->get('/marketplace')->getContent());

        // Con todo lo necesario para cobrar: se informa con el límite y el precio configurados.
        config(['services.mercadopago.access_token' => 'APP_USR-x', 'services.mercadopago.webhook_secret' => 'clave']);
        $this->assertStringContainsString('Premium amplía el límite hasta 1.000 productos por ARS 19.900 por mes.', $this->section($this->get('/marketplace')->getContent(), 'proveedores'));
    }

    public function test_it_explains_both_journeys_without_promising_sales(): void
    {
        $html = $this->get('/marketplace')->getContent();
        $how = $this->section($html, 'como-funciona');

        foreach (['Explorá los repuestos', 'Revisá la información y las fotos', 'Pedí una cotización', 'Acordá con el proveedor',
            'Creá tu cuenta', 'Publicá tus productos', 'Mantené tu catálogo al día', 'Recibí pedidos de cotización'] as $step) {
            $this->assertStringContainsString($step, $how, $step);
        }
        $this->assertStringContainsString('no cobra comisiones por las ventas ni procesa sus pagos', $how);
        $this->assertStringContainsString('Hasta 6 fotos por repuesto', $this->section($html, 'proveedores'));

        foreach (['garantiz', 'vas a vender', 'más ventas', 'aumentá tus ventas', 'miles de', 'compradores asegurados'] as $claim) {
            $this->assertStringNotContainsString($claim, mb_strtolower($html), $claim);
        }
        $this->assertStringNotContainsString('Profesionales y servicios', $html);
    }

    public function test_the_animations_are_light_and_respect_reduced_motion(): void
    {
        $html = $this->get('/marketplace')->getContent();

        $this->assertStringNotContainsString('elevator-doors', $html);
        $this->assertStringContainsString('data-shaft', $html);
        $this->assertStringContainsString("if (!shaft || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;", $html);
        $this->assertMatchesRegularExpression('#@media \(prefers-reduced-motion: reduce\) \{[^}]*\[data-reveal\]#', $html);
    }
}
