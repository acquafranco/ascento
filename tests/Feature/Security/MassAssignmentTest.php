<?php

namespace Tests\Feature\Security;

use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Barreras a nivel modelo: aunque un form/endpoint futuro deje pasar
 * company_id o is_super_admin desde el request, el modelo no lo acepta.
 */
class MassAssignmentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_tenant_models_are_always_created_in_the_actor_company(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $this->actingAs($a['admin']);

        $client = Client::create([
            'company_id' => $b['company']->id,
            'name' => 'Inyectado',
            'type' => 'consorcio',
        ]);

        $building = Building::create([
            'company_id' => $b['company']->id,
            'client_id' => $client->id,
            'name' => 'Edificio',
            'address' => '1',
        ]);

        $workOrder = WorkOrder::create([
            'company_id' => $b['company']->id,
            'building_id' => $building->id,
            'type' => 'claim',
        ]);

        $this->assertSame($a['company']->id, $client->fresh()->company_id);
        $this->assertSame($a['company']->id, Building::withoutGlobalScopes()->find($building->id)->company_id);
        $this->assertSame($a['company']->id, WorkOrder::withoutGlobalScopes()->find($workOrder->id)->company_id);
    }

    public function test_tenant_models_cannot_be_moved_to_another_company(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $this->actingAs($a['admin']);

        $a['building']->update(['company_id' => $b['company']->id, 'name' => 'Renombrado']);

        $fresh = Building::withoutGlobalScopes()->find($a['building']->id);

        $this->assertSame($a['company']->id, $fresh->company_id);
        $this->assertSame('Renombrado', $fresh->name, 'El resto del update se aplica normalmente.');
    }

    public function test_is_super_admin_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'x',
            'email' => 'x@example.com',
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->isSuperAdmin());

        $user->update(['is_super_admin' => true]);

        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_company_admin_cannot_move_users_or_grant_super_admin(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $this->actingAs($a['admin']);

        $created = User::create([
            'company_id' => $b['company']->id,
            'name' => 'Nuevo',
            'email' => 'nuevo@example.com',
            'password' => 'password',
        ]);

        $this->assertSame($a['company']->id, $created->fresh()->company_id);

        $technician = $a['technician'];
        $technician->forceFill([
            'company_id' => $b['company']->id,
            'is_super_admin' => true,
        ])->save();

        $technician->refresh();

        $this->assertSame($a['company']->id, $technician->company_id);
        $this->assertFalse($technician->isSuperAdmin());
    }

    public function test_super_admin_creates_records_in_the_selected_company(): void
    {
        $a = $this->makeTenant();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->withSession(['selected_company_id' => $a['company']->id]);
        session(['selected_company_id' => $a['company']->id]);

        $client = Client::create(['name' => 'Del superadmin', 'type' => 'empresa']);

        $this->assertSame($a['company']->id, $client->fresh()->company_id);
    }
}
