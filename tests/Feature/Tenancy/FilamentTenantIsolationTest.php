<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\Maintenances\Pages\ListMaintenances;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Reports\Pages\CreateReport;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Aislamiento entre empresas en el panel /admin (Filament).
 * Un filtro visual no alcanza: también se ataca el backend manipulando
 * el estado de Livewire y mandando IDs de la otra empresa.
 */
class FilamentTenantIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    /*
    |--------------------------------------------------------------------------
    | LISTADOS
    |--------------------------------------------------------------------------
    */

    public function test_lists_only_show_own_company_records(): void
    {
        $woA = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $woB = WorkOrder::factory()->create(['building_id' => $this->b['building']->id]);
        $quoteA = Quote::factory()->create(['building_id' => $this->a['building']->id]);
        $quoteB = Quote::factory()->create(['building_id' => $this->b['building']->id]);
        $reportA = Report::factory()->create(['building_id' => $this->a['building']->id]);
        $reportB = Report::factory()->create(['building_id' => $this->b['building']->id]);
        $noteA = DeliveryNote::factory()->create(['building_id' => $this->a['building']->id]);
        $noteB = DeliveryNote::factory()->create(['building_id' => $this->b['building']->id]);
        $clientA = $this->a['building']->client;
        $clientB = $this->b['building']->client;

        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->assertCanSeeTableRecords([$this->a['building']])
            ->assertCanNotSeeTableRecords([$this->b['building']]);

        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$clientA])
            ->assertCanNotSeeTableRecords([$clientB]);

        Livewire::test(ListWorkOrders::class)
            ->assertCanSeeTableRecords([$woA])
            ->assertCanNotSeeTableRecords([$woB]);

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$quoteA])
            ->assertCanNotSeeTableRecords([$quoteB]);

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$reportA])
            ->assertCanNotSeeTableRecords([$reportB]);

        Livewire::test(ListDeliveryNotes::class)
            ->assertCanSeeTableRecords([$noteA])
            ->assertCanNotSeeTableRecords([$noteB]);

        Livewire::test(ListMaintenances::class)
            ->assertCanSeeTableRecords([$noteA])
            ->assertCanNotSeeTableRecords([$noteB]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->a['technician']])
            ->assertCanNotSeeTableRecords([$this->b['technician'], $this->b['admin']]);
    }

    /*
    |--------------------------------------------------------------------------
    | URLs DIRECTAS A REGISTROS DE OTRA EMPRESA
    |--------------------------------------------------------------------------
    */

    public function test_direct_urls_to_other_company_records_return_404(): void
    {
        $woB = WorkOrder::factory()->create(['building_id' => $this->b['building']->id]);
        $quoteB = Quote::factory()->create(['building_id' => $this->b['building']->id]);
        $reportB = Report::factory()->create(['building_id' => $this->b['building']->id]);
        $noteB = DeliveryNote::factory()->create(['building_id' => $this->b['building']->id]);
        $clientB = $this->b['building']->client;

        $this->actingInPanel($this->a['admin']);

        $urls = [
            BuildingResource::getUrl('edit', ['record' => $this->b['building']]),
            ClientResource::getUrl('edit', ['record' => $clientB]),
            WorkOrderResource::getUrl('edit', ['record' => $woB]),
            QuoteResource::getUrl('view', ['record' => $quoteB]),
            QuoteResource::getUrl('edit', ['record' => $quoteB]),
            ReportResource::getUrl('view', ['record' => $reportB]),
            ReportResource::getUrl('edit', ['record' => $reportB]),
            DeliveryNoteResource::getUrl('view', ['record' => $noteB]),
            UserResource::getUrl('view', ['record' => $this->b['technician']]),
            UserResource::getUrl('edit', ['record' => $this->b['technician']]),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }

        // Control positivo: lo propio sí abre.
        $this->get(BuildingResource::getUrl('edit', ['record' => $this->a['building']]))->assertOk();
        $this->get(UserResource::getUrl('edit', ['record' => $this->a['technician']]))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | MANIPULACIÓN DE company_id EN EL ESTADO DE LIVEWIRE
    |--------------------------------------------------------------------------
    */

    public function test_injected_company_id_on_create_is_ignored(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateBuilding::class)
            ->fillForm([
                'client_id' => $this->a['building']->client_id,
                'name' => 'Edificio Inyectado',
                'address' => '123',
            ])
            ->set('data.company_id', $this->b['company']->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Building::withoutGlobalScopes()->where('name', 'Edificio Inyectado')->sole();
        $this->assertSame($this->a['company']->id, $created->company_id);

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Cliente Inyectado', 'type' => 'consorcio'])
            ->set('data.company_id', $this->b['company']->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::withoutGlobalScopes()->where('name', 'Cliente Inyectado')->sole();
        $this->assertSame($this->a['company']->id, $client->company_id);
    }

    public function test_injected_company_id_on_edit_cannot_move_records(): void
    {
        $clientA = $this->a['building']->client;

        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditBuilding::class, ['record' => $this->a['building']->getRouteKey()])
            ->set('data.company_id', $this->b['company']->id)
            ->call('save');

        Livewire::test(EditClient::class, ['record' => $clientA->getRouteKey()])
            ->set('data.company_id', $this->b['company']->id)
            ->call('save');

        $this->assertSame($this->a['company']->id, Building::withoutGlobalScopes()->find($this->a['building']->id)->company_id);
        $this->assertSame($this->a['company']->id, Client::withoutGlobalScopes()->find($clientA->id)->company_id);
    }

    public function test_user_form_cannot_create_users_in_other_company_or_super_admins(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuevo Técnico',
                'email' => 'nuevo@example.com',
                'phone' => '1122334455',
                'password' => 'secret-password',
            ])
            ->set('data.company_id', $this->b['company']->id)
            ->set('data.is_super_admin', true)
            ->set('data.role', 'admin')
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'nuevo@example.com')->sole();

        $this->assertSame($this->a['company']->id, $user->company_id);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertFalse($user->isAdmin());
    }

    public function test_user_edit_cannot_escalate_privileges_or_change_company(): void
    {
        $technician = $this->a['technician'];
        $technician->update(['phone' => '1122334455']);

        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditUser::class, ['record' => $technician->getRouteKey()])
            ->set('data.company_id', $this->b['company']->id)
            ->set('data.is_super_admin', true)
            ->set('data.role', 'admin')
            ->call('save')
            ->assertHasNoFormErrors();

        $technician->refresh();

        $this->assertSame($this->a['company']->id, $technician->company_id);
        $this->assertFalse($technician->isSuperAdmin());
        $this->assertSame('technician', $technician->role);
    }

    /*
    |--------------------------------------------------------------------------
    | SELECTS CON IDs DE OTRA EMPRESA
    |--------------------------------------------------------------------------
    */

    public function test_relationship_selects_reject_other_company_ids(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateBuilding::class)
            ->fillForm([
                'client_id' => $this->b['building']->client_id,
                'name' => 'Con cliente ajeno',
                'address' => '1',
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id']);

        Livewire::test(CreateWorkOrder::class)
            ->fillForm([
                'building_id' => $this->b['building']->id,
                'unit' => 'Ascensor 1',
                'type' => 'claim',
                'priority' => 'medium',
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasFormErrors(['building_id']);

        Livewire::test(CreateWorkOrder::class)
            ->fillForm([
                'building_id' => $this->a['building']->id,
                'unit' => 'Ascensor 1',
                'users' => [$this->b['technician']->id],
                'type' => 'claim',
                'priority' => 'medium',
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasFormErrors(['users.0']);

        Livewire::test(CreateQuote::class)
            ->fillForm([
                'client_id' => $this->b['building']->client_id,
                'building_id' => $this->b['building']->id,
                'title' => 'Presupuesto ajeno',
                'amount' => 100,
                'status' => 'pending',
                'priority' => 'normal',
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id', 'building_id']);

        Livewire::test(CreateReport::class)
            ->fillForm([
                'building_id' => $this->b['building']->id,
                'elevator_number' => '1',
                'description' => 'Reporte ajeno',
                'priority' => 'baja',
                'status' => 'pendiente',
            ])
            ->call('create')
            ->assertHasFormErrors(['building_id']);

        $this->assertSame(0, Building::withoutGlobalScopes()->where('name', 'Con cliente ajeno')->count());
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());
        $this->assertSame(0, Quote::withoutGlobalScopes()->count());
        $this->assertSame(0, Report::withoutGlobalScopes()->count());
    }

    public function test_work_order_can_be_created_and_assigned_within_own_company(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateWorkOrder::class)
            ->fillForm([
                'building_id' => $this->a['building']->id,
                'unit' => 'Ascensor 1',
                'users' => [$this->a['technician']->id],
                'type' => 'claim',
                'priority' => 'high',
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $workOrder = WorkOrder::withoutGlobalScopes()->sole();

        $this->assertSame($this->a['company']->id, $workOrder->company_id);
        $this->assertEquals([$this->a['technician']->id], $workOrder->users()->pluck('users.id')->all());
    }

    public function test_assign_technician_action_rejects_other_company_users(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->a['building'], data: [
                'user_ids' => [$this->b['technician']->id],
                'type' => 'inspection',
            ]);

        $this->assertFalse(
            $this->a['building']->users()->whereKey($this->b['technician']->id)->exists()
        );

        // Control positivo: un técnico propio sí se asigna.
        $other = User::factory()->technician()->create();

        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->a['building'], data: [
                'user_ids' => [$other->id],
                'type' => 'inspection',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($this->a['building']->users()->whereKey($other->id)->exists());
        $this->assertSame($this->a['company']->id, $other->fresh()->company_id);
    }

    /*
    |--------------------------------------------------------------------------
    | BORRADO DE REGISTROS DE OTRA EMPRESA
    |--------------------------------------------------------------------------
    */

    public function test_bulk_delete_cannot_touch_other_company_records(): void
    {
        $extraA = Building::factory()->create(['company_id' => $this->a['company']->id]);

        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->callTableBulkAction('delete', [$extraA->id, $this->b['building']->id]);

        // Lo propio se desactiva (soft delete); lo ajeno ni se toca.
        $this->assertSoftDeleted('buildings', ['id' => $extraA->id]);
        $this->assertNull(Building::withoutGlobalScopes()->find($this->b['building']->id)->deleted_at);
    }

    public function test_delete_action_on_other_company_record_is_impossible(): void
    {
        $woB = WorkOrder::factory()->create(['building_id' => $this->b['building']->id]);

        $this->actingInPanel($this->a['admin']);

        // El componente ni siquiera monta un registro de otra empresa.
        Livewire::test(EditWorkOrder::class, ['record' => $woB->getRouteKey()])
            ->assertNotFound();

        $this->assertNotNull(WorkOrder::withoutGlobalScopes()->find($woB->id));
    }
}
