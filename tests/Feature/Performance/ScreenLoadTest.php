<?php

namespace Tests\Feature\Performance;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\RelationManagers\PhotosRelationManager;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Insights\Indicators;
use App\Services\Insights\MaintenanceAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Las pantallas no cargan más de lo que muestran: paginación en el servidor,
 * sumas en la base, datos de la agenda una sola vez por solicitud y
 * miniaturas en vez de fotos originales. Siempre dentro de la propia empresa.
 */
class ScreenLoadTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    /** Consultas y modelos cargados durante $fn. @return array{queries: list<string>, models: array<string, int>} */
    private function measure(callable $fn): array
    {
        $models = [];
        Event::listen('eloquent.retrieved: *', function ($event) use (&$models) {
            $class = class_basename(str_replace('eloquent.retrieved: ', '', $event));
            $models[$class] = ($models[$class] ?? 0) + 1;
        });
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        Event::forget('eloquent.retrieved: *');

        return ['queries' => $queries, 'models' => $models];
    }

    /** Edificios de la empresa A asignados al técnico (como invitado: el modelo no fuerza otra empresa). */
    private function buildingsFor(User $tech, int $count, string $type = 'maintenance', array $attrs = []): Collection
    {
        auth()->logout();

        return collect(range(1, $count))->map(function () use ($tech, $type, $attrs) {
            $b = Building::factory()->create(['company_id' => $tech->company_id, 'elevator_count' => 2, 'freight_elevator_count' => 1, ...$attrs]);
            $b->users()->attach($tech->id, ['type' => $type]);

            return $b;
        });
    }

    private function doneVisit(Building $b, User $tech, string $type): void
    {
        BuildingVisit::factory()->create(['company_id' => $b->company_id, 'building_id' => $b->id, 'user_id' => $tech->id, 'assignment_type' => $type, 'status' => 'done']);
    }

    // --- Visitas del técnico ------------------------------------------------

    public function test_technician_visit_totals_are_summed_in_the_database_with_the_same_rules(): void
    {
        $tech = $this->a['technician']; // ya tiene 1 edificio de mantenimiento (makeTenant)
        $maintenance = $this->buildingsFor($tech, 3);
        $inspection = $this->buildingsFor($tech, 2, 'inspection');
        $both = $maintenance->first();
        $both->users()->attach($tech->id, ['type' => 'inspection']);

        $this->doneVisit($maintenance[0], $tech, 'maintenance');
        $this->doneVisit($maintenance[0], $tech, 'maintenance'); // repetida: cuenta una vez
        $this->doneVisit($inspection[0], $tech, 'inspection');
        $this->doneVisit($maintenance[1], $tech, 'inspection');   // no es su inspección: no descuenta
        // Otra empresa con visitas: no cuenta.
        $this->doneVisit(Building::factory()->create(['company_id' => $this->b['company']->id]), $this->b['technician'], 'maintenance');

        $base = $this->a['building']->elevator_count + $this->a['building']->freight_elevator_count;
        $view = $this->actingAs($tech)->get('/'.$this->a['company']->slug.'/buildings')->assertOk();

        $view->assertViewHas('maintenanceTotalMachines', $base + 9);
        $view->assertViewHas('maintenanceRemaining', $base + 9 - 3);
        $view->assertViewHas('inspectionTotalMachines', 9);          // 2 de inspección + el compartido
        $view->assertViewHas('inspectionRemaining', 9 - 3);
        $view->assertViewHas('totalMachines', $base + 15);           // el compartido una sola vez
    }

    public function test_technician_visits_do_not_load_every_assigned_building(): void
    {
        $tech = $this->a['technician'];
        $this->buildingsFor($tech, 45);

        $this->actingAs($tech)->get('/'.$this->a['company']->slug.'/buildings')->assertOk(); // calentamiento (empresa y plan en memoria)
        $few = $this->measure(fn () => $this->actingAs($tech)->get('/'.$this->a['company']->slug.'/buildings')->assertOk());
        $this->buildingsFor($tech, 30);
        $many = $this->measure(fn () => $this->actingAs($tech)->get('/'.$this->a['company']->slug.'/buildings')->assertOk());

        $this->assertSame(count($few['queries']), count($many['queries']), 'la cantidad de consultas no crece con los edificios');
        // Lo mismo con 45 que con 75 edificios: la página (20) y nada que crezca con el total.
        $this->assertSame($few['models']['Building'] ?? 0, $many['models']['Building'] ?? 0);
        $this->assertLessThan(45, $many['models']['Building'] ?? 0);
    }

    public function test_technician_visits_paginate_and_search_keep_working_and_stay_in_the_company(): void
    {
        $tech = $this->a['technician'];
        $this->buildingsFor($tech, 24, attrs: ['name' => 'Torre Alfa']);
        $this->buildingsFor($tech, 1, attrs: ['name' => 'Edificio Buscado']);
        Building::factory()->create(['company_id' => $this->b['company']->id, 'name' => 'Edificio Buscado Ajeno']);
        $slug = $this->a['company']->slug;

        $this->actingAs($tech)->get("/{$slug}/buildings")->assertViewHas('buildings', fn ($p) => $p->count() === 20 && $p->total() === 26);
        $this->get("/{$slug}/buildings?page=2")->assertViewHas('buildings', fn ($p) => $p->count() === 6);
        $this->get("/{$slug}/buildings?search=Buscado")->assertViewHas('buildings', fn ($p) => $p->total() === 1)
            ->assertSee('Edificio Buscado')->assertDontSee('Edificio Buscado Ajeno');
    }

    // --- Agenda ---------------------------------------------------------------

    public function test_the_agenda_loads_its_data_once_per_request(): void
    {
        $this->buildingsFor($this->a['technician'], 5);
        $this->actingInPanel($this->a['admin']);

        // El centro de atención la pide por tipo, estado y mes (antes, 9 veces).
        $run = $this->measure(fn () => $this->get('/admin/atencion')->assertOk());
        $plain = collect($run['queries'])->map(fn ($q) => str_replace(['"', '`'], '', $q)); // igual en SQLite y MySQL
        $buildingLoads = $plain->filter(fn ($q) => str_starts_with($q, 'select * from buildings') && str_contains($q, 'order by name'))->count();

        $this->assertSame(1, $buildingLoads);
    }

    public function test_outside_a_request_the_agenda_is_always_fresh(): void
    {
        $this->actingAs($this->a['admin']);
        $agenda = app(MaintenanceAgenda::class);
        $before = $agenda->rows(now()->month, now()->year, 'maintenance')->count();

        $this->buildingsFor($this->a['technician'], 2);
        $this->actingAs($this->a['admin']);

        $this->assertSame($before + 2, $agenda->rows(now()->month, now()->year, 'maintenance')->count());
    }

    public function test_a_technician_agenda_only_loads_their_buildings(): void
    {
        $tech = $this->a['technician'];
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->buildingsFor($other, 15);
        $mine = $this->buildingsFor($tech, 2);
        $visited = Building::factory()->create(['company_id' => $this->a['company']->id]); // sin asignar, pero visitado por él este mes
        $this->doneVisit($visited, $tech, 'maintenance');

        $this->actingAs($this->a['admin']);
        $run = $this->measure(fn () => $rows = app(MaintenanceAgenda::class)->rows(now()->month, now()->year, null, $tech->id));
        $rows = app(MaintenanceAgenda::class)->rows(now()->month, now()->year, null, $tech->id);

        $this->assertEqualsCanonicalizing([$this->a['building']->id, ...$mine->pluck('id'), $visited->id], $rows->pluck('building.id')->unique()->all());
        $this->assertLessThanOrEqual(4, $run['models']['Building'] ?? 0, 'no carga los 15 edificios del otro técnico');
    }

    public function test_the_agenda_page_is_paginated_with_the_full_summary_and_filters(): void
    {
        $this->buildingsFor($this->a['technician'], 59); // 60 con el de makeTenant
        Building::factory()->create(['company_id' => $this->b['company']->id])->users()->attach($this->b['technician']->id, ['type' => 'maintenance']);
        $this->actingInPanel($this->a['admin']);

        $page = Livewire::test(Agenda::class)->set('type', 'maintenance');
        $page->assertViewHas('rows', fn ($rows) => $rows->count() === Agenda::PER_PAGE && $rows->total() === 60)
            ->assertViewHas('summary', fn ($s) => array_sum($s) === 60); // el resumen cuenta el mes entero

        $page->call('gotoPage', 2)->assertViewHas('rows', fn ($rows) => $rows->count() === 10);
        // Cambiar un filtro vuelve a la página 1.
        $page->set('status', 'pending')->assertViewHas('rows', fn ($rows) => $rows->currentPage() === 1);
        // Nada de la otra empresa.
        $page->set('status', '')->assertViewHas('rows', fn ($rows) => $rows->total() === 60);
    }

    // --- Indicadores ------------------------------------------------------------

    public function test_technician_activity_uses_the_same_rules_without_queries_per_technician(): void
    {
        $tech = $this->a['technician'];
        auth()->logout();
        $building = $this->a['building'];
        BuildingVisit::factory()->count(3)->create(['company_id' => $this->a['company']->id, 'building_id' => $building->id, 'user_id' => $tech->id, 'visited_at' => now()->subDays(10)]);
        BuildingVisit::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $building->id, 'user_id' => $tech->id, 'visited_at' => now()->subDays(120)]); // fuera de 90 días
        $order = WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $building->id, 'status' => 'completed', 'created_at' => now()->subDays(4), 'finished_at' => now()->subDays(2)]);
        $order->participants()->attach($tech->id, ['role' => 'creator']);
        $foreign = WorkOrder::factory()->create(['company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id, 'status' => 'completed', 'finished_at' => now()->subDay()]);
        $foreign->participants()->attach($this->b['technician']->id, ['role' => 'creator']);

        $this->actingAs($this->a['admin']);
        $row = collect(app(Indicators::class)->company($this->a['company'], false)['technicians'])->firstWhere('name', $tech->name);

        $this->assertSame(3, $row['visits']);
        $this->assertSame(1, $row['orders']);
        $this->assertSame(2.0, $row['close_days']);

        // Más técnicos no suman consultas.
        $more = fn (int $n) => User::withoutEvents(fn () => User::factory()->technician()->count($n)->create(['company_id' => $this->a['company']->id])); // sin el límite del plan
        $more(6);
        app(Indicators::class)->company($this->a['company'], false); // calentamiento
        $one = $this->measure(fn () => app(Indicators::class)->company($this->a['company'], false));
        $more(10);
        $after = $this->measure(fn () => app(Indicators::class)->company($this->a['company'], false));
        $this->assertSame(count($one['queries']), count($after['queries']));
    }

    // --- Panel ----------------------------------------------------------------

    public function test_panel_pages_no_longer_load_every_building_id(): void
    {
        $this->buildingsFor($this->a['technician'], 30);

        $run = $this->measure(fn () => $this->actingAs($this->a['admin'])->get('/admin/clients')->assertOk());

        $this->assertSame(0, $run['models']['Building'] ?? 0, 'la guía ya no trae los ids de todos los edificios');
        $this->assertEmpty(collect($run['queries'])->filter(fn ($q) => str_contains(str_replace(['"', '`'], '', $q), 'from building_user where building_id in')));
    }

    public function test_incomplete_elevators_are_counted_in_the_database_with_the_same_rule(): void
    {
        auth()->logout();
        $building = $this->a['building'];
        $complete = ['manufacturer' => 'Otis', 'model' => 'Gen2', 'serial_number' => 'S1', 'year' => 2010, 'capacity_kg' => 450, 'stops' => 8];
        Elevator::withoutGlobalScopes()->where('building_id', $building->id)->delete();
        $make = function (string $label, array $data) use ($building) {
            $elevator = (new Elevator)->forceFill(['building_id' => $building->id, 'label' => $label, ...$data]);
            $elevator->save();

            return $elevator;
        };
        $ok = $make('A1', $complete);
        $empty = $make('A2', [...$complete, 'model' => '  ']);
        $null = $make('A3', [...$complete, 'stops' => null]);

        $this->actingAs($this->a['admin']);
        $sql = Elevator::incomplete()->pluck('id')->sort()->values()->all();
        $php = Elevator::all()->filter(fn ($e) => $e->missingEssentials() !== [])->pluck('id')->sort()->values()->all();

        $this->assertSame($php, $sql);
        $this->assertSame([$empty->id, $null->id], $sql);
        $this->assertNotContains($ok->id, $sql);
    }

    public function test_report_lists_and_previews_use_thumbnails_and_open_the_original(): void
    {
        auth()->logout();
        $report = Report::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        $photo = new ReportPhoto(['report_id' => $report->id, 'path' => "reports/{$this->a['company']->id}/f.jpg", 'position' => 0]);
        $photo->company_id = $this->a['company']->id;
        $photo->save();

        $this->actingInPanel($this->a['admin']);
        $thumb = e($photo->thumbUrl());

        $this->get('/admin/reports')->assertOk()->assertSee($thumb, false)->assertDontSee('src="'.e($photo->url()).'"', false);
        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewReport::class])
            ->assertSeeHtml($thumb)->assertSeeHtml('href="'.e($photo->url()).'"');

        // La miniatura sigue pasando por la ruta autenticada: otra empresa no la ve.
        $this->actingAs($this->b['admin'])->get($photo->thumbUrl())->assertNotFound();
    }

    public function test_legacy_clients_list_is_paginated_and_stays_in_the_company(): void
    {
        auth()->logout();
        Client::factory()->count(35)->create(['company_id' => $this->a['company']->id]);
        Client::factory()->create(['company_id' => $this->b['company']->id, 'name' => 'Cliente Ajeno']);
        $slug = $this->a['company']->slug;
        $total = Client::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count(); // 35 + el del edificio de makeTenant

        $run = $this->measure(fn () => $this->actingAs($this->a['admin'])->get("/{$slug}/clients")->assertOk()
            ->assertViewHas('clients', fn ($p) => $p->count() === 30 && $p->total() === $total)->assertDontSee('Cliente Ajeno'));
        $this->assertLessThanOrEqual(30, $run['models']['Client'] ?? 0);

        $this->get("/{$slug}/clients?page=2")->assertViewHas('clients', fn ($p) => $p->count() === $total - 30);
    }
}
