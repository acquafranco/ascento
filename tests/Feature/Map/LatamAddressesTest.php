<?php

namespace Tests\Feature\Map;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Models\Building;
use App\Models\PlatformSettings;
use App\Services\Geocoding\AddressAutocomplete;
use App\Services\Geocoding\BuildingGeocoder;
use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Direcciones de Latinoamérica: país de la empresa (Argentina por defecto) y de
 * cada edificio; el buscador y el mapa buscan en ese país; el número respeta el
 * formato de cada lugar; lo cargado en Argentina no cambia.
 */
class LatamAddressesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api.geoapify.com/v1/geocode/autocomplete*' => Http::response(['results' => [[
                'street' => 'Calle 45', 'housenumber' => '12-34', 'city' => 'Bogotá', 'state' => 'Bogotá D.C.', 'country_code' => 'co',
                'lat' => 4.63, 'lon' => -74.07, 'result_type' => 'building', 'formatted' => 'Calle 45 12-34, Bogotá', 'rank' => ['confidence' => 1],
            ]]]),
            'api.geoapify.com/v1/geocode/search*' => Http::response(['results' => []]),
        ]);
    }

    private function building(array $attributes): Building
    {
        return Building::factory()->create(['company_id' => $this->a['company']->id, ...$attributes])->load('company');
    }

    public function test_existing_argentine_addresses_do_not_change(): void
    {
        $this->assertSame('AR', $this->a['company']->fresh()->country);
        $b = $this->building(['name' => 'Av. Cabildo', 'address' => '2040', 'locality' => 'CABA', 'province' => null, 'country' => null]);

        $address = $b->geocodingAddress();
        $this->assertSame('ar', $address->countryCode);
        $this->assertStringEndsWith(', Ciudad Autónoma de Buenos Aires, Argentina', $address->canonical());
        $this->assertSame('2040', $address->number);
    }

    public function test_a_building_in_another_country_uses_its_country_number_format_and_region(): void
    {
        $b = $this->building(['name' => 'Calle 45', 'address' => '12-34', 'locality' => 'Bogotá', 'province' => 'Cundinamarca', 'country' => 'CO']);
        $address = $b->geocodingAddress();

        $this->assertSame(['co', '12-34', 'Cundinamarca'], [$address->countryCode, $address->number, $address->state]);
        $this->assertSame('Calle 45 12-34, Bogotá, Cundinamarca, Colombia', $address->canonical());

        // «Capital» o «Buenos Aires» no se leen como CABA fuera de Argentina, y la
        // provincia de la empresa (de Argentina) no se le pega a un edificio de Uruguay.
        $uy = $this->building(['name' => 'Rambla', 'address' => '1234 B', 'locality' => 'Capital', 'province' => null, 'country' => 'UY']);
        $this->a['company']->forceFill(['province' => 'Buenos Aires'])->save();
        $uyAddress = $uy->fresh()->load('company')->geocodingAddress();
        $this->assertSame(['Capital'], $uyAddress->cities);
        $this->assertNull($uyAddress->state);
        $this->assertSame('1234 B', $uyAddress->number);

        // El mapa busca en ese país.
        app(BuildingGeocoder::class)->geocode($b->fresh(), force: true);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/geocode/search') && $r['filter'] === 'countrycode:co');
    }

    public function test_the_address_search_looks_in_the_chosen_country_and_keeps_the_local_number(): void
    {
        $this->actingAs($this->a['admin']);
        $options = app(AddressAutocomplete::class)->search('Calle 45 12-34', $this->a['company']->id, 'CO');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'autocomplete') && $r['filter'] === 'countrycode:co');

        $fields = app(AddressAutocomplete::class)->pick(array_key_first($options))['fields'];
        $this->assertSame(['Calle 45', '12-34', 'Bogotá', 'CO'], [$fields['name'], $fields['address'], $fields['locality'], $fields['country']]);

        // Sin país elegido, el de la empresa.
        $this->a['company']->forceFill(['country' => 'MX'])->save();
        app(AddressAutocomplete::class)->search('Reforma 222', $this->a['company']->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'autocomplete') && $r['text'] === 'Reforma 222' && $r['filter'] === 'countrycode:mx');
    }

    public function test_the_building_form_validates_the_number_by_country(): void
    {
        $this->actingInPanel($this->a['admin']);
        $base = ['client_id' => $this->a['building']->client_id, 'name' => 'Calle 45', 'locality' => 'Bogotá', 'equipment' => [['kind' => 'elevator', 'machine_type' => 'traction']]];

        // Por defecto, el país de la empresa.
        Livewire::test(CreateBuilding::class)->assertSet('data.country', 'AR');

        // En Argentina, la altura es un número (como siempre).
        Livewire::test(CreateBuilding::class)->fillForm($base + ['country' => 'AR', 'address' => '12-34'])->call('create')->assertHasFormErrors(['address']);

        // En Colombia, «12-34» es un número válido.
        Livewire::test(CreateBuilding::class)->fillForm($base + ['country' => 'CO', 'address' => '12-34', 'province' => 'Cundinamarca'])->call('create')->assertHasNoFormErrors();
        $b = Building::latest('id')->first();
        $this->assertSame(['CO', '12-34', 'Cundinamarca'], [$b->country, $b->address, $b->province]);

        // Un país fuera de la lista: no.
        Livewire::test(CreateBuilding::class)->fillForm($base + ['country' => 'US', 'address' => '100'])->call('create')->assertHasFormErrors(['country']);
    }

    public function test_the_company_chooses_its_country_and_region(): void
    {
        $this->actingInPanel($this->a['admin']);
        // Pasarse a otro país requiere que esté habilitado para registrarse (RegistrationCountriesTest).
        PlatformSettings::row()->forceFill(['international_registration_enabled' => true, 'registration_countries' => ['CL']])->save();

        Livewire::test(CompanySettings::class)->assertSet('data.country', 'AR')
            ->set('data.country', 'CL')->set('data.province', 'Región Metropolitana')->call('save')->assertHasNoErrors();
        $this->assertSame(['CL', 'Región Metropolitana'], [$this->a['company']->fresh()->country, $this->a['company']->fresh()->province]);

        $this->assertSame('Región', Countries::regionLabel('CL'));
        $this->assertSame('Estado', Countries::regionLabel('MX'));
        $this->assertSame('Argentina', Countries::name('zz')); // código desconocido: el país por defecto
    }
}
