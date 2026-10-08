<?php

namespace Tests\Feature\Map;

use App\Filament\Pages\BuildingsMap;
use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Models\Building;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Color de cada edificio en el mapa (paleta fija).
 */
class BuildingColorTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);
    }

    private function locate(Building $building, ?string $color): void
    {
        $building->forceFill(['latitude' => -34.6, 'longitude' => -58.4, 'geocoding_status' => Building::GEO_GEOCODED, 'map_color' => $color])->saveQuietly();
    }

    public function test_the_color_is_chosen_when_creating_and_editing_a_building(): void
    {
        Livewire::test(CreateBuilding::class)
            ->fillForm([
                'client_id' => $this->a['building']->client_id,
                'name' => 'Mitre',
                'address' => '100',
                'map_color' => 'verde',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $building = Building::latest('id')->first();
        $this->assertSame('verde', $building->map_color);

        Livewire::test(EditBuilding::class, ['record' => $building->getRouteKey()])
            ->fillForm(['map_color' => 'violeta'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('violeta', $building->fresh()->map_color);
    }

    public function test_only_palette_colors_are_accepted(): void
    {
        Livewire::test(EditBuilding::class, ['record' => $this->a['building']->getRouteKey()])
            ->fillForm(['map_color' => 'red;background:url(x)'])
            ->call('save')
            ->assertHasFormErrors(['map_color']);

        $this->assertNull($this->a['building']->fresh()->map_color);
    }

    public function test_markers_carry_their_color_and_default_to_orange(): void
    {
        $this->locate($this->a['building'], 'azul');
        $plain = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->locate($plain, null);

        $markers = collect(Livewire::test(BuildingsMap::class)->instance()->getMarkers())->keyBy('id');

        $this->assertSame('#2563EB', $markers[$this->a['building']->id]['color']);
        $this->assertSame('azul', $markers[$this->a['building']->id]['colorKey']);
        $this->assertSame(Building::MAP_COLORS['naranja'][1], $markers[$plain->id]['color']);
    }

    public function test_the_map_offers_a_color_filter_with_counts(): void
    {
        $this->locate($this->a['building'], 'azul');
        foreach (range(1, 2) as $i) {
            $this->locate(Building::factory()->create(['company_id' => $this->a['company']->id]), 'rojo');
        }

        $colors = Livewire::test(BuildingsMap::class)->instance()->colorsInUse();

        $this->assertSame(1, $colors['azul']['count']);
        $this->assertSame(2, $colors['rojo']['count']);

        $this->withoutVite()->get(BuildingsMap::getUrl())->assertSee('Todos los colores')->assertSee('Rojo (2)');
    }
}
