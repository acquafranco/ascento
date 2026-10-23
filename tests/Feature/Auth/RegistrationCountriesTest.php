<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\PlatformSettingsPage;
use App\Models\Company;
use App\Models\Marketplace\Supplier;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSettings;
use App\Models\User;
use App\Support\RegistrationCountries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Registro por país (SuperAdmin → Configuración de la plataforma). Por
 * defecto solo Argentina, sin selector y validado en el servidor; con el
 * switch internacional, solo los países habilitados uno por uno. Nunca
 * cambia cuentas existentes.
 */
class RegistrationCountriesTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, PassesRegistrationChallenge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function registerCompany(array $overrides = [])
    {
        return $this->post('/register', [...$this->registrationProof(),
            'company_name' => 'Ascensores Andinos', 'name' => 'Valentina Rojas', 'email' => 'valentina@andinos.test',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', 'phone' => '11 4567-8900',
            'cuit' => '30-71234567-1', 'province' => 'Buenos Aires', 'locality' => 'Lomas de Zamora', 'terms' => '1', ...$overrides]);
    }

    private function registerSupplier(array $overrides = [])
    {
        return $this->post('/proveedores/registro', [...$this->registrationProof(),
            'supplier_name' => 'Repuestos del Plata', 'tax_id' => '30-71234567-1', 'phone' => '11 4567-8900',
            'coverage' => 'CABA y GBA', 'categories' => ['doors'], 'name' => 'Marta Gómez', 'email' => 'ventas@plata.test',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', 'terms' => '1', ...$overrides]);
    }

    private function settings(bool $international, array $countries = []): void
    {
        PlatformSettings::row()->forceFill(['international_registration_enabled' => $international, 'registration_countries' => $countries])->save();
    }

    public function test_by_default_only_argentina_without_a_country_selector(): void
    {
        $this->assertFalse(RegistrationCountries::internationalEnabled());
        $this->assertSame(['AR'], RegistrationCountries::enabled());

        $this->get('/register')->assertOk()->assertDontSee('<select id="country"', false)->assertSee('data-register-home-only', false)
            ->assertSee('Por ahora el registro está disponible para empresas de Argentina.')->assertDontSee('Chile');
        $this->get('/proveedores/registro')->assertOk()->assertDontSee('<select id="country"', false)->assertSee('data-supplier-home-only', false);
    }

    public function test_by_default_a_tampered_request_from_another_country_is_rejected(): void
    {
        // Empresa: otro país enviado a mano (aunque su identificación sea válida allá) se rechaza.
        $this->registerCompany(['country' => 'CL', 'cuit' => '76.123.456-K', 'province' => 'Región Metropolitana'])
            ->assertSessionHasErrors(['country' => RegistrationCountries::rejectedMessage()]);
        $this->assertSame(0, Company::count());

        // Proveedor: lo mismo.
        $this->registerSupplier(['country' => 'MX', 'tax_id' => 'ABC123456T12'])->assertSessionHasErrors('country');
        $this->assertSame(0, Supplier::count());

        // Sin país (formulario sin selector): Argentina, asignada en el servidor.
        $this->registerCompany()->assertRedirect();
        $this->assertSame('AR', User::where('email', 'valentina@andinos.test')->sole()->company->country);
        auth()->logout();
        $this->registerSupplier()->assertRedirect();
        $this->assertSame(['AR', 'ARS'], [Supplier::sole()->country, Supplier::sole()->currency]);
    }

    public function test_turning_the_switch_on_without_countries_adds_no_option(): void
    {
        $this->settings(true, []);

        $this->assertSame(['AR'], RegistrationCountries::enabled());
        $this->assertFalse(RegistrationCountries::showsSelector());
        $this->get('/register')->assertDontSee('<select id="country"', false);
        $this->registerCompany(['country' => 'UY', 'cuit' => '211234560018', 'province' => 'Montevideo'])->assertSessionHasErrors('country');
    }

    public function test_with_the_switch_on_only_the_enabled_countries_are_offered_and_accepted(): void
    {
        $this->settings(true, ['CL']);

        $html = $this->get('/register')->assertOk()->assertSee('data-register-country', false)->getContent();
        preg_match_all('#<option value="([A-Z]{2})"#', $html, $m);
        $this->assertSame(['AR', 'CL'], $m[1]);
        $this->assertStringContainsString('no implica que el pago de la suscripción o la facturación estén disponibles', $html);
        $this->get('/proveedores/registro')->assertSee('data-supplier-country', false)->assertSee('Chile')->assertDontSee('México');

        // Un país habilitado: sí.
        $this->registerCompany(['country' => 'CL', 'cuit' => '76.123.456-k', 'province' => 'Región Metropolitana'])->assertRedirect();
        $this->assertSame('CL', User::where('email', 'valentina@andinos.test')->sole()->company->country);
        auth()->logout();

        // Uno no habilitado, ni empresa ni proveedor.
        $this->registerCompany(['country' => 'MX', 'cuit' => 'ABC123456T12', 'province' => 'Jalisco', 'email' => 'mx@andinos.test'])->assertSessionHasErrors('country');
        $this->registerSupplier(['country' => 'MX', 'tax_id' => 'ABC123456T12'])->assertSessionHasErrors('country');
        $this->registerSupplier(['country' => 'CL', 'tax_id' => '76.123.456-K'])->assertRedirect();
        $this->assertSame(['CL', 'CLP'], [Supplier::sole()->country, Supplier::sole()->currency]);

        // Con el switch apagado, la selección se conserva pero no vale.
        $this->settings(false, ['CL']);
        $this->assertSame(['AR'], RegistrationCountries::enabled());
        $this->assertSame(['CL'], RegistrationCountries::configuredExtra());
    }

    public function test_changing_the_setting_never_touches_existing_accounts(): void
    {
        $a = $this->makeTenant();
        $a['company']->forceFill(['country' => 'MX', 'cuit' => 'ABC123456T12'])->save();
        [$supplier] = $this->makeSupplier();
        $supplier->forceFill(['country' => 'PE', 'currency' => 'PEN'])->save();
        auth()->logout();

        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);
        Livewire::test(PlatformSettingsPage::class)->set('data.international_registration_enabled', true)->set('data.registration_countries', ['CL'])->call('save')->assertHasNoErrors();
        Livewire::test(PlatformSettingsPage::class)->set('data.international_registration_enabled', false)->call('save');

        $this->assertSame(['MX', 'ABC123456T12'], [$a['company']->fresh()->country, $a['company']->fresh()->cuit]);
        $this->assertSame(['PE', 'PEN', Supplier::APPROVED], [$supplier->fresh()->country, $supplier->fresh()->currency, $supplier->fresh()->status]);
        $this->assertFalse((bool) $a['admin']->fresh()->trashed());
    }

    public function test_an_existing_company_keeps_its_country_but_can_only_move_to_enabled_ones(): void
    {
        $a = $this->makeTenant();
        $a['company']->forceFill(['country' => 'MX', 'cuit' => 'ABC123456T12', 'province' => 'Jalisco'])->save();
        $this->actingInPanel($a['admin']);

        // Sigue en México aunque hoy solo se registre Argentina.
        Livewire::test(CompanySettings::class)->assertSet('data.country', 'MX')->set('data.city', 'Guadalajara')->call('save')->assertHasNoErrors();
        $this->assertSame('MX', $a['company']->fresh()->country);

        // Pero no puede pasar a un país no habilitado.
        Livewire::test(CompanySettings::class)->set('data.country', 'CL')->set('data.cuit', '76.123.456-K')->set('data.province', 'Región Metropolitana')
            ->call('save')->assertHasErrors(['data.country']);
        $this->assertSame('MX', $a['company']->fresh()->country);
    }

    public function test_only_the_super_admin_sees_and_changes_the_switch_and_it_is_audited(): void
    {
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);
        $this->get(PlatformSettingsPage::getUrl())->assertForbidden();
        Livewire::test(PlatformSettingsPage::class)->assertForbidden();
        auth()->logout();

        [, $supplierUser] = $this->makeSupplier();
        // Un proveedor no entra al panel (lo saca a su área).
        $this->assertNotSame(200, $this->actingAs($supplierUser)->get(PlatformSettingsPage::getUrl())->status());
        $this->assertFalse(RegistrationCountries::internationalEnabled());
        auth()->logout();

        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);
        $this->get(PlatformSettingsPage::getUrl())->assertOk()->assertSee('Permitir registros de otros países de Latinoamérica')
            ->assertSee('No modifica las cuentas existentes ni activa automáticamente los pagos internacionales.');
        // Un código que no está en la lista (o Argentina, que siempre está): se rechaza y no se guarda nada.
        Livewire::test(PlatformSettingsPage::class)->set('data.international_registration_enabled', true)
            ->set('data.registration_countries', ['UY', 'US'])->call('save')->assertHasErrors();
        $this->assertFalse(RegistrationCountries::internationalEnabled());
        $this->assertSame(['UY'], RegistrationCountries::clean(['UY', 'US', 'AR', 'uy']));

        Livewire::test(PlatformSettingsPage::class)->set('data.international_registration_enabled', true)
            ->set('data.registration_countries', ['UY'])->call('save')->assertHasNoErrors();
        $this->assertSame(['UY'], PlatformSettings::current()->registration_countries);
        $this->assertSame(['AR', 'UY'], RegistrationCountries::enabled());
        $this->assertSame('platform.registration.updated', PlatformAuditLog::latest('id')->value('action'));
    }
}
