<?php

namespace Tests\Feature\Public;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pulido de la página de inicio: funciones en tres grupos, capturas que no
 * se frenan con el cursor, panel con pestañas, planes del mismo tamaño y la
 * prueba gratis explicada según cómo funciona de verdad.
 */
class LandingLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function home(): string
    {
        $this->withoutVite();

        return $this->get('/')->assertOk()->getContent();
    }

    private function section(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('#<section id="'.$id.'".*?</section>#s', $html, $m), $id);

        return $m[0];
    }

    public function test_the_sixteen_features_are_kept_once_each_in_three_groups(): void
    {
        $benefits = $this->section($this->home(), 'beneficios');

        $this->assertSame(3, substr_count($benefits, 'data-carousel '));
        preg_match_all('#<h3[^>]*>([^<]+)</h3>#', $benefits, $m);
        $this->assertCount(16, $m[1]);
        $this->assertSame($m[1], array_unique($m[1]), 'Ninguna función repetida.');
        foreach (['Agenda de mantenimientos e inspecciones', 'Técnicos desde el celular', 'Stock, servicios y cobranzas', 'Video en los reportes'] as $title) {
            $this->assertContains($title, $m[1]);
        }
        // Controles accesibles en cada grupo.
        $this->assertSame(3, substr_count($benefits, 'data-carousel-prev'));
        $this->assertSame(3, substr_count($benefits, 'data-carousel-next'));
    }

    public function test_the_app_screenshots_do_not_pause_on_hover_and_the_loop_copy_is_hidden(): void
    {
        $html = $this->home();

        $this->assertStringNotContainsString('animation-play-state: paused', $html);
        $this->assertStringNotContainsString('animate-marquee', $html);
        $this->assertStringContainsString('data-marquee-track', $html);
        // 7 capturas reales y su copia para el loop, fuera de la lectura y del teclado.
        $this->assertSame(7, substr_count($html, 'aria-hidden="true" inert data-marquee-copy'));
        $this->assertStringContainsString('data-marquee-prev', $html);
        $this->assertStringContainsString('data-marquee-next', $html);
    }

    public function test_the_company_panel_is_introduced_and_keeps_its_six_screens(): void
    {
        $demo = $this->section($this->home(), 'demo');

        $this->assertStringContainsString('El panel de Ascento, en la computadora.', $demo);
        $this->assertSame(6, substr_count($demo, 'role="tab"'));
        foreach (['admin-inicio', 'admin-mapa', 'admin-presupuesto', 'admin-reparaciones', 'admin-paracaidas', 'admin-indicadores'] as $file) {
            $this->assertStringContainsString('images/landing/'.$file.'.jpg', $demo);
        }
    }

    public function test_the_plan_cards_are_the_same_size_and_the_trial_is_explained_once(): void
    {
        $planes = $this->section($this->home(), 'planes');

        // Mismo tamaño: el recomendado ya no se agranda.
        $this->assertStringNotContainsString('lg:-my-4', $planes);
        $this->assertStringContainsString('lg:items-stretch', $planes);
        $this->assertSame(3, substr_count($planes, 'Ver qué incluye'));

        // La prueba no se elige por plan: una sola mención, con el plan real de la prueba.
        $trial = SubscriptionPlan::findBySlug(SubscriptionPlan::TRIAL_PLAN)->shortName();
        $this->assertSame(1, substr_count($planes, 'Probar gratis 30 días'));
        $this->assertStringContainsString('30 días con todas las funciones del plan '.$trial.', sin tarjeta. Al terminar elegís el plan desde tu cuenta.', $planes);
        $this->assertStringContainsString('id="comparativa"', $planes);

        // Precios y orden, sin cambios.
        $this->assertMatchesRegularExpression('#Inicial.*\$69\.000.*Recomendado.*Profesional.*\$119\.000.*Empresa.*\$169\.000#s', $planes);
    }

    public function test_the_free_trial_section_stays(): void
    {
        $prueba = $this->section($this->home(), 'prueba-gratis');

        $this->assertStringContainsString('Probá el sistema gratis durante 30 días.', $prueba);
        $this->assertStringContainsString(route('register'), $prueba);
    }

    public function test_the_faq_answers_who_it_is_for_how_the_trial_ends_and_how_to_ask_for_a_demo(): void
    {
        $faq = $this->section($this->home(), 'faq');

        $this->assertStringContainsString('¿Para quién es Ascento?', $faq);
        $this->assertStringContainsString('sin elegir un plan antes. Al terminar, para seguir usándolo elegís el plan que te sirva desde tu cuenta y lo pagás con Mercado Pago.', $faq);
        $this->assertStringContainsString('coordinamos una demostración', $faq);
        // Sin promesas que el sistema no define.
        $this->assertStringNotContainsString('reembolso', mb_strtolower($faq));
        $this->assertStringNotContainsString('garant', mb_strtolower($faq));
    }

    public function test_the_hero_doors_respect_reduced_motion(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('class="elevator-doors" aria-hidden="true"', $html);
        $this->assertMatchesRegularExpression('#@media \(prefers-reduced-motion: reduce\) \{\s*\.elevator-doors \{ display: none; \}#', $html);
    }

    public function test_the_hero_says_you_can_run_the_company_from_home_with_real_features(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('Gestioná tu empresa de ascensores', $html); // el título no cambia
        $this->assertMatchesRegularExpression('#Tu empresa de ascensores, bajo control\. <span[^>]*>Incluso desde casa\.</span>#', $html);
        $this->assertStringContainsString('Organizá mantenimientos, supervisá el trabajo de tus técnicos y consultá los remitos digitales desde un solo lugar, sin depender de estar en la oficina.', $html);
        // Una sola vez el mensaje principal: no se repite por toda la página.
        $this->assertSame(1, substr_count($html, 'Incluso desde casa.'));
    }

    public function test_the_position_indicator_shows_the_real_ascento_logo(): void
    {
        $html = $this->home();

        $this->assertMatchesRegularExpression('#<img src="[^"]*/images/brand/logo-128\.png" alt="" width="40" height="40" data-indicator-logo#', $html);
        $this->assertFileExists(public_path('images/brand/logo-128.png'));
        // Las puertas y el indicador animado siguen.
        $this->assertStringContainsString('class="elevator-doors" aria-hidden="true"', $html);
        $this->assertStringContainsString('id="floorDigit"', $html);
    }
}
