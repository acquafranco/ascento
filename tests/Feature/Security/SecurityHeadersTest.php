<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\User;
use App\Support\LocalAvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_carry_security_headers(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security'); // solo por https

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
    }

    /**
     * Etapa 11: las páginas del panel (/admin) no pasan por el grupo «web»
     * (Filament arma su propia lista de middleware) y salían sin estas
     * cabeceras: el panel se podía enmarcar desde otro sitio (clickjacking).
     */
    public function test_panel_pages_carry_the_same_security_headers(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $owner = User::factory()->admin()->create(['company_id' => $company->id]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));

        $this->get('/admin/dashboard')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * Etapa 11: el avatar por defecto de Filament se pedía a ui-avatars.com
     * con el nombre del usuario en la URL, en cada pantalla del panel.
     */
    public function test_the_panel_avatar_never_sends_the_user_name_to_a_third_party(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $owner = User::factory()->admin()->create(['company_id' => $company->id, 'name' => 'Marina <b>Gómez</b>']);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));

        $html = $this->get('/admin/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('ui-avatars.com', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);

        $svg = base64_decode(substr((new LocalAvatarProvider)->get($owner), strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>MB<', $svg); // iniciales; el «<» inicial se descarta como signo
        $this->assertStringNotContainsString('<b>', $svg);
    }
}
