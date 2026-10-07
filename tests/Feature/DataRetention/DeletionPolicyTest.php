<?php

namespace Tests\Feature\DataRetention;

use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Nada que forme parte del historial (remitos, visitas, órdenes con
 * remito, reportes, presupuestos) puede desaparecer porque se "borre"
 * la entidad padre.
 */
class DeletionPolicyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private DeliveryNote $note;

    private BuildingVisit $visit;

    private WorkOrder $workOrder;

    private Report $report;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();

        $this->workOrder = WorkOrder::factory()->create([
            'building_id' => $this->a['building']->id,
            'status' => 'completed',
        ]);
        $this->workOrder->users()->attach($this->a['technician']->id);
        $this->workOrder->participants()->attach($this->a['technician']->id, ['role' => 'creator']);

        $this->visit = BuildingVisit::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ]);
        $this->visit->participants()->attach($this->a['technician']->id, ['role' => 'creator']);

        $this->note = DeliveryNote::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
            'building_visit_id' => $this->visit->id,
            'work_order_id' => $this->workOrder->id,
            'description' => 'TRABAJO HISTÓRICO',
        ]);

        $this->report = Report::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ]);

        $this->quote = Quote::factory()->create([
            'building_id' => $this->a['building']->id,
            'created_by' => $this->a['admin']->id,
        ]);
    }

    private function assertHistoryIntact(): void
    {
        $this->assertNotNull(DeliveryNote::withoutGlobalScopes()->find($this->note->id));
        $this->assertNotNull(BuildingVisit::withoutGlobalScopes()->find($this->visit->id));
        $this->assertNotNull(WorkOrder::withoutGlobalScopes()->withTrashed()->find($this->workOrder->id));
        $this->assertNotNull(Report::withoutGlobalScopes()->withTrashed()->find($this->report->id));
        $this->assertNotNull(Quote::withoutGlobalScopes()->withTrashed()->find($this->quote->id));
        $this->assertSame(1, $this->visit->participants()->count());
        $this->assertSame(1, $this->workOrder->participants()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | TÉCNICOS
    |--------------------------------------------------------------------------
    */

    public function test_admin_deactivates_technician_and_history_is_kept(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditUser::class, ['record' => $this->a['technician']->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($this->a['technician']);
        $this->assertHistoryIntact();

        // El remito sigue mostrando quién lo hizo.
        $this->actingAs($this->a['admin'])
            ->get("/{$this->a['company']->slug}/delivery-notes/{$this->note->number}/pdf")
            ->assertOk()
            ->assertSee($this->a['technician']->name)
            ->assertSee('TRABAJO HISTÓRICO');
    }

    public function test_deactivated_technician_can_be_reactivated(): void
    {
        $this->actingAs($this->a['admin']);
        $this->a['technician']->delete();

        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListUsers::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$this->a['technician']])
            ->callTableAction('restore', $this->a['technician']);

        $this->assertNotSoftDeleted($this->a['technician']);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditUser::class, ['record' => $this->a['admin']->getRouteKey()])
            ->assertActionHidden('delete');

        // Ni siquiera por código / bulk action.
        $this->a['admin']->delete();
        $this->assertNotSoftDeleted($this->a['admin']);
    }

    /*
    |--------------------------------------------------------------------------
    | EDIFICIOS Y CLIENTES
    |--------------------------------------------------------------------------
    */

    public function test_deactivating_building_keeps_history_and_hides_it_from_operations(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditBuilding::class, ['record' => $this->a['building']->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($this->a['building']);
        $this->assertHistoryIntact();

        $slug = $this->a['company']->slug;
        $name = $this->a['building']->name;

        // Ya no aparece para operar...
        $this->actingAs($this->a['technician'])->get("/{$slug}/buildings")->assertDontSee($name);
        $this->actingAs($this->a['technician'])
            ->get("/{$slug}/delivery-notes/create/building/{$this->a['building']->id}")
            ->assertNotFound();

        // ...pero el historial sigue mostrándolo.
        $this->actingAs($this->a['technician'])
            ->get("/{$slug}/delivery-notes/{$this->note->number}")
            ->assertOk()
            ->assertSee($name);
    }

    public function test_deactivated_building_can_be_reactivated(): void
    {
        $this->actingAs($this->a['admin']);
        $this->a['building']->delete();

        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $this->a['building']);

        $this->assertNotSoftDeleted($this->a['building']);
    }

    public function test_deactivating_client_keeps_buildings_and_history(): void
    {
        $client = $this->a['building']->client;

        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($client);
        $this->assertNotSoftDeleted($this->a['building']);
        $this->assertHistoryIntact();
        $this->assertSame($client->name, $this->a['building']->fresh()->client->name);
    }

    /*
    |--------------------------------------------------------------------------
    | REMITOS, MANTENIMIENTOS, ÓRDENES
    |--------------------------------------------------------------------------
    */

    public function test_delivery_notes_cannot_be_deleted(): void
    {
        $this->actingInPanel($this->a['admin']);

        $this->assertFalse(DeliveryNoteResource::canDelete($this->note));
        $this->assertFalse(DeliveryNoteResource::canDeleteAny());
        $this->assertFalse(MaintenanceResource::canDelete($this->note));

        $this->assertFalse($this->note->delete());
        $this->assertNotNull(DeliveryNote::find($this->note->id));
    }

    public function test_maintenance_with_delivery_note_cannot_be_unmarked(): void
    {
        $this->actingAs($this->a['technician'])
            ->post("/{$this->a['company']->slug}/building-check/{$this->a['building']->id}/done", [
                'assignment_type' => 'maintenance',
                'month' => $this->visit->month,
                'year' => $this->visit->year,
            ])
            ->assertSessionHasErrors('general');

        $this->assertNotNull(BuildingVisit::find($this->visit->id));
    }

    public function test_work_order_with_history_cannot_be_deleted(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditWorkOrder::class, ['record' => $this->workOrder->getRouteKey()])
            ->assertActionHidden('delete');

        $this->assertFalse($this->workOrder->delete());
        $this->assertNotSoftDeleted($this->workOrder);
    }

    public function test_pending_work_order_without_history_can_be_deleted_softly(): void
    {
        $pending = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);

        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditWorkOrder::class, ['record' => $pending->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($pending);
    }

    public function test_quotes_are_soft_deleted(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($this->quote);
    }

    /*
    |--------------------------------------------------------------------------
    | ÚLTIMA BARRERA: LA BASE DE DATOS
    |--------------------------------------------------------------------------
    */

    public function test_physical_delete_of_parents_with_history_is_rejected_by_the_database(): void
    {
        foreach ([
            fn () => $this->a['building']->forceDelete(),
            fn () => $this->a['technician']->forceDelete(),
            fn () => Client::withoutGlobalScopes()->find($this->a['building']->client_id)->forceDelete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('El borrado físico debió fallar por la FK restrict.');
            } catch (QueryException) {
                // Esperado.
            }
        }

        $this->assertHistoryIntact();
        $this->assertNotNull(Building::withoutGlobalScopes()->find($this->a['building']->id));
        $this->assertNotNull(User::find($this->a['technician']->id));
    }

    /*
    |--------------------------------------------------------------------------
    | EMPRESAS
    |--------------------------------------------------------------------------
    */

    public function test_super_admin_deactivates_company_keeping_all_data(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingInPanel($superAdmin);

        Livewire::test(EditCompany::class, ['record' => $this->a['company']->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($this->a['company']);
        $this->assertHistoryIntact();
        $this->assertNotSoftDeleted($this->a['building']);

        // Sus usuarios pierden el acceso.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['technician']->fresh())
            ->get("/{$this->a['company']->slug}/dashboard")
            ->assertNotFound();
        // El admin no ve nada: se cierra su sesión y vuelve al login con un aviso.
        $this->actingAs($this->a['admin']->fresh())
            ->get('/admin/buildings')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', \App\Support\HomeRedirect::NO_ACCESS_MESSAGE);
        $this->assertGuest();
    }
}
