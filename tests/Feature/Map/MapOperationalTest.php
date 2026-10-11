<?php

namespace Tests\Feature\Map;

use App\Filament\Pages\BuildingsMap;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\MaintenanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Mapa para organizar la cartera: estado del mantenimiento del mes, técnico y
 * zona en cada edificio, sin datos de otra empresa ni coordenadas inventadas.
 */
class MapOperationalTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-14 10:00'));
    }

    private function place(Building $b, float $lat, float $lng, ?string $zone = null): void
    {
        $b->forceFill(['latitude' => $lat, 'longitude' => $lng, 'geocoding_status' => Building::GEO_MANUAL, 'map_zone' => $zone])->saveQuietly();
    }

    public function test_markers_show_the_month_status_technician_and_zone(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->actingInPanel($a['admin']);

        $done = $a['building'];
        $done->update(['name' => 'Con remito']);
        $pending = Building::factory()->create(['company_id' => $a['company']->id, 'client_id' => $done->client_id, 'name' => 'Pendiente']);
        $pending->users()->attach($a['technician']->id, ['type' => 'maintenance']);
        $noTech = Building::factory()->create(['company_id' => $a['company']->id, 'client_id' => $done->client_id, 'name' => 'Sin técnico']);
        $unlocated = Building::factory()->create(['company_id' => $a['company']->id, 'client_id' => $done->client_id, 'name' => 'Sin ubicar']);
        foreach ([$done, $pending, $noTech] as $building) {
            MaintenanceService::create(['client_id' => $building->client_id, 'building_id' => $building->id, 'description' => 'Abono', 'amount' => 1000, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);
        }
        $this->place($done, -34.60, -58.38, 'Zona Norte');
        $this->place($pending, -34.61, -58.39, 'Zona Sur');
        $this->place($noTech, -34.62, -58.40);
        $this->place($b['building'], -31.42, -64.18, 'Zona Ajena');

        $visit = BuildingVisit::create(['company_id' => $a['company']->id, 'building_id' => $done->id, 'user_id' => $a['technician']->id, 'visit_type' => 'fixed',
            'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'status' => 'done', 'visited_at' => now(), 'source' => 'building']);
        DeliveryNote::factory()->create(['building_id' => $done->id, 'building_visit_id' => $visit->id, 'performed' => true]);

        $page = Livewire::test(BuildingsMap::class)->instance();
        $markers = collect($page->getMarkers())->keyBy('title');
        $byName = fn (string $name) => $markers->first(fn ($m) => str_starts_with($m['title'], $name));

        $this->assertSame(['done', 'Zona Norte'], [$byName('Con remito')['status'], $byName('Con remito')['zone']]);
        $this->assertSame(['pending', $a['technician']->name], [$byName('Pendiente')['status'], $byName('Pendiente')['technicians']]);
        $this->assertSame('unassigned', $byName('Sin técnico')['status']);
        $this->assertSame(BuildingsMap::STATUS_COLORS['pending'], $byName('Pendiente')['statusColor']);
        $this->assertCount(3, $markers);                                  // ni el no ubicado ni el de otra empresa
        $this->assertSame(['Zona Norte', 'Zona Sur'], $page->zonesInUse()); // sin zonas ajenas
        $this->assertSame([$a['technician']->id => $a['technician']->name], $page->techniciansInUse());
        $this->assertNull($unlocated->fresh()->latitude);                  // no se inventan coordenadas
    }

    public function test_the_page_shows_the_new_filters_and_legend(): void
    {
        $a = $this->makeTenant();
        $this->place($a['building'], -34.60, -58.38, 'Zona Norte');

        $this->actingInPanel($a['admin'])->get(BuildingsMap::getUrl())->assertOk()
            ->assertSee('Colorear por: estado del mes')->assertSee('Todas las zonas')->assertSee('Zona Norte')
            ->assertSee('data-map-legend="status"', false)->assertSee('Sin técnico');
    }
}
