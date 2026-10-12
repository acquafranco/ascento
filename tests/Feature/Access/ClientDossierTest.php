<?php

namespace Tests\Feature\Access;

use App\Enums\PermissionArea;
use App\Filament\Pages\AttentionCenterPage;
use App\Filament\RelationManagers\AccountStatementRelationManager;
use App\Filament\RelationManagers\InteractionsRelationManager;
use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Crm\ClientDossier;
use App\Services\Crm\ClientInteractionService;
use App\Services\Insights\AttentionCenter;
use App\Services\Staff\StaffManager;
use App\Services\Tasks\TaskService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 6, PR 4: ficha CRM del cliente (lectura con «ver» en Clientes, cada
 * sección y pestaña con su propia área, entre empresas nada) y Centro de
 * atención filtrado por permisos (sin enlaces que terminen en 403).
 */
class ClientDossierTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, string $name = 'Ana Oficina'): User
    {
        $this->actingAs($this->a['admin']);

        return app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
    }

    private function viewUrl(?Client $client = null): string
    {
        return ClientResource::getUrl('view', ['record' => $client ?? $this->client]);
    }

    /** Un dato en cada módulo, todo del cliente de la empresa A. */
    private function fillClient(): array
    {
        $company = $this->a['company']->id;
        $building = $this->a['building'];
        $building->update(['name' => 'Torre Rivadavia']);
        (new Elevator)->forceFill(['building_id' => $building->id, 'company_id' => $company, 'label' => 'Ascensor Principal', 'kind' => 'elevator', 'is_active' => true])->save();

        $quote = Quote::factory()->create(['company_id' => $company, 'building_id' => $building->id, 'client_id' => $this->client->id, 'title' => 'Cambio de cables de tracción', 'status' => Quote::SENT, 'amount' => 250000, 'valid_until' => today()->addDays(20)]);
        $order = WorkOrder::factory()->create(['company_id' => $company, 'building_id' => $building->id, 'type' => 'claim', 'status' => 'pending']);
        $note = DeliveryNote::factory()->create(['company_id' => $company, 'building_id' => $building->id]);
        $receivable = Receivable::withoutGlobalScopes()->forceCreate(['company_id' => $company, 'client_id' => $this->client->id, 'source' => 'manual', 'concept' => 'Abono de septiembre', 'amount' => 80000, 'paid_amount' => 30000, 'status' => Receivable::PARTIAL, 'due_date' => today()->subDays(10)]);
        ReceivablePayment::withoutGlobalScopes()->forceCreate(['company_id' => $company, 'receivable_id' => $receivable->id, 'amount' => 30000, 'paid_at' => today()->subDays(3), 'method' => 'transfer']);

        $this->actingAs($this->a['admin']);
        $task = app(TaskService::class)->create($this->a['admin'], ['title' => 'Llamar por la renovación', 'priority' => 'high', 'client_id' => $this->client->id, 'due_at' => now()->subDay()]);
        app(ClientInteractionService::class)->record($this->a['admin'], ['client_id' => $this->client->id, 'type' => 'call', 'result' => 'follow_up', 'occurred_at' => now()->subDays(2), 'notes' => 'Pide la cotización por escrito.']);

        return compact('quote', 'order', 'note', 'receivable', 'task');
    }

    public function test_the_owner_sees_the_whole_dossier_in_one_place(): void
    {
        $data = $this->fillClient();
        $this->inPanel($this->a['admin']);

        $this->get($this->viewUrl())->assertOk()
            ->assertSee('Resumen')->assertSee('Actividad reciente')
            ->assertSee('Torre Rivadavia')->assertSee('Ascensor Principal')
            ->assertSee('Cambio de cables de tracción')->assertSee('#'.$data['note']->number.' · Mantenimiento')
            ->assertSee('#'.$data['order']->id)
            ->assertSee('Abono de septiembre')->assertSee('$ 50.000') // saldo
            ->assertSee('Llamar por la renovación')->assertSee('Pide la cotización por escrito.')
            ->assertSee('Se recibieron $ 30.000')
            ->assertSee(ClientResource::getUrl('edit', ['record' => $this->client]), false);

        $d = app(ClientDossier::class)->for($this->client, $this->a['admin']);
        $elevators = Elevator::withoutGlobalScopes()->where('building_id', $this->a['building']->id)->count();
        $this->assertSame([1, $elevators, 1, 1, 1], [$d['buildings']['count'], $d['buildings']['elevators'], $d['quotes']['pending_count'], $d['work_orders']['open_count'], $d['tasks']['open_count']]);
        $this->assertEquals([50000.0, 50000.0], [$d['receivables']['balance'], $d['receivables']['overdue']]);
        $this->assertNotNull($d['quotes']['items'][0]['url']);

        // Las pestañas de siempre siguen ahí (en la ficha, solo lectura salvo contactos).
        foreach ([ServicesRelationManager::class, AccountStatementRelationManager::class, PortalUsersRelationManager::class, InteractionsRelationManager::class] as $relation) {
            $this->assertTrue($relation::canViewForRecord($this->client, ViewClient::class), $relation);
        }
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertSee('Gestionar servicios')->assertSee('Crear servicio');
        // El listado lleva a la ficha.
        Livewire::test(ListClients::class)->assertTableActionVisible('view', $this->client)->assertTableActionVisible('edit', $this->client);
    }

    public function test_a_staff_member_with_view_on_clients_reads_the_dossier_without_editing(): void
    {
        $this->fillClient();
        $ana = $this->staff(['clients' => 'view']);
        $this->inPanel($ana);

        $this->get($this->viewUrl())->assertOk()
            ->assertSee($this->client->name)
            ->assertDontSee('Editar cliente')
            ->assertDontSee(ClientResource::getUrl('edit', ['record' => $this->client]), false);
        $this->get(ClientResource::getUrl('edit', ['record' => $this->client]))->assertForbidden();

        Livewire::test(ListClients::class)->assertTableActionVisible('view', $this->client)->assertTableActionHidden('edit', $this->client);
        Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])->assertOk()->assertActionHidden('edit');
    }

    public function test_sections_and_tabs_follow_their_own_area(): void
    {
        $data = $this->fillClient();

        // Solo «Clientes»: datos del cliente, ninguna sección ni número de otras áreas.
        $ana = $this->staff(['clients' => 'view'], 'Solo clientes');
        $this->inPanel($ana);
        $d = app(ClientDossier::class)->for($this->client, $ana);
        foreach (['buildings', 'quotes', 'work_orders', 'delivery_notes', 'receivables', 'tasks', 'contacts'] as $section) {
            $this->assertNull($d[$section], $section);
        }
        $this->assertSame([], $d['summary']);
        $this->assertSame([], $d['activity']);
        $this->get($this->viewUrl())->assertOk()
            ->assertDontSee('Torre Rivadavia')->assertDontSee('Cambio de cables')->assertDontSee('#'.$data['note']->number.' · Mantenimiento')
            ->assertDontSee('Abono de septiembre')->assertDontSee('50.000')->assertDontSee('Llamar por la renovación')
            ->assertDontSee('Pide la cotización')->assertDontSee('Se recibieron');
        foreach ([ServicesRelationManager::class, AccountStatementRelationManager::class, InteractionsRelationManager::class] as $relation) {
            $this->assertFalse($relation::canViewForRecord($this->client, ViewClient::class), $relation);
        }

        // Presupuestos y contactos (gestión), sin cobranzas ni edificios.
        $marcos = $this->staff(['clients' => 'view', 'quotes' => 'view', 'contacts' => 'manage'], 'Marcos');
        $this->inPanel($marcos);
        $d = app(ClientDossier::class)->for($this->client, $marcos);
        $this->assertSame(['quotes', 'contacts'], array_keys($d['summary']));
        $this->assertEqualsCanonicalizing(['Presupuesto', 'Contacto'], collect($d['activity'])->pluck('kind')->unique()->all());
        $this->get($this->viewUrl())->assertOk()
            ->assertSee('Cambio de cables de tracción')->assertSee('Pide la cotización por escrito.')
            ->assertDontSee('Torre Rivadavia')->assertDontSee('Abono de septiembre')->assertDontSee('#'.$data['note']->number.' · Mantenimiento')->assertDontSee('Llamar por la renovación');
        $this->assertFalse(AccountStatementRelationManager::canViewForRecord($this->client, ViewClient::class));
        $this->assertTrue(InteractionsRelationManager::canViewForRecord($this->client, ViewClient::class));

        // Registra un contacto desde la ficha (gestiona contactos, solo ve clientes).
        Livewire::test(InteractionsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->callTableAction('create', data: ['occurred_at' => '2026-10-14 08:30', 'type' => 'visit', 'result' => 'resolved', 'notes' => 'Visita comercial.'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2, ClientInteraction::where('client_id', $this->client->id)->count());

        // Con «ver» en contactos: la pestaña, pero el alta se rechaza en el servidor.
        $viewer = $this->staff(['clients' => 'view', 'contacts' => 'view'], 'Mira');
        $this->inPanel($viewer);
        Livewire::test(InteractionsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertTableActionHidden('create');
        Livewire::test(InteractionsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->mountTableAction('create')->callMountedTableAction();
        $this->assertSame(2, ClientInteraction::where('client_id', $this->client->id)->count());

        // Cobranzas con «ver»: el detalle se abre (página de lectura).
        $collector = $this->staff(['clients' => 'view', 'receivables' => 'view'], 'Cobra');
        $this->inPanel($collector);
        $d = app(ClientDossier::class)->for($this->client, $collector);
        $this->assertSame($data['receivable']->concept, $d['receivables']['items'][0]['label']);
        $this->assertNotNull($d['receivables']['items'][0]['url']);
        $this->get($d['receivables']['items'][0]['url'])->assertOk();
        // En la pestaña Servicios: sin «Crear servicio» (pide gestionar); el listado sí abre.
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertSee('Gestionar servicios')->assertDontSee('Crear servicio')
            ->assertDontSee(MaintenanceServiceResource::getUrl('create'), false);
        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])->assertOk();

        // Edificios con «ver»: el edificio solo tiene página de edición, así que se muestra sin enlace (nunca un 403).
        $buildingsViewer = $this->staff(['clients' => 'view', 'buildings' => 'view'], 'Mira edificios');
        $this->inPanel($buildingsViewer);
        $d = app(ClientDossier::class)->for($this->client, $buildingsViewer);
        $this->assertSame('Torre Rivadavia', $d['buildings']['items'][0]['name']);
        $this->assertNull($d['buildings']['items'][0]['url']);
        $this->get($this->viewUrl())->assertOk()->assertSee('Torre Rivadavia')
            ->assertDontSee(BuildingResource::getUrl('edit', ['record' => $this->a['building']]), false);
    }

    public function test_without_clients_area_the_dossier_does_not_open(): void
    {
        $ana = $this->staff(['quotes' => 'manage', 'contacts' => 'manage']);
        $this->inPanel($ana);

        $this->get($this->viewUrl())->assertForbidden();
        $this->assertFalse(ClientResource::canView($this->client));
    }

    public function test_the_dossier_never_crosses_companies(): void
    {
        $this->fillClient();
        auth()->logout();
        $b = $this->makeTenant();

        $this->inPanel($b['admin']);
        $this->get($this->viewUrl())->assertNotFound();

        $bStaff = app(StaffManager::class)->create($b['admin'], ['name' => 'Ajena', 'email' => 'ajena@demo.test', 'password' => 'clave-segura-123'], ['clients' => 'view', 'quotes' => 'view', 'contacts' => 'view']);
        $this->inPanel($bStaff);
        $this->get($this->viewUrl())->assertNotFound();
        $this->assertFalse(InteractionsRelationManager::canViewForRecord($this->client, ViewClient::class));
        Livewire::test(InteractionsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])->assertForbidden();
    }

    public function test_a_client_without_data_shows_empty_sections(): void
    {
        $empty = Client::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Consorcio Nuevo']);
        $this->inPanel($this->a['admin']);

        $this->get($this->viewUrl($empty))->assertOk()
            ->assertSee('Consorcio Nuevo')
            ->assertSee('Este cliente no tiene edificios cargados.')
            ->assertSee('No hay presupuestos para este cliente.')
            ->assertSee('No hay órdenes de trabajo en sus edificios.')
            ->assertSee('No hay remitos en sus edificios.')
            ->assertSee('No hay cobranzas registradas.')
            ->assertSee('No hay tareas abiertas.')
            ->assertSee('Todavía no se registraron contactos.')
            ->assertSee('Todavía no hay actividad registrada para este cliente.');

        $d = app(ClientDossier::class)->for($empty, $this->a['admin']);
        $this->assertEquals([0, 0.0, '—'], [$d['buildings']['count'], $d['receivables']['balance'], $d['summary']['contacts']['value']]);
        // No toma datos de otro cliente de la misma empresa.
        $this->fillClient();
        $d = app(ClientDossier::class)->for($empty, $this->a['admin']);
        $this->assertSame([0, 0, 0, 0], [$d['quotes']['pending_count'], $d['work_orders']['open_count'], $d['tasks']['open_count'], $d['contacts']['count']]);
    }

    public function test_the_attention_center_only_shows_alerts_of_the_staff_member_areas(): void
    {
        $this->fillClient(); // tarea vencida y cobro vencido

        $this->inPanel($this->a['admin']);
        $titles = fn () => app(AttentionCenter::class)->sections($this->a['company'])['basic']->pluck('title')->all();
        $this->assertContains('Tareas vencidas', $titles());
        $this->assertContains('Cobros vencidos', $titles());

        $ana = $this->staff(['attention' => 'view', 'tasks' => 'view'], 'Ana');
        $this->inPanel($ana);
        $basic = app(AttentionCenter::class)->sections($this->a['company'])['basic'];
        $this->assertSame(['Tareas vencidas'], $basic->pluck('title')->all());
        $this->assertNotNull($basic->first()['url']);
        $this->get(AttentionCenterPage::getUrl())->assertOk()
            ->assertSee('Tareas vencidas')->assertDontSee('Cobros vencidos')->assertDontSee('Órdenes sin tomar');

        // Solo el Centro de atención: nada de otras áreas.
        $solo = $this->staff(['attention' => 'view'], 'Solo atencion');
        $this->inPanel($solo);
        $this->assertCount(0, app(AttentionCenter::class)->sections($this->a['company'])['basic']);
        $this->get(AttentionCenterPage::getUrl())->assertOk()->assertDontSee('Tareas vencidas')->assertDontSee('Cobros vencidos');
    }

    public function test_an_alert_whose_destination_cannot_be_opened_has_no_link(): void
    {
        $ana = $this->staff(['attention' => 'view', 'tasks' => 'view'], 'Ana');
        $this->inPanel($ana);

        $closed = new class
        {
            public static function permissionArea(): PermissionArea
            {
                return PermissionArea::Tasks;
            }

            public static function canAccess(): bool
            {
                return false;
            }
        };
        $items = collect([['severity' => 'warning', 'title' => 'X', 'detail' => '', 'count' => 1, 'url' => '/admin/x', 'target' => $closed::class, 'examples' => []]]);

        $visible = app(AttentionCenter::class)->visibleTo($ana, $items);
        $this->assertCount(1, $visible);
        $this->assertNull($visible->first()['url']);

        // Una alerta sin área declarada no se muestra a un administrativo; al dueño sí.
        $items = collect([['severity' => 'warning', 'title' => 'Y', 'detail' => '', 'count' => 1, 'url' => '/admin/y', 'target' => null, 'examples' => []]]);
        $this->assertCount(0, app(AttentionCenter::class)->visibleTo($ana, $items));
        $this->assertCount(1, app(AttentionCenter::class)->visibleTo($this->a['admin'], $items));
    }
}
