<?php

namespace Tests\Feature\Public;

use App\Services\PublicQr\PublicQrService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Páginas legales: se ven, están enlazadas entre sí, no tienen correos ni
 * enlaces rotos, no inventan los datos del titular, no prometen lo que
 * Ascento no hace (servicio de emergencias, integración con WhatsApp,
 * firma digital) y la fecha es la de la versión, no la de hoy.
 */
class LegalPagesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const PAGES = ['legal.terms', 'legal.privacy', 'legal.cookies', 'legal.data-deletion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_four_pages_render_link_each_other_and_use_the_configured_contact(): void
    {
        config(['legal.email' => 'legales@ascento.test']);

        foreach (self::PAGES as $page) {
            $html = $this->get(route($page))->assertOk()->getContent();

            foreach (self::PAGES as $other) {
                $this->assertStringContainsString(route($other), $html, "{$page} no enlaza a {$other}");
            }
            $this->assertStringContainsString('mailto:legales@ascento.test', $html);
            // Restos que había antes: correos ofuscados, marcadores y anclas a otra página.
            foreach (['email&#160;protected', 'email protected', '[EMAIL LEGAL]', 'href="#beneficios"', 'href="#planes"'] as $broken) {
                $this->assertStringNotContainsString($broken, $html, "{$page} contiene «{$broken}»");
            }
            // La fecha es la de la versión, no la del día en que se abre.
            $this->assertStringContainsString('Versión vigente desde el 23 de octubre de 2026', $html);
        }
    }

    public function test_owner_data_is_never_invented_and_the_draft_notice_depends_on_legal_review(): void
    {
        config(['legal.owner_name' => null, 'legal.owner_tax_id' => null, 'legal.reviewed' => false]);
        $this->get(route('legal.terms'))->assertSee('Razón social: pendiente de completar')->assertSee('CUIT: pendiente de completar')
            ->assertSee('Borrador pendiente de revisión legal');

        config(['legal.owner_name' => 'Ascento S.A.S.', 'legal.owner_tax_id' => '30-00000000-0', 'legal.owner_address' => 'Calle 1', 'legal.reviewed' => true]);
        $this->get(route('legal.terms'))->assertSee('Ascento S.A.S.')->assertSee('30-00000000-0')
            ->assertDontSee('pendiente de completar')->assertDontSee('Borrador pendiente de revisión legal');
    }

    public function test_the_terms_describe_what_ascento_is_not_and_the_real_limits_of_the_qr_report(): void
    {
        $this->get(route('legal.terms'))->assertOk()
            ->assertSee('No mantiene, repara ni inspecciona ascensores', false)
            ->assertSee('plataforma de gestión y comunicación', false)
            ->assertSee('Reportar un problema no es un servicio de emergencias')
            ->assertSee('Un reporte no es una solicitud de rescate atendida', false)
            ->assertSee('Emitir o registrar un aviso no garantiza que alguien lo haya recibido', false)
            ->assertSee('no garantizamos un servicio sin interrupciones', false)
            ->assertSee('no reemplaza los medios de comunicación y de emergencia', false)
            ->assertSee('según sus procedimientos y las obligaciones que le correspondan', false)
            ->assertSeeInOrder(['Envío:', 'Registro:', 'Intento de aviso:', 'Lectura:', 'Gestión:'], false)
            ->assertDontSee('Estoy atrapado en el ascensor», que cualquier persona puede usar', false)
            ->assertSee('911')
            ->assertSee('imagen del trazo dibujado en la pantalla', false)
            ->assertSee('no se aplican', false)
            ->assertDontSee('garantizamos la recuperación')
            ->assertDontSee('integración con WhatsApp Business');
    }

    public function test_privacy_and_deletion_reflect_the_real_processing(): void
    {
        $this->get(route('legal.privacy'))->assertOk()
            ->assertSee('la dirección IP no se guarda', false)
            ->assertSee('sin metadatos, incluida la ubicación GPS', false)
            ->assertSee('a las 12 horas')
            ->assertSee('durante 7 días')
            ->assertSee('Agencia de Acceso a la Información Pública');

        $this->get(route('legal.data-deletion'))->assertOk()
            ->assertSee('La eliminación es definitiva')
            ->assertSee('Hoy no hay un botón para que cada persona borre su propia cuenta', false)
            ->assertSee('copias de seguridad');
    }

    public function test_registration_and_the_home_page_link_to_the_legal_pages(): void
    {
        $this->get('/register')->assertOk()->assertSee(route('legal.terms'), false)->assertSee(route('legal.privacy'), false);
        $home = $this->get('/')->assertOk()->getContent();
        foreach (['legal.terms', 'legal.privacy', 'legal.cookies'] as $page) {
            $this->assertStringContainsString(route($page), $home, "La portada no enlaza a {$page}");
        }
    }

    public function test_the_login_the_public_qr_and_the_portal_link_to_the_legal_pages(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-legal-links', false)->assertSee(route('legal.privacy'), false);

        // QR público: quien pide ayuda puede leer los términos (límites del pedido) y la privacidad.
        $tenant = $this->makeTenant();
        $this->giveCompanyPlan($tenant['company'], 'empresa');
        $this->actingAs($tenant['admin']);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        $page = app(PublicQrService::class)->generate($tenant['admin'], $tenant['building']);
        auth()->logout();
        $this->get('/qr/'.$page->token)->assertOk()->assertSee('data-legal-links', false)
            ->assertSee(route('legal.terms'), false)->assertSee(route('legal.privacy'), false);
    }
}
