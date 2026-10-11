<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Contacto por WhatsApp: en las páginas públicas (inicio, ingreso y
 * registro), con el número correcto y sin guardar nada; nunca en las
 * pantallas de trabajo del técnico, del admin ni del portal.
 */
class WhatsAppContactTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_the_number_is_the_argentine_whatsapp_in_international_format(): void
    {
        $this->assertSame('5491173728421', config('app.whatsapp_contact')); // +54 9 11 7372-8421
    }

    public function test_landing_and_auth_pages_offer_the_contact_panel(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()
            ->assertSee('id="wa-dialog"', false)
            ->assertSee('data-whatsapp-open', false)
            ->assertSee('"5491173728421"', false)
            ->assertSee('Quiero solicitar una demostración')
            ->assertSee('El mensaje lo enviás vos desde WhatsApp')
            ->assertDontSee('action="/whatsapp', false); // no hay envío al servidor

        $this->get('/login')->assertOk()->assertSee('id="wa-dialog"', false)->assertSee('Escribinos por WhatsApp');
        $this->get('/register')->assertOk()->assertSee('id="wa-dialog"', false);
    }

    public function test_work_screens_and_the_portal_do_not_show_it(): void
    {
        $this->withoutVite();
        $a = $this->makeTenant();

        $this->get(route('portal.login'))->assertOk()->assertDontSee('id="wa-dialog"', false);
        $this->actingAs($a['technician'])->get(route('dashboard', ['company' => $a['company']->slug]))->assertOk()->assertDontSee('id="wa-dialog"', false);
        $this->actingInPanel($a['admin'])->get('/admin/dashboard')->assertOk()->assertDontSee('id="wa-dialog"', false);
    }
}
