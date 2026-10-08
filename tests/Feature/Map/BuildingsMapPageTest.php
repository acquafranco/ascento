<?php

namespace Tests\Feature\Map;

use App\Filament\Pages\BuildingsMap;
use App\Jobs\GeocodePendingBuildings;
use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Mapa de edificios: cada empresa ve SOLO sus edificios, abrir el mapa no
 * consulta a Geoapify y la API key nunca llega al navegador.
 */
class BuildingsMapPageTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const API_KEY = 'super-secret-geoapify-key';

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        $this->locate($this->a['building'], 'Calle Empresa A', -34.60, -58.38);
        $this->locate($this->b['building'], 'Calle Empresa B', -31.42, -64.18);

        config(['services.geoapify.api_key' => self::API_KEY]);
    }

    /** Crea edificios sin que el modelo dispare la geocodificación automática. */
    private function withoutAutoGeocoding(callable $callback): mixed
    {
        config(['services.geoapify.api_key' => null]);

        try {
            return $callback();
        } finally {
            config(['services.geoapify.api_key' => self::API_KEY]);
        }
    }

    private function locate(Building $building, string $street, float $lat, float $lng): void
    {
        $building->forceFill([
            'name' => $street,
            'latitude' => $lat,
            'longitude' => $lng,
            'geocoding_status' => Building::GEO_GEOCODED,
        ])->saveQuietly();
    }

    public function test_admin_only_sees_buildings_of_their_company(): void
    {
        Building::factory()->create(['company_id' => $this->b['company']->id, 'name' => 'Pendiente Empresa B']);

        $this->actingInPanel($this->a['admin'])
            ->get(BuildingsMap::getUrl())
            ->assertOk()
            ->assertSee('Calle Empresa A')
            ->assertDontSee('Calle Empresa B')
            ->assertDontSee('Pendiente Empresa B');

        $markers = Livewire::test(BuildingsMap::class)->instance()->getMarkers();

        $this->assertSame([$this->a['building']->id], array_column($markers, 'id'));
    }

    public function test_marker_has_client_and_basic_info(): void
    {
        $this->actingInPanel($this->a['admin']);

        $marker = Livewire::test(BuildingsMap::class)->instance()->getMarkers()[0];

        $this->assertSame($this->a['building']->client->name, $marker['client']);
        $this->assertSame(-34.6, $marker['lat']);
        $this->assertArrayHasKey('elevators', $marker);
    }

    public function test_buildings_without_coordinates_are_listed_not_drawn(): void
    {
        $missing = Building::factory()->create([
            'company_id' => $this->a['company']->id,
            'name' => 'Sin Localidad',
            'locality' => null,
        ]);

        $this->actingInPanel($this->a['admin']);
        $page = Livewire::test(BuildingsMap::class);

        $this->assertNotContains($missing->id, array_column($page->instance()->getMarkers(), 'id'));

        $unlocated = $page->instance()->unlocated->firstWhere('id', $missing->id);
        $this->assertSame('Falta localidad en la dirección', $unlocated['reason']);

        $page->assertSee('Sin Localidad')->assertSee('Marcar en el mapa');
    }

    public function test_opening_the_map_never_calls_geoapify(): void
    {
        $this->withoutAutoGeocoding(fn () => Building::factory()->count(3)->create(['company_id' => $this->a['company']->id]));
        Http::fake();

        $this->actingInPanel($this->a['admin'])->get(BuildingsMap::getUrl())->assertOk();

        Http::assertNothingSent();
    }

    public function test_secret_api_key_never_reaches_the_browser(): void
    {
        // Sin key de mapa: mosaicos de OpenStreetMap, ninguna key en la página.
        $response = $this->actingInPanel($this->a['admin'])->get(BuildingsMap::getUrl());

        $response->assertOk()
            ->assertDontSee(self::API_KEY)
            ->assertSee('tile.openstreetmap.org', false);

        $this->assertStringNotContainsString(self::API_KEY, json_encode(Livewire::test(BuildingsMap::class)->snapshot));
    }

    public function test_tiles_come_straight_from_the_cdn_with_the_public_map_key(): void
    {
        config(['services.geoapify.map_key' => 'public-map-key-restringida']);

        $this->actingInPanel($this->a['admin'])
            ->get(BuildingsMap::getUrl())
            ->assertOk()
            ->assertSee('maps.geoapify.com', false)
            ->assertSee('public-map-key-restringida')
            ->assertDontSee(self::API_KEY);
    }

    public function test_admin_can_place_a_building_manually(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(BuildingsMap::class)
            ->call('placeBuilding', $this->a['building']->id, -34.5, -58.4)
            ->assertReturned(fn ($marker) => $marker['id'] === $this->a['building']->id && $marker['manual'] === true);

        $building = $this->a['building']->fresh();
        $this->assertSame(Building::GEO_MANUAL, $building->geocoding_status);
        $this->assertEqualsWithDelta(-34.5, $building->latitude, 0.000001);
        $this->assertSame($building->geocodingAddress()->canonical(), $building->geocoded_address);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(BuildingsMap::class)
            ->call('placeBuilding', $this->a['building']->id, 'abc', 500)
            ->assertReturned(null);

        $this->assertEqualsWithDelta(-34.60, $this->a['building']->fresh()->latitude, 0.000001);
    }

    public function test_admin_cannot_move_a_building_of_another_company(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(BuildingsMap::class)
            ->call('placeBuilding', $this->b['building']->id, 0, 0)
            ->assertNotFound();

        $building = $this->b['building']->fresh();
        $this->assertEqualsWithDelta(-31.42, $building->latitude, 0.000001);
        $this->assertSame(Building::GEO_GEOCODED, $building->geocoding_status);
    }

    public function test_opening_the_map_locates_pending_buildings_of_this_company_in_the_background(): void
    {
        [$ownPending, $otherPending] = $this->withoutAutoGeocoding(fn () => [
            Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Mitre', 'address' => '1234', 'locality' => 'Rosario']),
            Building::factory()->create(['company_id' => $this->b['company']->id, 'name' => 'Mitre', 'address' => '1234', 'locality' => 'Rosario']),
        ]);

        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [[
            'lat' => -32.9, 'lon' => -60.6, 'result_type' => 'building', 'housenumber' => '1234',
            'rank' => ['confidence' => 0.97, 'confidence_city_level' => 1],
        ]]])]);

        // La página se arma sin esperar a Geoapify (avisa que está ubicando)
        // y DESPUÉS de responder ubica solo los de SU empresa.
        $this->actingInPanel($this->a['admin'])->get(BuildingsMap::getUrl())->assertOk()->assertSee('Ubicando edificios');

        $this->assertSame(Building::GEO_GEOCODED, $ownPending->fresh()->geocoding_status);
        $this->assertSame(Building::GEO_PENDING, $otherPending->fresh()->geocoding_status);
        Http::assertSentCount(1);
        $this->assertFalse(GeocodePendingBuildings::isRunning($this->a['company']->id));
    }

    public function test_only_one_background_batch_per_company_at_a_time(): void
    {
        $this->withoutAutoGeocoding(fn () => Building::factory()->create(['company_id' => $this->a['company']->id, 'locality' => 'Rosario']));
        Bus::fake([GeocodePendingBuildings::class]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(BuildingsMap::class);
        Livewire::test(BuildingsMap::class);

        Bus::assertDispatchedTimes(GeocodePendingBuildings::class, 1);
    }

    public function test_new_markers_reach_the_open_map_without_reloading(): void
    {
        $this->actingInPanel($this->a['admin']);
        $page = Livewire::test(BuildingsMap::class);

        $this->travel(2)->seconds();

        // Se crean como lo haría el job en segundo plano (sin sesión): así el
        // de la empresa B queda realmente en B.
        auth()->logout();
        [$new, $theirs] = $this->withoutAutoGeocoding(fn () => [
            Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Nuevo Punto']),
            Building::factory()->create(['company_id' => $this->b['company']->id]),
        ]);
        foreach ([$new, $theirs] as $building) {
            $building->forceFill(['latitude' => -34.7, 'longitude' => -58.5, 'geocoding_status' => Building::GEO_GEOCODED, 'geocoded_at' => now()])->saveQuietly();
        }
        $this->assertSame($this->b['company']->id, (int) $theirs->fresh()->company_id);
        $this->actingInPanel($this->a['admin']);

        $page->call('pollNewMarkers');
        $page->assertDispatched('buildings-map-markers', fn ($name, $params) => array_column($params['markers'], 'id') === [$new->id]);
    }

    public function test_the_unlocated_list_is_capped(): void
    {
        $this->withoutAutoGeocoding(fn () => Building::factory()->count(BuildingsMap::LIST_LIMIT + 5)->create(['company_id' => $this->a['company']->id, 'locality' => null]));

        $this->actingInPanel($this->a['admin']);
        $page = Livewire::test(BuildingsMap::class);

        $this->assertCount(BuildingsMap::LIST_LIMIT, $page->instance()->unlocated);
        $page->assertSee('Edificios sin ubicar ('.(BuildingsMap::LIST_LIMIT + 5).')')->assertSee('Y 5 más');
    }

    public function test_technicians_cannot_open_the_map(): void
    {
        $this->actingInPanel($this->a['technician'])
            ->get(BuildingsMap::getUrl())
            ->assertRedirect(route('dashboard', ['company' => $this->a['company']->slug]))
            ->assertDontSee('Calle Empresa A');
    }

    public function test_super_admin_sees_only_the_selected_company(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);

        $this->assertSame([], Livewire::test(BuildingsMap::class)->instance()->getMarkers());

        session(['selected_company_id' => $this->b['company']->id]);

        $this->assertSame(
            [$this->b['building']->id],
            array_column(Livewire::test(BuildingsMap::class)->instance()->getMarkers(), 'id')
        );
    }

    public function test_company_without_access_cannot_open_the_map(): void
    {
        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $this->actingInPanel($this->a['admin'])
            ->get(BuildingsMap::getUrl())
            ->assertRedirect('/admin/subscription');
    }
}
