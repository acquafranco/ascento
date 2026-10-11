<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cookies: hoy solo hay necesarias, así que el aviso es informativo (sin un
 * "Aceptar" que no cambia nada). Si se configura una categoría opcional, el
 * aviso pide consentimiento y sus scripts quedan bloqueados hasta aceptarlo.
 */
class CookieNoticeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_pages_show_an_informative_notice_and_no_trackers(): void
    {
        foreach (['/', '/login', '/register'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-cookie-bar', false)
                ->assertSee('solo cookies necesarias', false)
                ->assertSee('Entendido')
                ->assertDontSee('data-ck="accept"', false)
                ->assertDontSee('googletagmanager', false)
                ->assertDontSee('google-analytics', false)
                ->assertDontSee('connect.facebook.net', false);
        }

        $this->get('/')->assertSee('Preferencias de cookies')->assertSee(route('legal.cookies'), false);
    }

    public function test_the_policy_describes_what_is_really_used_and_is_marked_as_draft(): void
    {
        $this->get(route('legal.cookies'))->assertOk()
            ->assertSee('Borrador pendiente de revisión legal')
            ->assertSee(config('session.cookie'))
            ->assertSee('XSRF-TOKEN')
            ->assertSee('No usamos cookies de publicidad ni de analítica', false)
            ->assertSee('ascento.cookies');
    }

    public function test_an_optional_category_turns_it_into_real_consent(): void
    {
        config(['cookies.optional' => ['analytics' => ['label' => 'Analítica', 'description' => 'Medir el uso de la página.']]]);

        $this->get('/')->assertOk()
            ->assertSee('data-ck="accept"', false)
            ->assertSee('data-ck="reject"', false)
            ->assertSee('data-ck-category="analytics"', false)
            ->assertSee('script[type="text/plain"][data-consent]', false); // solo se activan con consentimiento
    }

    public function test_work_screens_do_not_show_the_notice(): void
    {
        $a = $this->makeTenant();
        $this->actingAs($a['technician'])->get(route('dashboard', ['company' => $a['company']->slug]))->assertOk()->assertDontSee('data-cookie-bar', false);
    }
}
