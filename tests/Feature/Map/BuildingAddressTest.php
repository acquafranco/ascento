<?php

namespace Tests\Feature\Map;

use App\Models\Building;
use App\Models\Company;
use App\Support\Geocoding\BuildingAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cómo se arma la dirección que se envía a Geoapify a partir de lo que
 * realmente guarda el formulario (name = calle, address = número).
 */
class BuildingAddressTest extends TestCase
{
    use RefreshDatabase;

    private function address(array $attributes, ?string $companyProvince = null): BuildingAddress
    {
        $building = new Building($attributes);
        $building->setRelation('company', new Company(['province' => $companyProvince]));

        return BuildingAddress::fromBuilding($building);
    }

    public function test_street_is_name_and_number_is_address(): void
    {
        $address = $this->address([
            'name' => '  Av.  Cabildo ',
            'address' => '1234',
            'locality' => 'Belgrano',
            'province' => 'Córdoba',
        ]);

        $this->assertSame([[
            'street' => 'Av. Cabildo',
            'housenumber' => '1234',
            'city' => 'Belgrano',
            'state' => 'Córdoba',
        ]], $address->queries());
        $this->assertSame('Av. Cabildo 1234, Belgrano, Córdoba, Argentina', $address->canonical());
    }

    public function test_locality_first_then_municipality_as_second_attempt(): void
    {
        $address = $this->address([
            'name' => 'Italia',
            'address' => '500',
            'locality' => 'Benavídez',
            'municipality' => 'Tigre',
            'province' => 'Bs. As.',
        ]);

        $this->assertSame(['Benavídez', 'Tigre'], array_column($address->queries(), 'city'));
        $this->assertSame('Buenos Aires', $address->queries()[0]['state']);
    }

    #[DataProvider('cabaAliases')]
    public function test_caba_aliases_resolve_to_the_city_of_buenos_aires(array $attributes): void
    {
        $query = $this->address(['name' => 'Gorriti', 'address' => '4500', ...$attributes])->queries()[0];

        $this->assertSame('Buenos Aires', $query['city']);
        $this->assertSame('Ciudad Autónoma de Buenos Aires', $query['state']);
    }

    public static function cabaAliases(): array
    {
        return [
            'localidad CABA' => [['locality' => 'CABA']],
            'localidad C.A.B.A.' => [['locality' => 'C.A.B.A.']],
            'provincia Capital Federal + barrio' => [['province' => 'Capital Federal', 'neighborhood' => 'Palermo']],
        ];
    }

    public function test_company_province_is_the_default_state(): void
    {
        $query = $this->address(['name' => 'Mitre', 'address' => '10', 'locality' => 'Rosario'], 'Santa Fe')->queries()[0];

        $this->assertSame('Santa Fe', $query['state']);
    }

    public function test_neighborhood_is_only_a_last_resort_city(): void
    {
        $this->assertSame(
            ['Tigre'],
            array_column($this->address(['name' => 'X', 'address' => '1', 'municipality' => 'Tigre', 'neighborhood' => 'Nordelta'])->queries(), 'city')
        );

        $this->assertSame(
            ['Nordelta'],
            array_column($this->address(['name' => 'X', 'address' => '1', 'neighborhood' => 'Nordelta'])->queries(), 'city')
        );
    }

    public function test_number_is_extracted_from_messy_values(): void
    {
        $this->assertSame('1234', $this->address(['name' => 'Corrientes', 'address' => '1234 4°B', 'locality' => 'X'])->number);
        $this->assertSame('85', $this->address(['name' => 'Corrientes', 'address' => '0085', 'locality' => 'X'])->number);

        // Número pegado a la calle (registros viejos).
        $legacy = $this->address(['name' => 'Corrientes 1500', 'address' => '', 'locality' => 'X']);
        $this->assertSame('Corrientes', $legacy->street);
        $this->assertSame('1500', $legacy->number);
    }

    public function test_incomplete_addresses_are_not_geocodable(): void
    {
        $noCity = $this->address(['name' => 'San Martín', 'address' => '123']);
        $this->assertFalse($noCity->isGeocodable());
        $this->assertSame(['localidad'], $noCity->missingParts());
        $this->assertSame([], $noCity->queries());

        $noNumber = $this->address(['name' => 'San Martín', 'address' => 'S/N', 'locality' => 'Tigre']);
        $this->assertFalse($noNumber->isGeocodable());
        $this->assertSame(['número'], $noNumber->missingParts());
    }
}
