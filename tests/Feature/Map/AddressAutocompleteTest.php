<?php

namespace Tests\Feature\Map;

use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Jobs\GeocodeBuilding;
use App\Models\Building;
use App\Services\Geocoding\AddressAutocomplete;
use App\Services\Geocoding\BuildingGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Buscador de direcciones del alta de edificios: completa los campos y deja
 * el edificio ubicado en el mapa al guardar, con coordenadas del servidor.
 */
class AddressAutocompleteTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::preventStrayRequests();

        Http::fake(['api.geoapify.com/v1/geocode/autocomplete*' => Http::response(['results' => [
            [
                'street' => 'Avenida Cabildo', 'housenumber' => '2040', 'suburb' => 'Belgrano',
                'city' => 'Buenos Aires', 'county' => 'Comuna 13', 'state' => 'Ciudad Autónoma de Buenos Aires',
                'lat' => -34.5627, 'lon' => -58.4561, 'result_type' => 'building',
                'formatted' => 'Avenida Cabildo 2040, Belgrano, Buenos Aires', 'rank' => ['confidence' => 1],
            ],
            [
                'street' => 'Italia', 'housenumber' => '500', 'city' => 'Benavídez',
                'county' => 'Partido de Tigre', 'state' => 'Buenos Aires',
                'lat' => -34.41, 'lon' => -58.68, 'result_type' => 'building',
                'formatted' => 'Italia 500, Benavídez, Buenos Aires',
            ],
            ['city' => 'Córdoba', 'state' => 'Córdoba', 'result_type' => 'city', 'formatted' => 'Córdoba'],
        ]])]);
    }

    private function searchAs(string $text): array
    {
        return app(AddressAutocomplete::class)->search($text, $this->a['company']->id);
    }

    public function test_suggestions_are_addresses_only_and_the_key_stays_on_the_server(): void
    {
        $this->actingAs($this->a['admin']);

        $options = $this->searchAs('Cabildo 2040');

        $this->assertSame(['Avenida Cabildo 2040, Belgrano, Buenos Aires', 'Italia 500, Benavídez, Buenos Aires'], array_values($options));
        foreach (array_keys($options) as $token) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $token);
        }

        Http::assertSent(fn (Request $request) => $request['text'] === 'Cabildo 2040' && $request['filter'] === 'countrycode:ar');
    }

    public function test_short_text_does_not_spend_credits_and_results_are_cached(): void
    {
        $this->actingAs($this->a['admin']);

        $this->assertSame([], $this->searchAs('Cab'));
        $this->searchAs('Cabildo 2040');
        $this->searchAs('Cabildo 2040');

        Http::assertSentCount(1);
    }

    public function test_picking_fills_the_form_with_argentine_fields(): void
    {
        $this->actingAs($this->a['admin']);
        $tokens = array_keys($this->searchAs('Italia 500'));

        $fields = app(AddressAutocomplete::class)->pick($tokens[1])['fields'];

        $this->assertSame([
            'name' => 'Italia',
            'address' => '500',
            'locality' => 'Benavídez',
            'municipality' => 'Tigre',          // "Partido de Tigre" → "Tigre"
            'neighborhood' => null,
            'province' => 'Buenos Aires',
        ], $fields);

        // Las comunas de CABA no son municipios.
        $this->assertNull(app(AddressAutocomplete::class)->pick($tokens[0])['fields']['municipality']);
    }

    public function test_creating_a_building_from_a_suggestion_puts_it_on_the_map_right_away(): void
    {
        $this->actingInPanel($this->a['admin']);

        $page = Livewire::test(CreateBuilding::class);
        $token = array_key_first($this->searchAs('Cabildo 2040'));

        $page->fillForm(['client_id' => $this->a['building']->client_id])
            ->set('data.address_search', $token)
            ->assertSet('data.name', 'Avenida Cabildo')
            ->assertSet('data.address', '2040')
            ->assertSet('data.province', 'Ciudad Autónoma de Buenos Aires')
            ->call('create')
            ->assertHasNoFormErrors();

        $building = Building::latest('id')->first();
        $this->assertSame(Building::GEO_GEOCODED, $building->geocoding_status);
        $this->assertEqualsWithDelta(-34.5627, $building->latitude, 0.00001);
        $this->assertSame($building->geocodingAddress()->canonical(), $building->geocoded_address);

        // No hace falta otra consulta a Geoapify: el job la saltea.
        GeocodeBuilding::dispatchSync($building->id);
        Http::assertSentCount(1);
    }

    public function test_if_the_admin_edits_the_address_after_picking_the_suggestion_is_not_used(): void
    {
        $this->actingInPanel($this->a['admin']);
        $page = Livewire::test(CreateBuilding::class);
        $token = array_key_first($this->searchAs('Cabildo 2040'));

        $page->fillForm(['client_id' => $this->a['building']->client_id])
            ->set('data.address_search', $token)
            ->set('data.address', '2100'); // cambió la altura a mano

        Bus::fake([GeocodeBuilding::class]);
        $page->call('create')
            ->assertHasNoFormErrors();

        // No usa las coordenadas de la sugerencia: va por la geocodificación
        // normal con la dirección nueva (acá Geoapify no la encuentra).
        Http::fake(['api.geoapify.com/v1/geocode/search*' => Http::response(['results' => []])]);
        (new GeocodeBuilding(Building::latest('id')->value('id')))->handle(app(BuildingGeocoder::class));

        $building = Building::latest('id')->first();
        $this->assertSame(Building::GEO_NEEDS_REVIEW, $building->geocoding_status);
        $this->assertNull($building->latitude);
        Http::assertSent(fn (Request $request) => ($request['housenumber'] ?? null) === '2100');
    }

    public function test_a_token_from_another_user_or_invented_is_useless(): void
    {
        $this->actingAs($this->a['admin']);
        $token = array_key_first($this->searchAs('Cabildo 2040'));

        $other = $this->makeTenant();
        $this->actingAs($other['admin']);

        $this->assertNull(app(AddressAutocomplete::class)->pick($token));
        $this->assertNull(app(AddressAutocomplete::class)->pick('x'));
        $this->assertNull(app(AddressAutocomplete::class)->pick('../../etc/passwd'));
        $this->assertFalse(app(AddressAutocomplete::class)->applyToBuilding($other['building'], $token));
        $this->assertNull($other['building']->fresh()->latitude);
    }

    public function test_editing_with_a_new_suggestion_relocates_the_building(): void
    {
        $building = $this->a['building'];
        $this->actingInPanel($this->a['admin']);

        $page = Livewire::test(EditBuilding::class, ['record' => $building->getRouteKey()]);
        $token = array_keys($this->searchAs('Italia 500'))[1];

        $page->set('data.address_search', $token)->call('save')->assertHasNoFormErrors();

        $building->refresh();
        $this->assertSame('Italia', $building->name);
        $this->assertSame(Building::GEO_GEOCODED, $building->geocoding_status);
        $this->assertEqualsWithDelta(-34.41, $building->latitude, 0.00001);
    }

    public function test_without_api_key_the_search_field_is_hidden(): void
    {
        config(['services.geoapify.api_key' => null]);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateBuilding::class)->assertFormFieldIsHidden('address_search');
    }
}
