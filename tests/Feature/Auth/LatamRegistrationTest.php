<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\CompanySettings;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Models\User;
use App\Services\PublicQr\PublicQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * Empresas de Latinoamérica: el registro pide el país, la identificación
 * fiscal de ese país (CUIT, RUT, RFC, RUC…) y su división principal; Argentina
 * sigue igual (CUIT con dígito verificador y la lista de provincias).
 */
class LatamRegistrationTest extends TestCase
{
    use InteractsWithTenants, PassesRegistrationChallenge, RefreshDatabase;

    private function register(array $overrides = [])
    {
        return $this->post('/register', [
            ...$this->registrationProof(),
            'company_name' => 'Ascensores Andinos',
            'name' => 'Valentina Rojas',
            'email' => 'valentina@andinos.test',
            'password' => 'Kiosco-Verde-8241',
            'password_confirmation' => 'Kiosco-Verde-8241',
            'phone' => '+56 9 1234 5678',
            'locality' => 'Santiago',
            'terms' => '1',
            ...$overrides,
        ]);
    }

    public function test_the_form_offers_the_countries_and_adapts_labels(): void
    {
        $this->withoutVite();
        $this->get('/register')->assertOk()
            ->assertSee('País')->assertSee('Chile')->assertSee('México')->assertSee('Uruguay')
            ->assertSee('data-register-country', false)->assertSee('CUIT');

        // Volviendo con errores desde Chile, el formulario muestra RUT y «Región».
        $this->withSession(['_old_input' => ['country' => 'CL']])->get('/register')->assertOk()->assertSee('RUT')->assertSee('Región');
    }

    public function test_a_company_from_another_country_registers_with_its_tax_id_and_region(): void
    {
        $this->register(['country' => 'CL', 'cuit' => '76.123.456-k', 'province' => 'Región Metropolitana'])->assertRedirect();

        $company = User::where('email', 'valentina@andinos.test')->sole()->company;
        $this->assertSame(['CL', '76.123.456-K', 'Región Metropolitana', 'RUT'], [$company->country, $company->cuit, $company->province, $company->taxIdLabel()]);

        // La misma identificación otra vez: no (una empresa = una cuenta), sin revelar de quién es.
        auth()->logout();
        $this->register(['country' => 'CL', 'cuit' => '76.123.456-K', 'province' => 'Valparaíso', 'email' => 'otra@andinos.test'])
            ->assertSessionHasErrors(['cuit' => RegisteredUserController::cuitTakenMessage()]);
    }

    public function test_an_eleven_digit_tax_id_from_peru_is_not_turned_into_a_cuit(): void
    {
        $this->register(['country' => 'PE', 'cuit' => '20123456789', 'province' => 'Lima', 'email' => 'peru@andinos.test'])->assertRedirect();

        $this->assertSame('20123456789', Company::where('country', 'PE')->sole()->cuit);
    }

    public function test_argentina_keeps_its_rules(): void
    {
        // CUIT inválido y provincia que no existe: no.
        $this->register(['country' => 'AR', 'cuit' => '30-71234567-2', 'province' => 'Región Metropolitana'])
            ->assertSessionHasErrors(['cuit', 'province']);

        // Sin país (formulario viejo): Argentina, como siempre.
        $this->register(['cuit' => '30-71234567-1', 'province' => 'Buenos Aires', 'locality' => 'Lomas de Zamora'])->assertRedirect();
        $company = User::where('email', 'valentina@andinos.test')->sole()->company;
        $this->assertSame(['AR', '30-71234567-1'], [$company->country, $company->cuit]);

        // Un identificador sin forma de tal, en otro país: no.
        auth()->logout();
        $this->register(['country' => 'MX', 'cuit' => 'hola', 'province' => 'Jalisco', 'email' => 'mx@andinos.test'])->assertSessionHasErrors('cuit');
        // Un país fuera de la lista: no.
        $this->register(['country' => 'US', 'cuit' => '12-3456789', 'province' => 'Texas', 'email' => 'us@andinos.test'])->assertSessionHasErrors('country');
    }

    public function test_company_settings_follow_the_country(): void
    {
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);

        Livewire::test(CompanySettings::class)
            ->set('data.country', 'MX')->set('data.cuit', 'abc123456t12')->set('data.province', 'Jalisco')
            ->set('data.bank_cbu', '012180001234567891')
            ->call('save')->assertHasNoErrors();

        $company = $a['company']->fresh();
        $this->assertSame(['MX', 'ABC123456T12', 'Jalisco', '012180001234567891', 'RFC'], [$company->country, $company->cuit, $company->province, $company->bank_cbu, $company->taxIdLabel()]);

        // De vuelta en Argentina: el CUIT se valida con su dígito verificador.
        Livewire::test(CompanySettings::class)->set('data.country', 'AR')->set('data.cuit', '30-71234567-2')->set('data.province', 'Córdoba')
            ->call('save')->assertHasErrors(['data.cuit']);
    }

    public function test_public_texts_point_to_the_local_emergency_number(): void
    {
        $this->withoutVite();
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'empresa');
        $this->actingInPanel($a['admin']);
        $page = app(PublicQrService::class)->generate($a['admin'], $a['building']);
        auth()->logout();

        // Argentina: el 911, como siempre.
        $this->get(route('public-qr.show', ['token' => $page->token]))->assertSee('llamá al 911');

        // Edificio en Uruguay: no se publica un número que no esté verificado.
        $a['building']->forceFill(['country' => 'UY'])->saveQuietly();
        $this->get(route('public-qr.show', ['token' => $page->token]))
            ->assertSee('llamá al número de emergencias de tu país')->assertDontSee('llamá al 911');
        $this->assertInstanceOf(BuildingPublicPage::class, $page);
    }

    public function test_legal_pages_describe_the_latin_american_scope(): void
    {
        $this->withoutVite();
        $this->get(route('legal.terms'))->assertOk()
            ->assertSee('otros países de Latinoamérica')
            ->assertSee('El servicio lo presta su titular desde la República Argentina')
            ->assertSee('no excluyen las normas de ese país que no se puedan dejar de lado por contrato')
            ->assertSee('la conversión de moneda');
        $this->get(route('legal.privacy'))->assertOk()
            ->assertSee('la autoridad de protección de datos de tu país')
            ->assertSee('Ley 25.326');
    }
}
