<?php

namespace Tests\Feature\Map;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proxy de mosaicos: la API key se usa del lado del servidor y no se
 * expone; solo admins autenticados pueden gastar créditos.
 */
class MapTileProxyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const API_KEY = 'super-secret-geoapify-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.geoapify.api_key' => self::API_KEY]);
        Http::preventStrayRequests();
    }

    private function fakeTile(): void
    {
        Http::fake(['maps.geoapify.com/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
    }

    public function test_admin_gets_the_tile_with_the_key_added_server_side(): void
    {
        $tenant = $this->makeTenant();
        $this->fakeTile();

        $response = $this->actingAs($tenant['admin'])->get('/map-tiles/12/1381/2468.png');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'immutable, max-age=604800, private');
        $this->assertSame('PNGDATA', $response->getContent());
        $this->assertStringNotContainsString(self::API_KEY, json_encode($response->headers->all()));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://maps.geoapify.com/v1/tile/osm-bright/12/1381/2468.png')
            && $request['apiKey'] === self::API_KEY);
    }

    public function test_guests_and_technicians_cannot_use_the_proxy(): void
    {
        $this->fakeTile();
        $this->get('/map-tiles/1/0/0.png')->assertRedirect();

        $this->actingAs($this->makeTenant()['technician'])->get('/map-tiles/1/0/0.png')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_out_of_range_tiles_are_rejected(): void
    {
        $this->fakeTile();
        $admin = $this->makeTenant()['admin'];

        $this->actingAs($admin)->get('/map-tiles/2/4/0.png')->assertNotFound();
        $this->actingAs($admin)->get('/map-tiles/21/0/0.png')->assertNotFound();
        $this->actingAs($admin)->get('/map-tiles/a/0/0.png')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_without_api_key_the_proxy_is_disabled(): void
    {
        $this->fakeTile();
        config(['services.geoapify.api_key' => null]);

        $this->actingAs($this->makeTenant()['admin'])->get('/map-tiles/1/0/0.png')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_upstream_errors_do_not_leak_details(): void
    {
        Http::fake(['maps.geoapify.com/*' => Http::response('Invalid apiKey '.self::API_KEY, 401)]);

        $response = $this->actingAs($this->makeTenant()['admin'])->get('/map-tiles/1/0/0.png');

        $response->assertStatus(502);
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    public function test_api_key_is_not_in_any_frontend_source(): void
    {
        foreach (['resources/js', 'resources/views'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                $this->assertStringNotContainsString('GEOAPIFY_API_KEY', file_get_contents($file), $file->getPathname());
                $this->assertStringNotContainsString('services.geoapify.api_key', file_get_contents($file), $file->getPathname());
            }
        }
    }
}
