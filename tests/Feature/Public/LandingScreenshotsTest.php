<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Carrusel de la landing: capturas actuales, existentes y livianas. */
class LandingScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_screenshot_in_the_landing_exists_and_is_light(): void
    {
        $this->withoutVite();
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('#/images/landing/([a-z0-9-]+\.jpg)#', $html, $matches);
        $files = array_unique($matches[1]);

        $this->assertGreaterThanOrEqual(8, count($files));
        foreach ($files as $file) {
            $path = public_path('images/landing/'.$file);
            $this->assertFileExists($path);
            $this->assertLessThan(200 * 1024, filesize($path), "{$file} pesa demasiado");
        }

        $this->assertStringNotContainsString('screenshot-1.jpg', $html); // capturas de la versión anterior
        $this->assertStringContainsString('Capturas de la aplicación real con datos de ejemplo', $html);
    }
}
