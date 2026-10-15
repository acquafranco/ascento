<?php

namespace Tests\Feature\Billing;

use App\Filament\Clusters\ServicesCluster;
use App\Filament\RelationManagers\AccountStatementRelationManager;
use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Pages\ListMaintenanceServices;
use App\Filament\Resources\MaintenanceServices\Pages\ViewMaintenanceService;
use App\Filament\Resources\ServiceCatalogItems\Pages\ListServiceCatalogItems;
use App\Filament\Resources\ServiceCatalogItems\ServiceCatalogItemResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\ServiceCatalogItem;
use App\Services\Billing\ReceivableService;
use App\Services\Billing\ServiceBillingService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sección «Servicios» unificada: una entrada de navegación con dos vistas
 * (contratados y ofrecidos), acciones iguales en todos lados, cobros de la
 * contratación y «Volver a cobrar» un pago único anulado sin duplicar cobros.
 */
class ServicesSectionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    private Building $tower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
        $this->tower = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id, 'name' => 'Torre Norte', 'elevator_count' => 2]);
        $this->actingInPanel($this->a['admin']);
    }

    private function contract(array $attributes = []): MaintenanceService
    {
        return MaintenanceService::create([
            'client_id' => $this->client->id, 'building_id' => $this->tower->id, 'description' => 'Mantenimiento mensual', 'category' => 'maintenance',
            'amount' => 100000, 'frequency' => 'monthly', 'start_date' => '2026-10-01', 'payment_due_day' => 20, 'status' => 'active',
            ...$attributes,
        ]);
    }

    private function oneTime(array $attributes = []): MaintenanceService
    {
        return $this->contract(['description' => 'Prueba de paracaídas', 'category' => 'parachute', 'amount' => 90000, 'frequency' => 'once', ...$attributes]);
    }

    private function staff(array $levels, string $name): void
    {
        $this->actingAs($this->a['admin']);
        $user = app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
        $this->flushSession();
        $this->actingInPanel($user);
    }

    /*
    |--------------------------------------------------------------------------
    | ORGANIZACIÓN DE LA SECCIÓN
    |--------------------------------------------------------------------------
    */

    public function test_there_is_a_single_services_entry_with_two_views(): void
    {
        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()));
        $this->assertSame(1, $labels->filter(fn ($l) => $l === 'Servicios')->count());
        $this->assertFalse($labels->contains('Catálogo de servicios'));
        $this->assertFalse($labels->contains('Servicios ofrecidos'));
        $this->assertFalse($labels->contains('Servicios contratados'));

        // Dentro: «Servicios contratados» y «Servicios ofrecidos», en ese orden.
        $this->assertSame(ServicesCluster::class, MaintenanceServiceResource::getCluster());
        $this->assertSame(ServicesCluster::class, ServiceCatalogItemResource::getCluster());
        $this->assertSame(['Servicios contratados', 'Servicios ofrecidos'], [MaintenanceServiceResource::getNavigationLabel(), ServiceCatalogItemResource::getNavigationLabel()]);
        $this->assertLessThan(ServiceCatalogItemResource::getNavigationSort(), MaintenanceServiceResource::getNavigationSort());

        // Entrar a «Servicios» abre la primera vista.
        $this->get(ServicesCluster::getUrl())->assertRedirect(MaintenanceServiceResource::getUrl('index'));

        $this->get(MaintenanceServiceResource::getUrl('index'))->assertOk()
            ->assertSee('Servicios contratados')->assertSee('Servicios ofrecidos')->assertSee('Contratar servicio')->assertDontSee('Nuevo servicio ofrecido');
        $this->get(ServiceCatalogItemResource::getUrl('index'))->assertOk()
            ->assertSee('Nuevo servicio ofrecido')->assertDontSee('Contratar servicio');
    }

    public function test_offered_and_contracted_services_stay_separate_and_are_not_repeated(): void
    {
        $item = ServiceCatalogItem::create(['name' => 'Mantenimiento por equipo', 'category' => 'maintenance', 'pricing_mode' => ServiceCatalogItem::PER_EQUIPMENT, 'base_price' => 50000, 'billing_frequency' => 'monthly', 'is_active' => true]);
        $fromCatalog = $this->contract(['catalog_item_id' => $item->id, 'description' => 'Mantenimiento por equipo']);
        $legacy = $this->contract(['building_id' => $this->a['building']->id, 'category' => null, 'description' => 'Abono histórico']);

        Livewire::test(ListMaintenanceServices::class)->assertCanSeeTableRecords([$fromCatalog, $legacy])->assertCountTableRecords(2);
        Livewire::test(ListServiceCatalogItems::class)->assertCanSeeTableRecords([$item])->assertCountTableRecords(1);

        // «Contratos activos» del servicio ofrecido lleva a sus contrataciones (no las repite).
        $url = MaintenanceServiceResource::getUrl('index', ['filters' => ['catalog_item_id' => ['value' => $item->id]]]);
        Livewire::test(ListServiceCatalogItems::class)->assertTableColumnStateSet('active_contracts_count', 1, $item);
        $this->get(ServiceCatalogItemResource::getUrl('index'))->assertSee(e($url), false);
        Livewire::withQueryParams(['filters' => ['catalog_item_id' => ['value' => $item->id]]])->test(ListMaintenanceServices::class)
            ->assertCanSeeTableRecords([$fromCatalog])->assertCanNotSeeTableRecords([$legacy]);

        // Nada se migró al catálogo: el contrato histórico sigue sin catálogo ni tipo.
        $this->assertSame([null, null], [$legacy->fresh()->catalog_item_id, $legacy->fresh()->category]);
    }

    public function test_old_addresses_redirect_to_the_new_section(): void
    {
        $service = $this->contract();

        $this->get('/admin/maintenance-services')->assertRedirect(url('/admin/servicios/contratados'));
        $this->get("/admin/maintenance-services/{$service->id}/edit")->assertRedirect(url("/admin/servicios/contratados/{$service->id}/edit"));
        $this->get('/admin/catalogo-servicios/create?x=1')->assertRedirect(url('/admin/servicios/ofrecidos/create?x=1'));
        $this->assertSame(url("/admin/servicios/contratados/{$service->id}"), MaintenanceServiceResource::getUrl('view', ['record' => $service]));
    }

    /*
    |--------------------------------------------------------------------------
    | ACCIONES IGUALES EN TODOS LADOS
    |--------------------------------------------------------------------------
    */

    public function test_lifecycle_actions_are_the_same_in_the_list_the_contract_and_the_client(): void
    {
        $active = $this->contract();
        $finished = $this->contract(['building_id' => $this->a['building']->id, 'status' => MaintenanceService::FINISHED, 'end_date' => '2026-10-10']);

        foreach ([Livewire::test(ListMaintenanceServices::class), Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])] as $table) {
            $table->assertTableActionVisible('set_paused', $active)->assertTableActionVisible('finish', $active)
                ->assertTableActionHidden('set_active', $active)
                ->assertTableActionHidden('set_active', $finished)->assertTableActionHidden('finish', $finished);
        }
        Livewire::test(ViewMaintenanceService::class, ['record' => $active->getRouteKey()])
            ->assertActionVisible('set_paused')->assertActionVisible('finish')->assertActionHidden('rebill');

        Livewire::test(ListMaintenanceServices::class)->callTableAction('finish', $active);
        $this->assertSame(MaintenanceService::FINISHED, $active->fresh()->status);
    }

    public function test_the_contract_shows_its_own_charges_without_manual_charges(): void
    {
        $service = $this->contract();
        $other = $this->contract(['building_id' => $this->a['building']->id, 'description' => 'Otro contrato']);

        Livewire::test(AccountStatementRelationManager::class, ['ownerRecord' => $service, 'pageClass' => ViewMaintenanceService::class])
            ->assertSee('Cobros')
            ->assertCanSeeTableRecords($service->receivables)
            ->assertCanNotSeeTableRecords($other->receivables)
            ->assertTableActionDoesNotExist('createManual');
        // En la ficha del cliente, la cuenta corriente sigue igual (con el alta manual).
        Livewire::test(AccountStatementRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
            ->assertSee('Cuenta corriente')->assertCanSeeTableRecords(Receivable::all());
    }

    /*
    |--------------------------------------------------------------------------
    | VOLVER A COBRAR UN PAGO ÚNICO ANULADO
    |--------------------------------------------------------------------------
    */

    public function test_a_voided_one_time_charge_can_be_billed_again_once(): void
    {
        $service = $this->oneTime();
        $original = Receivable::sole();
        $this->assertFalse(app(ServiceBillingService::class)->canRebill($service)); // vigente: no

        app(ReceivableService::class)->void($original, 'Error en el concepto');
        $voided = $original->fresh()->toArray();

        Livewire::test(ListMaintenanceServices::class)->assertTableColumnStateSet('one_time_charge', 'Anulada', $service)
            ->assertTableActionVisible('rebill', $service)
            ->callTableAction('rebill', $service);

        $new = Receivable::whereKeyNot($original->id)->sole();
        $this->assertSame([$service->id, 'service', Receivable::PENDING, 90000.0, '2026-10-20'], [$new->maintenance_service_id, $new->source, $new->status, (float) $new->amount, $new->due_date->toDateString()]);
        $this->assertSame($voided, $original->fresh()->toArray()); // el anulado queda intacto
        $this->assertSame('Pendiente', $service->fresh()->oneTimeCharge()->displayStatusLabel());

        // Doble clic o solicitud repetida: no hay segundo cobro.
        Livewire::test(ListMaintenanceServices::class)->assertTableActionHidden('rebill', $service);
        try {
            app(ServiceBillingService::class)->rebillOneTime($service, $this->a['admin']);
            $this->fail('Se creó un segundo cobro.');
        } catch (ValidationException) {
        }
        // Y la facturación automática tampoco agrega otro.
        $this->travelTo(Carbon::parse('2027-03-01'));
        app(ServiceBillingService::class)->generateAll();
        $this->assertSame(2, Receivable::count());
        $this->assertSame(1, Receivable::where('status', '!=', Receivable::VOID)->count());
    }

    public function test_rebilling_is_refused_while_any_charge_is_current(): void
    {
        $billing = app(ServiceBillingService::class);
        $payments = app(ReceivableService::class);

        // Pendiente, vencido, parcialmente pagado y pagado: no.
        $overdue = $this->oneTime(['building_id' => $this->a['building']->id, 'payment_due_day' => 16]); // vence el 16/10
        $this->travelTo(Carbon::parse('2026-10-17 10:00')); // ya pasó: vencido
        $pending = $this->oneTime();
        $partial = $this->oneTime(['description' => 'Parcial']);
        $payments->registerPayment($partial->receivables()->sole(), 10000, today(), 'cash', null, $this->a['admin']);
        $paid = $this->oneTime(['description' => 'Pagado']);
        $payments->registerPayment($paid->receivables()->sole(), 90000, today(), 'cash', null, $this->a['admin']);
        $this->assertSame('overdue', $overdue->receivables()->sole()->displayStatus());

        foreach ([$pending, $overdue, $partial, $paid] as $service) {
            $this->assertFalse($billing->canRebill($service));
            Livewire::test(ListMaintenanceServices::class)->assertTableActionHidden('rebill', $service);
            try {
                $billing->rebillOneTime($service, $this->a['admin']);
                $this->fail('Se volvió a cobrar con un cobro vigente.');
            } catch (ValidationException) {
            }
        }

        // Ni un servicio mensual, ni un pago único sin cobro todavía, ni uno pausado.
        $monthly = $this->contract(['description' => 'Mensual']);
        $monthly->receivables()->each(fn ($r) => $payments->void($r, 'x'));
        $future = $this->oneTime(['description' => 'Futuro', 'start_date' => '2027-02-01']);
        $paused = $this->oneTime(['description' => 'Pausado']);
        $payments->void($paused->receivables()->sole(), 'x');
        $paused->update(['status' => MaintenanceService::PAUSED]);
        foreach ([$monthly, $future, $paused] as $service) {
            $this->assertFalse($billing->canRebill($service->fresh()));
        }

        $this->assertSame(0, Receivable::where('concept', 'like', '%nuevo cobro%')->count());
    }

    public function test_rebilling_respects_permissions_and_companies(): void
    {
        $service = $this->oneTime();
        app(ReceivableService::class)->void($service->receivables()->sole(), 'Error');

        // Solo lectura: no aparece ni se ejecuta.
        $this->staff(['clients' => 'view', 'receivables' => 'view'], 'Solo mira');
        Livewire::test(ListMaintenanceServices::class)->assertTableActionHidden('rebill', $service);
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])->assertTableActionHidden('rebill', $service);
        $this->assertSame(1, Receivable::count());

        // Otra empresa: no puede volver a cobrar una contratación ajena.
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        try {
            app(ServiceBillingService::class)->rebillOneTime($service, $this->b['admin']);
            $this->fail('Otra empresa volvió a cobrar.');
        } catch (ValidationException) {
        }
        $this->assertSame(1, Receivable::withoutGlobalScopes()->count());

        // Desde la ficha del cliente (con permisos), funciona.
        $this->flushSession();
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])->callTableAction('rebill', $service);
        $this->assertSame(2, Receivable::count());
    }

    public function test_view_only_staff_cannot_run_lifecycle_actions_in_the_services_list(): void
    {
        $service = $this->contract();
        $this->staff(['clients' => 'view', 'receivables' => 'view'], 'Mira contratos');

        Livewire::test(ListMaintenanceServices::class)
            ->assertTableActionHidden('set_paused', $service)
            ->assertTableActionHidden('finish', $service)
            ->assertTableActionHidden('edit', $service);
        $this->get(ServiceCatalogItemResource::getUrl('index'))->assertOk()->assertDontSee('Nuevo servicio ofrecido');
        $this->assertSame(MaintenanceService::ACTIVE, $service->fresh()->status);
    }

    public function test_without_receivables_permission_the_section_is_not_shown_nor_opened(): void
    {
        $this->staff(['clients' => 'view'], 'Sin cobranzas');

        $this->assertFalse(ServicesCluster::canAccess());
        $this->get(ClientResource::getUrl('index'))->assertOk()->assertDontSee('href="'.ServicesCluster::getUrl().'"', false);
        $this->get(ServicesCluster::getUrl())->assertForbidden();
        $this->get(MaintenanceServiceResource::getUrl('index'))->assertForbidden();
        $this->get(ServiceCatalogItemResource::getUrl('index'))->assertForbidden();
    }
}
