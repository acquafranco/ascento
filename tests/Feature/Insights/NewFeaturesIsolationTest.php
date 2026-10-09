<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\Agenda;
use App\Filament\Pages\AttentionCenterPage;
use App\Filament\Pages\IndicatorsPage;
use App\Filament\Resources\Elevators\ElevatorResource;
use App\Filament\Resources\Elevators\Pages\ViewElevator;
use App\Filament\Resources\Elevators\RelationManagers\DocumentsRelationManager;
use App\Livewire\ElevatorHistoryPanel;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\Report;
use App\Models\Subscription;
use App\Services\Elevators\ElevatorDocumentService;
use App\Services\Insights\FailureAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ataques sobre las funciones nuevas: URLs, IDs, filtros y solicitudes de
 * otra empresa; plan en el backend.
 */
class NewFeaturesIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Elevator $elevatorB;

    private ElevatorDocument $documentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        // Datos privados de B (creados sin sesión).
        $this->elevatorB = Elevator::withoutGlobalScopes()->where('building_id', $this->b['building']->id)->where('label', 'Ascensor 1')->sole();
        $this->elevatorB->forceFill(['serial_number' => 'SERIE-SECRETA-B'])->save();
        $this->documentB = app(ElevatorDocumentService::class)->store(
            $this->elevatorB, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%%EOF"), 'certificate', 'Certificado de B', null, null
        );
        Report::factory()->count(4)->create(['building_id' => $this->b['building']->id, 'user_id' => $this->b['technician']->id, 'elevator_number' => 'Ascensor 1', 'description' => 'Falla privada de B']);

        $this->actingInPanel($this->a['admin']);
    }

    public function test_urls_and_ids_of_another_company_never_return_data(): void
    {
        foreach ([
            ElevatorResource::getUrl('view', ['record' => $this->elevatorB]),
            ElevatorResource::getUrl('edit', ['record' => $this->elevatorB]),
            $this->documentB->url(),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->get(ElevatorResource::getUrl())->assertOk()->assertDontSee('SERIE-SECRETA-B');
        $this->get(AttentionCenterPage::getUrl())->assertOk()->assertDontSee($this->b['building']->name);
        $this->get(IndicatorsPage::getUrl())->assertOk()->assertDontSee($this->b['technician']->name);

        Livewire::test(ElevatorHistoryPanel::class, ['elevator' => $this->elevatorB])->assertStatus(404)->assertDontSee('Falla privada de B');
        $this->assertCount(0, app(FailureAnalysis::class)->recurrent());
    }

    public function test_the_documents_component_cannot_be_pointed_at_another_company(): void
    {
        try {
            Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->elevatorB, 'pageClass' => ViewElevator::class])
                ->callTableAction('upload', data: ['type' => 'plan', 'title' => 'Inyectado', 'file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF")]);
        } catch (\Throwable $e) {
            // rechazado
        }

        $this->assertSame(0, ElevatorDocument::withoutGlobalScopes()->where('title', 'Inyectado')->count());
    }

    public function test_filters_with_ids_of_another_company_are_ignored(): void
    {
        Livewire::withQueryParams(['technician' => (string) $this->b['technician']->id, 'status' => 'pending'])
            ->test(Agenda::class)
            ->assertOk()
            ->assertDontSee($this->b['building']->name)
            ->assertSee($this->a['building']->name); // el filtro ajeno se descarta
    }

    public function test_technicians_and_guests_get_nothing(): void
    {
        $this->actingAs($this->a['technician']);
        foreach ([Agenda::getUrl(), AttentionCenterPage::getUrl(), IndicatorsPage::getUrl(), ElevatorResource::getUrl()] as $url) {
            $this->get($url)->assertRedirect();
        }
        $documentA = app(ElevatorDocumentService::class)->store(
            Elevator::where('building_id', $this->a['building']->id)->first(), UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"), 'manual', 'Manual', null, null
        );
        $this->get($documentA->url())->assertNotFound();

        auth()->logout();
        $this->get($documentA->url())->assertRedirect('/login');
    }

    public function test_plan_is_checked_in_the_backend_for_each_new_feature(): void
    {
        Subscription::create(['company_id' => $this->a['company']->id, 'provider' => 'manual', 'plan' => 'inicial', 'status' => 'authorized', 'amount' => 69000, 'current_period_end' => now()->addMonth()]);
        $this->a['company']->forgetPlan();
        Report::factory()->count(3)->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 1']);
        $elevatorA = Elevator::where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->sole();

        // Inicial: abre todo lo básico, sin datos de las funciones de otros planes.
        $this->get(ElevatorResource::getUrl('view', ['record' => $elevatorA]))->assertOk();
        Livewire::test(ElevatorHistoryPanel::class, ['elevator' => $elevatorA])
            ->set('type', 'report')->set('months', '3')   // filtros del plan superior, manipulados
            ->assertViewHas('advanced', false)
            ->assertViewHas('analysis', null)
            ->assertSee('Disponible en el plan Profesional');
    }
}
