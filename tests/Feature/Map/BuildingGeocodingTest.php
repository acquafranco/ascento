<?php

namespace Tests\Feature\Map;

use App\Jobs\GeocodeBuilding;
use App\Models\Building;
use App\Services\Geocoding\BuildingGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Geocodificación con Geoapify: qué se guarda, cuándo NO se guarda y
 * cuándo NO se llama a la API.
 */
class BuildingGeocodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.geoapify.api_key' => 'test-geoapify-key']);
        Http::preventStrayRequests();

        // Cola de respuestas de Geoapify: cada request consume una.
        Http::fake(['api.geoapify.com/*' => function () {
            $this->assertNotEmpty($this->geoapifyQueue, 'Llamada inesperada a Geoapify.');

            return array_shift($this->geoapifyQueue);
        }]);
    }

    private array $geoapifyQueue = [];

    private function geoResult(array $overrides = []): array
    {
        return array_replace_recursive([
            'lat' => -34.5627,
            'lon' => -58.4561,
            'result_type' => 'building',
            'housenumber' => '1234',
            'country_code' => 'ar',
            'rank' => ['confidence' => 0.95, 'confidence_city_level' => 1, 'match_type' => 'full_match'],
        ], $overrides);
    }

    private function fakeGeoapify(array ...$results): void
    {
        foreach ($results as $result) {
            $this->geoapifyQueue[] = Http::response(['results' => $result === [] ? [] : [$result]]);
        }
    }

    private function fakeGeoapifyFailure(int $status): void
    {
        $this->geoapifyQueue[] = Http::response(['message' => 'error'], $status);
    }

    /** Edificio creado sin disparar la geocodificación automática. */
    private function building(array $attributes = []): Building
    {
        // Sin key al crear: el modelo no despacha el job.
        config(['services.geoapify.api_key' => null]);

        $building = Building::factory()->create([
            'name' => 'Av. Cabildo',
            'address' => '1234',
            'locality' => 'Belgrano',
            'province' => 'CABA',
            ...$attributes,
        ]);

        config(['services.geoapify.api_key' => 'test-geoapify-key']);

        return $building->fresh();
    }

    private function geocode(Building $building, bool $force = false): string
    {
        return app(BuildingGeocoder::class)->geocode($building, $force);
    }

    public function test_a_reliable_result_is_stored_with_its_metadata(): void
    {
        $building = $this->building();
        $this->fakeGeoapify($this->geoResult());

        $this->assertSame(Building::GEO_GEOCODED, $this->geocode($building));

        $building->refresh();
        $this->assertEqualsWithDelta(-34.5627, $building->latitude, 0.000001);
        $this->assertEqualsWithDelta(-58.4561, $building->longitude, 0.000001);
        $this->assertSame(Building::GEO_GEOCODED, $building->geocoding_status);
        $this->assertEqualsWithDelta(0.95, $building->geocoding_confidence, 0.001);
        $this->assertSame('Av. Cabildo 1234, Buenos Aires, Belgrano, Ciudad Autónoma de Buenos Aires, Argentina', $building->geocoded_address);
        $this->assertNotNull($building->geocoded_at);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.geoapify.com/v1/geocode/search')
            && $request['street'] === 'Av. Cabildo'
            && $request['housenumber'] === '1234'
            && $request['city'] === 'Buenos Aires'
            && $request['filter'] === 'countrycode:ar'
            && $request['apiKey'] === 'test-geoapify-key');
    }

    public function test_street_level_results_are_not_stored(): void
    {
        $building = $this->building(['locality' => 'Belgrano', 'municipality' => null, 'province' => 'Córdoba']);
        $this->fakeGeoapify($this->geoResult(['result_type' => 'street', 'housenumber' => null]));

        $this->assertSame(Building::GEO_NEEDS_REVIEW, $this->geocode($building));

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
    }

    public function test_low_confidence_wrong_number_or_wrong_city_are_not_stored(): void
    {
        foreach ([
            $this->geoResult(['rank' => ['confidence' => 0.5]]),
            $this->geoResult(['housenumber' => '4321']),
            $this->geoResult(['rank' => ['confidence_city_level' => 0.2]]),
        ] as $doubtful) {
            $building = $this->building(['province' => 'Córdoba']);
            $this->fakeGeoapify($doubtful);

            $this->assertSame(Building::GEO_NEEDS_REVIEW, $this->geocode($building));
            $this->assertNull($building->fresh()->latitude);
        }
    }

    public function test_tries_the_municipality_when_the_locality_does_not_match(): void
    {
        $building = $this->building(['locality' => 'Benavídez', 'municipality' => 'Tigre', 'province' => 'Buenos Aires']);
        $this->fakeGeoapify([], $this->geoResult());

        $this->assertSame(Building::GEO_GEOCODED, $this->geocode($building));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['city'] === 'Tigre');
    }

    public function test_incomplete_addresses_never_call_geoapify(): void
    {
        $building = $this->building(['locality' => null, 'municipality' => null, 'neighborhood' => null, 'province' => null]);

        $this->assertSame(Building::GEO_NEEDS_REVIEW, $this->geocode($building));

        Http::assertNothingSent();
    }

    public function test_already_resolved_addresses_are_not_requested_again(): void
    {
        $building = $this->building();
        $this->fakeGeoapify($this->geoResult());
        $this->geocode($building);

        $this->assertSame(BuildingGeocoder::OUTCOME_SKIPPED, $this->geocode($building->fresh()));
        Http::assertSentCount(1);
    }

    public function test_an_address_that_failed_is_not_retried_until_it_changes(): void
    {
        $building = $this->building(['province' => 'Córdoba']);
        $this->fakeGeoapify([]);
        $this->geocode($building);

        $this->assertSame(BuildingGeocoder::OUTCOME_SKIPPED, $this->geocode($building->fresh()));
        Http::assertSentCount(1);
    }

    public function test_changing_the_address_clears_coordinates_and_requests_a_new_geocode(): void
    {
        $building = $this->building();
        $this->fakeGeoapify($this->geoResult());
        $this->geocode($building);

        Bus::fake([GeocodeBuilding::class]);
        $building->refresh()->update(['address' => '2000']);

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
        $this->assertSame(Building::GEO_PENDING, $building->geocoding_status);
        $this->assertTrue($building->isGeocodingStale());
        Bus::assertDispatched(GeocodeBuilding::class, fn ($job) => $job->buildingId === $building->id);
    }

    public function test_editing_other_fields_keeps_the_location(): void
    {
        $building = $this->building();
        $this->fakeGeoapify($this->geoResult());
        $this->geocode($building);

        Bus::fake([GeocodeBuilding::class]);
        $building->refresh()->update(['notes' => 'Portero: Juan', 'elevator_count' => 3, 'name' => ' Av.  Cabildo ']);

        $this->assertSame(Building::GEO_GEOCODED, $building->fresh()->geocoding_status);
        $this->assertNotNull($building->fresh()->latitude);
        Bus::assertNotDispatched(GeocodeBuilding::class);
    }

    public function test_creating_a_building_geocodes_it_after_the_response(): void
    {
        Bus::fake([GeocodeBuilding::class]);

        $building = Building::factory()->create(['name' => 'Mitre', 'address' => '100', 'locality' => 'Rosario']);

        Bus::assertDispatchedAfterResponse(GeocodeBuilding::class, fn ($job) => $job->buildingId === $building->id);
    }

    public function test_the_job_geocodes_the_building(): void
    {
        $building = $this->building();
        $this->fakeGeoapify($this->geoResult());

        GeocodeBuilding::dispatchSync($building->id);

        $this->assertSame(Building::GEO_GEOCODED, $building->fresh()->geocoding_status);
    }

    public function test_without_api_key_nothing_is_dispatched_or_called(): void
    {
        config(['services.geoapify.api_key' => null]);
        Bus::fake([GeocodeBuilding::class]);

        $building = Building::factory()->create();

        Bus::assertNotDispatched(GeocodeBuilding::class);
        Http::assertNothingSent();
        $this->assertSame(Building::GEO_PENDING, $building->fresh()->geocoding_status);
    }

    public function test_a_manual_location_is_never_overwritten_for_the_same_address(): void
    {
        $building = $this->building();
        $building->forceFill([
            'latitude' => -34.1,
            'longitude' => -58.1,
            'geocoding_status' => Building::GEO_MANUAL,
            'geocoded_address' => $building->geocodingAddress()->canonical(),
        ])->saveQuietly();

        $this->assertSame(BuildingGeocoder::OUTCOME_SKIPPED, $this->geocode($building->fresh(), force: true));

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(-34.1, $building->fresh()->latitude, 0.000001);
    }

    public function test_geoapify_errors_mark_for_retry_without_inventing_coordinates(): void
    {
        $building = $this->building();
        $this->fakeGeoapifyFailure(500);

        $this->assertSame(Building::GEO_ERROR, $this->geocode($building));
        $this->assertNull($building->fresh()->latitude);
    }

    public function test_an_outage_does_not_erase_a_valid_location(): void
    {
        $building = $this->building();
        $this->fakeGeoapify($this->geoResult());
        $this->geocode($building);

        $this->fakeGeoapifyFailure(503);
        $this->assertSame(Building::GEO_ERROR, $this->geocode($building->fresh(), force: true));

        $this->assertEqualsWithDelta(-34.5627, $building->fresh()->latitude, 0.000001);
        $this->assertSame(Building::GEO_GEOCODED, $building->fresh()->geocoding_status);
    }

    public function test_the_daily_limit_stops_calls(): void
    {
        config(['services.geoapify.daily_limit' => 1]);
        Cache::put('geoapify:geocode:'.now()->format('Y-m-d'), 1, now()->endOfDay());

        $this->assertSame(Building::GEO_ERROR, $this->geocode($this->building()));
        Http::assertNothingSent();
    }

    public function test_the_command_only_processes_what_needs_it(): void
    {
        $pending = $this->building();
        $error = $this->building(['name' => 'Corrientes']);
        $error->forceFill(['geocoding_status' => Building::GEO_ERROR])->saveQuietly();

        $done = $this->building(['name' => 'Santa Fe']);
        $done->forceFill([
            'latitude' => -34.5,
            'longitude' => -58.5,
            'geocoding_status' => Building::GEO_GEOCODED,
            'geocoded_address' => $done->geocodingAddress()->canonical(),
        ])->saveQuietly();

        $this->fakeGeoapify($this->geoResult(), $this->geoResult());

        $this->artisan('buildings:geocode')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(Building::GEO_GEOCODED, $pending->fresh()->geocoding_status);
        $this->assertSame(Building::GEO_GEOCODED, $error->fresh()->geocoding_status);
    }

    public function test_the_command_dry_run_does_not_call_geoapify(): void
    {
        $this->building();

        $this->artisan('buildings:geocode --dry-run')->assertSuccessful();

        Http::assertNothingSent();
    }
}
