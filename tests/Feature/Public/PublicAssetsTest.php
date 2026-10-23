<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Páginas públicas: estilos, tipografías y scripts compilados y servidos
 * por Ascento (sin CDN de Tailwind, Google Fonts ni Alpine de un CDN).
 */
class PublicAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_do_not_load_styles_fonts_or_scripts_from_third_parties(): void
    {
        $this->withoutVite(); // no depende de que exista public/build

        foreach (['/', '/marketplace', '/login', '/register', '/forgot-password', '/legal/terminos', '/legal/cookies'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net', 'cdn.jsdelivr.net', 'unpkg.com'] as $host) {
                $this->assertStringNotContainsString($host, $html, "{$url} carga algo de {$host}");
            }
        }

        // Y los estilos vienen del build propio.
        foreach (['welcome', 'marketplace/landing', 'layouts/guest', 'legal/layout'] as $view) {
            $this->assertStringContainsString('resources/css/public.css', (string) file_get_contents(resource_path("views/{$view}.blade.php")));
        }
        $this->assertStringContainsString('resources/css/public.css', (string) file_get_contents(base_path('vite.config.js')));
    }
}
