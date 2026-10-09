<?php

namespace Tests\Feature\Insights;

use App\Filament\Resources\Elevators\ElevatorResource;
use App\Filament\Resources\Elevators\Pages\EditElevator;
use App\Filament\Resources\Elevators\Pages\ViewElevator;
use App\Filament\Resources\Elevators\RelationManagers\DocumentsRelationManager;
use App\Livewire\ElevatorHistoryPanel;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\Quote;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\WorkOrder;
use App\Services\Elevators\ElevatorDocumentService;
use App\Services\Insights\ElevatorHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Legajo técnico del ascensor: legajos automáticos, ficha técnica,
 * documentación (privada) e historial básico/avanzado según el plan.
 */
class ElevatorFileTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Elevator $elevator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant(); // edificio con 2 ascensores
        $this->elevator = Elevator::where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->sole();
    }

    private function plan(string $slug, ?array $tenant = null): void
    {
        $tenant ??= $this->a;
        Subscription::updateOrCreate(['company_id' => $tenant['company']->id], ['provider' => 'mercadopago', 'plan' => $slug, 'status' => 'authorized', 'amount' => 1, 'current_period_end' => now()->addMonth()]);
        $tenant['company']->forgetPlan();
    }

    /** Un PDF real mínimo (el tipo se detecta por contenido). */
    private function pdf(int $padding = 0): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".str_repeat(' ', $padding)."\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    public function test_each_elevator_of_a_building_gets_its_own_file_automatically(): void
    {
        $this->assertSame(['Ascensor 1', 'Ascensor 2'], Elevator::where('building_id', $this->a['building']->id)->orderBy('id')->pluck('label')->all());
        $this->assertSame($this->a['company']->id, $this->elevator->company_id);

        // Más equipos: se agregan. Menos: el legajo queda inactivo (no se borra su historia).
        $this->a['building']->update(['elevator_count' => 1, 'freight_elevator_count' => 1]);
        $labels = Elevator::where('building_id', $this->a['building']->id)->orderBy('id')->get(['label', 'is_active']);
        $this->assertEquals(['Ascensor 1' => true, 'Ascensor 2' => false, 'Montacargas 1' => true], $labels->pluck('is_active', 'label')->all());

        $this->a['building']->update(['elevator_count' => 2]);
        $this->assertTrue(Elevator::where('label', 'Ascensor 2')->sole()->is_active);
        $this->assertSame(3, Elevator::where('building_id', $this->a['building']->id)->count());
    }

    public function test_the_migration_creates_files_for_existing_buildings(): void
    {
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 3, 'freight_elevator_count' => 1]);
        DB::table('elevators')->where('building_id', $building->id)->delete(); // como antes de la migración

        $migration = require database_path('migrations/2026_10_20_100000_create_elevators_tables.php');
        $migration->down();
        $migration->up();

        $this->assertSame(['Ascensor 1', 'Ascensor 2', 'Ascensor 3', 'Montacargas 1'], Elevator::withoutGlobalScopes()->where('building_id', $building->id)->orderBy('id')->pluck('label')->all());
    }

    public function test_a_restored_building_gets_its_files(): void
    {
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 2]);
        $building->delete();
        DB::table('elevators')->where('building_id', $building->id)->delete(); // como un eliminado antes de la migración

        $building->restore();

        $this->assertSame(['Ascensor 1', 'Ascensor 2'], Elevator::withoutGlobalScopes()->where('building_id', $building->id)->orderBy('id')->pluck('label')->all());
    }

    public function test_admin_completes_the_technical_sheet_and_cannot_move_it(): void
    {
        $this->actingInPanel($this->a['admin']);
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);

        $this->assertNotEmpty($this->elevator->missingEssentials());

        Livewire::test(EditElevator::class, ['record' => $this->elevator->getRouteKey()])
            ->fillForm(['manufacturer' => 'Otis', 'model' => 'Gen2', 'serial_number' => 'SN-123', 'year' => 2015, 'capacity_kg' => 600, 'stops' => 12, 'machine_type' => 'traction_mrl', 'doors' => 'Automáticas'])
            ->set('data.company_id', $b['company']->id)
            ->set('data.building_id', $b['building']->id)
            ->set('data.label', 'Hackeado')
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $this->elevator->fresh();
        $this->assertSame('Otis', $fresh->manufacturer);
        $this->assertSame([], $fresh->missingEssentials());
        $this->assertSame($this->a['company']->id, $fresh->company_id);
        $this->assertSame($this->a['building']->id, $fresh->building_id);
        $this->assertSame('Ascensor 1', $fresh->label);

        Livewire::test(EditElevator::class, ['record' => $this->elevator->getRouteKey()])
            ->fillForm(['year' => 1500, 'stops' => 0])->call('save')->assertHasFormErrors(['year', 'stops']);
    }

    public function test_documents_are_private_and_only_for_admins_of_the_company(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->elevator, 'pageClass' => ViewElevator::class])
            ->callTableAction('upload', data: [
                'type' => 'certificate', 'title' => 'Habilitación 2026', 'expires_at' => now()->addDays(10)->toDateString(),
                'file' => UploadedFile::fake()->createWithContent('cert.pdf', $this->pdf()),
            ])
            ->assertHasNoTableActionErrors();

        $doc = ElevatorDocument::sole();
        $this->assertMatchesRegularExpression('#^elevators/'.$this->a['company']->id.'/[A-Za-z0-9]{40}\.pdf$#', $doc->path);
        Storage::disk('local')->assertExists($doc->path);
        $this->assertTrue($doc->expiresSoon());

        $this->get($doc->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Técnico de la empresa, otra empresa y sin sesión: no.
        $this->actingAs($this->a['technician'])->get($doc->url())->assertNotFound();
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingAs($b['admin'])->get($doc->url())->assertNotFound();
        auth()->logout();
        $this->get($doc->url())->assertRedirect('/login');
        $this->get('/storage/'.$doc->path)->assertNotFound();

        // Borrar el documento borra el archivo.
        $this->actingInPanel($this->a['admin']);
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->elevator, 'pageClass' => ViewElevator::class])
            ->callTableAction('delete', $doc);
        Storage::disk('local')->assertMissing($doc->path);
    }

    public function test_dangerous_or_wrong_files_are_rejected(): void
    {
        $this->actingInPanel($this->a['admin']);
        $service = app(ElevatorDocumentService::class);

        foreach ([
            UploadedFile::fake()->createWithContent('plano.pdf', '<?php system($_GET["c"]); ?>'),       // no es un PDF de verdad
            UploadedFile::fake()->createWithContent('x.svg', '<svg><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('grande.pdf', $this->pdf(21 * 1024 * 1024)),
        ] as $file) {
            try {
                $service->store($this->elevator, $file, 'plan', 'X', null, $this->a['admin']);
                $this->fail('Se aceptó un archivo inválido: '.$file->getClientOriginalName());
            } catch (ValidationException) {
                // esperado
            }
        }

        $this->assertSame(0, ElevatorDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_history_brings_together_existing_records_of_the_elevator(): void
    {
        $this->plan('profesional');
        $this->actingInPanel($this->a['admin']);

        Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 1', 'description' => 'Ruido en puertas', 'component' => 'doors']);
        Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 2', 'description' => 'Del otro equipo']);
        WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'type' => 'claim', 'status' => 'completed']);
        BuildingVisit::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'assignment_type' => 'maintenance']);
        Quote::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1', 'title' => 'Cambio de operador']);

        $events = app(ElevatorHistory::class)->events($this->elevator, advanced: true);

        $this->assertSame(4, $events->count()); // reporte + orden + mantenimiento + presupuesto (no el del otro equipo)
        $this->assertEqualsCanonicalizing(['report', 'work_order', 'visit', 'quote'], $events->pluck('type')->all());
        $this->assertTrue($events->firstWhere('type', 'visit')['building_level']);
        $this->assertStringNotContainsString('Del otro equipo', $events->pluck('title')->implode(' '));

        Livewire::test(ElevatorHistoryPanel::class, ['elevator' => $this->elevator])
            ->assertSee('Reporte: Ruido en puertas')
            ->assertSee('Mantenimiento mensual del edificio')
            ->set('type', 'quote')
            ->assertSee('Presupuesto: Cambio de operador')
            ->assertDontSee('Reporte: Ruido en puertas');
    }

    public function test_basic_history_on_inicial_is_limited_and_ignores_filters(): void
    {
        $this->plan('inicial');
        $this->actingInPanel($this->a['admin']);
        // 15 reportes (el tope mensual de Inicial) + 3 órdenes = 18 hechos.
        Report::factory()->count(15)->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 1']);
        WorkOrder::factory()->count(3)->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor 1']);

        $this->assertCount(ElevatorHistory::BASIC_LIMIT, app(ElevatorHistory::class)->events($this->elevator, advanced: false, type: 'quote'));

        Livewire::test(ElevatorHistoryPanel::class, ['elevator' => $this->elevator])
            ->set('type', 'visit')               // manipulado: no aplica sin el plan
            ->assertSee('Reporte:')
            ->assertSee('Disponible en el plan Profesional')
            ->assertDontSee('Falla que se repite');  // el análisis no se calcula
    }

    public function test_other_companies_and_technicians_cannot_open_elevator_files(): void
    {
        auth()->logout();
        $b = $this->makeTenant();

        $this->actingInPanel($b['admin'])->get(ElevatorResource::getUrl('view', ['record' => $this->elevator]))->assertNotFound();
        $this->actingInPanel($b['admin'])->get(ElevatorResource::getUrl('edit', ['record' => $this->elevator]))->assertNotFound();

        $this->actingAs($this->a['technician'])->get(ElevatorResource::getUrl())->assertRedirect();

        // El componente vuelve a buscar el equipo con el scope de empresa: 404, sin datos.
        Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 1', 'description' => 'Dato privado de A']);
        $this->actingInPanel($b['admin']);
        Livewire::test(ElevatorHistoryPanel::class, ['elevator' => $this->elevator])
            ->assertStatus(404)
            ->assertDontSee('Dato privado de A');
    }
}
