<?php

namespace Tests\Feature\Technicians;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * PWA en iPhone: con viewport-fit=cover y barra de estado "black-translucent"
 * la página se dibuja debajo de la barra de estado. La barra superior y la de
 * progreso tienen que reservar env(safe-area-inset-top); la inferior, el
 * inset de abajo. (La validación real necesita un iPhone: ver docs.)
 */
class PwaSafeAreaTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_the_technician_app_reserves_the_ios_safe_areas(): void
    {
        $this->withoutVite();
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])->get('/'.$a['company']->slug.'/dashboard')->assertOk()
            ->assertSee('viewport-fit=cover', false)
            ->assertSee('black-translucent', false)
            ->assertSee('t-nav-top', false)
            ->assertSee('padding-bottom: env(safe-area-inset-bottom)', false)
            // El aviso emergente queda por encima de la barra inferior también en el iPhone.
            ->assertSee('bottom: calc(96px + env(safe-area-inset-bottom, 0px))', false);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.t-nav-top\s*\{[^}]*padding-top:\s*env\(safe-area-inset-top/', $css);
        $this->assertMatchesRegularExpression('/#t-progress\s*\{[^}]*inset:\s*env\(safe-area-inset-top/', $css);
    }
}
