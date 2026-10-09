<?php

namespace Tests\Feature\Portal;

use App\Enums\PlanLimit;
use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Building;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Services\Elevators\ElevatorDocumentService;
use App\Services\Reports\ReportPhotoService;
use App\Support\Plans\PlanGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Portal del cliente: solo sus edificios autorizados y solo lo que la
 * empresa compartió. Nunca otro cliente, otra empresa ni funciones internas.
 */
class ClientPortalTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $c1;

    private Client $c2;

    private Building $b1;   // de c1, autorizado

    private Building $b2;   // de c1, NO autorizado

    private Building $b3;   // de c2

    private User $u1;

    private User $u2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        $company = $this->a['company']->id;
        $this->c1 = Client::factory()->create(['company_id' => $company, 'name' => 'Consorcio Uno']);
        $this->c2 = Client::factory()->create(['company_id' => $company, 'name' => 'Consorcio Dos']);
        $this->b1 = Building::factory()->create(['company_id' => $company, 'client_id' => $this->c1->id, 'name' => 'Edificio Autorizado']);
        $this->b2 = Building::factory()->create(['company_id' => $company, 'client_id' => $this->c1->id, 'name' => 'Edificio No Autorizado']);
        $this->b3 = Building::factory()->create(['company_id' => $company, 'client_id' => $this->c2->id, 'name' => 'Edificio De Otro Cliente']);

        $this->u1 = $this->portalUser($this->c1, [$this->b1->id], 'uno@consorcio.test');
        $this->u2 = $this->portalUser($this->c2, [$this->b3->id], 'dos@consorcio.test');
    }

    private function portalUser(Client $client, array $buildingIds, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $user->portalBuildings()->sync($buildingIds);

        return $user;
    }

    private function note(Building $building, bool $shared, string $description = 'Mantenimiento mensual'): DeliveryNote
    {
        $note = DeliveryNote::factory()->create(['building_id' => $building->id, 'user_id' => $this->a['technician']->id, 'description' => $description]);
        $note->shareWithClient($shared);

        return $note->fresh();
    }

    private function report(Building $building, bool $shared, string $description): Report
    {
        $report = Report::factory()->create(['building_id' => $building->id, 'user_id' => $this->a['technician']->id, 'description' => $description]);
        app(ReportPhotoService::class)->add($report, [UploadedFile::fake()->image('f.jpg', 300, 200)]);
        $report->shareWithClient($shared);

        return $report->fresh();
    }

    private function document(Building $building, bool $shared, string $title): ElevatorDocument
    {
        $doc = app(ElevatorDocumentService::class)->store(Elevator::withoutGlobalScopes()->where('building_id', $building->id)->first(),
            UploadedFile::fake()->createWithContent('d.pdf', "%PDF-1.4\n%%EOF"), 'certificate', $title, null, null);
        $doc->shareWithClient($shared);

        return $doc;
    }

    public function test_login_takes_the_client_to_the_portal_with_only_their_authorized_buildings(): void
    {
        $this->post('/login', ['email' => 'uno@consorcio.test', 'password' => 'password'])->assertRedirect(route('portal.home'));

        $this->get(route('portal.home'))->assertOk()
            ->assertSee('Edificio Autorizado')
            ->assertDontSee('Edificio No Autorizado')     // mismo cliente, sin autorización
            ->assertDontSee('Edificio De Otro Cliente')
            ->assertDontSee($this->b['building']->name);

        $this->get(route('portal.building', $this->b1))->assertOk();
        foreach ([$this->b2, $this->b3, $this->b['building']] as $building) {
            $this->get(route('portal.building', $building))->assertNotFound();
        }
    }

    public function test_only_shared_records_of_authorized_buildings_are_visible(): void
    {
        $shared = $this->note($this->b1, true, 'Remito compartido');
        $private = $this->note($this->b1, false, 'Remito privado');
        $otherBuilding = $this->note($this->b2, true, 'Remito de edificio no autorizado');
        $sharedReport = $this->report($this->b1, true, 'Ruido en la cabina');
        $privateReport = $this->report($this->b1, false, 'Nota interna del técnico');
        $sharedDoc = $this->document($this->b1, true, 'Certificado anual');
        $privateDoc = $this->document($this->b1, false, 'Plano interno');
        $sharedQuote = Quote::factory()->create(['building_id' => $this->b1->id, 'title' => 'Cambio de operador']);
        $sharedQuote->shareWithClient(true);
        $privateQuote = Quote::factory()->create(['building_id' => $this->b1->id, 'title' => 'Presupuesto interno']);

        $this->actingAs($this->u1)->get(route('portal.building', $this->b1))->assertOk()
            ->assertSee('Remito '.$shared->number)->assertDontSee('Remito '.$private->number)
            ->assertSee('Ruido en la cabina')->assertDontSee('Nota interna del técnico')
            ->assertSee('Certificado anual')->assertDontSee('Plano interno')
            ->assertSee('Cambio de operador')->assertDontSee('Presupuesto interno');

        // Lo compartido abre; lo privado o de otro edificio da 404 aunque se conozca la URL.
        $this->get(route('portal.delivery-note', $shared))->assertOk()->assertSee('Remito compartido')->assertDontSee('btn-share', false);
        $this->get(route('portal.report', $sharedReport))->assertOk()->assertSee('Ruido en la cabina');
        $this->get(route('portal.report-photo', [$sharedReport, $sharedReport->photos->first()]))->assertOk();
        $this->get(route('portal.document', $sharedDoc))->assertOk();
        $this->get(route('portal.quote', $sharedQuote))->assertOk()->assertSee('Cambio de operador')->assertDontSee('Enviar por WhatsApp');

        foreach ([
            route('portal.delivery-note', $private),
            route('portal.delivery-note', $otherBuilding),
            route('portal.report', $privateReport),
            route('portal.report-photo', [$privateReport, $privateReport->photos->first()]),
            route('portal.report-photo', [$sharedReport, $privateReport->photos->first()]), // foto de otro reporte
            route('portal.document', $privateDoc),
            route('portal.quote', $privateQuote),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        // Las rutas internas de archivos tampoco le sirven.
        $this->get(route('reports.photos.show', [$sharedReport, $sharedReport->photos->first()]))->assertNotFound();
        $this->get(route('reports.pdf', $sharedReport))->assertNotFound();
        $this->get($sharedDoc->url())->assertNotFound();

        // Otro cliente de la misma empresa no ve nada de esto.
        $this->actingAs($this->u2);
        foreach ([route('portal.delivery-note', $shared), route('portal.report', $sharedReport), route('portal.document', $sharedDoc), route('portal.quote', $sharedQuote)] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_another_company_shared_records_are_unreachable(): void
    {
        auth()->logout();
        $clientB = Client::factory()->create(['company_id' => $this->b['company']->id]);
        $buildingB = Building::factory()->create(['company_id' => $this->b['company']->id, 'client_id' => $clientB->id]);
        $noteB = DeliveryNote::factory()->create(['building_id' => $buildingB->id]);
        $noteB->shareWithClient(true);
        // Aunque alguien lo autorizara por error, el edificio no es de su cliente ni de su empresa.
        $this->u1->portalBuildings()->attach($buildingB->id);

        $this->actingAs($this->u1)->get(route('portal.building', $buildingB))->assertNotFound();
        $this->get(route('portal.home'))->assertDontSee($buildingB->name);
    }

    public function test_portal_users_are_not_staff_and_staff_are_not_portal_users(): void
    {
        $slug = $this->a['company']->slug;

        $this->actingAs($this->u1)->get('/admin')->assertRedirect(route('portal.home'));
        $this->get('/admin/buildings')->assertRedirect(route('portal.home'));
        $this->get("/{$slug}/dashboard")->assertRedirect(route('portal.home'));
        $this->get("/{$slug}/buildings")->assertRedirect(route('portal.home'));
        $this->post("/{$slug}/reports", ['building_id' => $this->b1->id])->assertForbidden();
        $this->post("/{$slug}/delivery-notes/store", ['building_id' => $this->b1->id])->assertForbidden();

        $this->actingAs($this->a['admin'])->get(route('portal.home'))->assertRedirect('/admin');
        $this->actingAs($this->a['technician'])->get(route('portal.home'))->assertRedirect(route('dashboard', ['company' => $slug]));
        auth()->logout();
        $this->get(route('portal.home'))->assertRedirect('/login');
    }

    public function test_portal_users_never_count_or_act_as_technicians(): void
    {
        $this->assertSame(1, PlanGuard::for($this->a['company'])->usage(PlanLimit::Technicians)); // solo el técnico real
        $this->assertNull($this->u1->planLimit());

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$this->u1, $this->u2]);

        // No se los puede poner como participantes de un remito.
        $this->a['building']->users()->syncWithoutDetaching([$this->a['technician']->id => ['type' => 'maintenance']]);
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id, 'description' => 'x', 'elevator_quantity' => 1, 'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance', 'signature_name' => 'T', 'signature' => $this->validSignature(),
            'participants' => [$this->u1->id],
        ])->assertSessionHasErrors('participants.0');
    }

    public function test_admin_creates_portal_users_only_for_buildings_of_that_client(): void
    {
        $this->actingInPanel($this->a['admin']);

        // Edificios de otro cliente o de otra empresa inyectados: rechazados.
        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->c1, 'pageClass' => EditClient::class])
            ->callTableAction('createPortalUser', data: [
                'name' => 'Intruso', 'email' => 'intruso@consorcio.test', 'password' => 'clave-segura-1',
                'building_ids' => [$this->b1->id, $this->b3->id, $this->b['building']->id],
            ])
            ->assertHasTableActionErrors();
        $this->assertSame(0, User::where('email', 'intruso@consorcio.test')->count());

        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->c1, 'pageClass' => EditClient::class])
            ->callTableAction('createPortalUser', data: [
                'name' => 'Administración Uno', 'email' => 'admin@consorcio.test', 'password' => 'clave-segura-1',
                'building_ids' => [$this->b1->id, $this->b2->id],
            ])
            ->assertHasNoTableActionErrors();

        $user = User::where('email', 'admin@consorcio.test')->sole();
        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertSame([$this->c1->id, $this->a['company']->id], [$user->client_id, $user->company_id]);
        $this->assertEqualsCanonicalizing([$this->b1->id, $this->b2->id], $user->portalBuildings()->pluck('buildings.id')->all());

        // Desactivado: no entra más.
        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->c1, 'pageClass' => EditClient::class])->callTableAction('deactivate', $user);
        auth()->logout();
        $this->post('/login', ['email' => 'admin@consorcio.test', 'password' => 'clave-segura-1']);
        $this->assertGuest();

        // Otra empresa no puede administrar este cliente.
        $this->actingInPanel($this->b['admin']);
        $component = Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->c1, 'pageClass' => EditClient::class]);
        $this->assertContains($this->statusOf($component), [403, 404]);
        $component->assertDontSee('Administración Uno');
    }

    private function statusOf($component): int
    {
        try {
            $component->assertStatus(404);

            return 404;
        } catch (AssertionFailedError) {
            $component->assertStatus(403);

            return 403;
        }
    }

    public function test_sharing_is_explicit_and_audited(): void
    {
        $report = Report::factory()->create(['building_id' => $this->b1->id, 'user_id' => $this->a['technician']->id]);
        $this->assertFalse($report->fresh()->shared_with_client); // privado por defecto

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListReports::class)->call('updateTableColumnState', 'shared_with_client', (string) $report->getKey(), true);
        $this->assertTrue($report->fresh()->shared_with_client);
        $this->assertNotNull($report->fresh()->shared_at);

        Livewire::test(ListReports::class)->callTableBulkAction('unshareWithClient', [$report]);
        $this->assertFalse($report->fresh()->shared_with_client);
        $this->assertNull($report->fresh()->shared_at);
    }

    public function test_empty_states_and_inactive_company(): void
    {
        $this->actingAs($this->u1)->get(route('portal.building', $this->b1))->assertOk()
            ->assertSee('Todavía no hay remitos compartidos')
            ->assertSee('No hay documentación compartida');

        $this->u1->portalBuildings()->detach();
        $this->get(route('portal.home'))->assertOk()->assertSee('Todavía no tenés edificios habilitados');

        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->actingAs($this->u1->fresh())->get(route('portal.home'))->assertForbidden()->assertSee('El portal no está disponible');
    }
}
