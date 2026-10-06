<?php

namespace Tests\Feature\Performance;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * La cantidad de queries de cada pantalla no debe crecer con la cantidad
 * de filas (N+1). Se compara la misma pantalla con 1 y con 10 registros.
 */
class NoNPlusOneQueriesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function seedRecords(array $tenant, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $building = Building::factory()->create(['company_id' => $tenant['company']->id]);
            $building->users()->attach($tenant['technician']->id, ['type' => 'maintenance']);

            $workOrder = WorkOrder::factory()->create(['building_id' => $building->id]);
            $workOrder->users()->attach($tenant['technician']->id);

            $visit = BuildingVisit::factory()->create([
                'building_id' => $building->id,
                'user_id' => $tenant['technician']->id,
            ]);
            $visit->participants()->attach($tenant['technician']->id, ['role' => 'creator']);

            DeliveryNote::factory()->create([
                'building_id' => $building->id,
                'user_id' => $tenant['technician']->id,
                'building_visit_id' => $visit->id,
                'work_order_id' => $workOrder->id,
            ]);
            Quote::factory()->create(['building_id' => $building->id]);
            Report::factory()->create(['building_id' => $building->id, 'user_id' => $tenant['technician']->id]);
            Company::factory()->create();
        }
    }

    /**
     * @return array<string, int>
     */
    private function queryCounts(int $records, User $superAdmin): array
    {
        // Cada medición usa su propia empresa: los datos de la otra no
        // cuentan gracias al aislamiento por tenant. El seeding se hace
        // como invitado (logueado, el modelo forzaría la empresa del usuario).
        $this->app['auth']->forgetGuards();

        $tenant = $this->makeTenant();
        $this->seedRecords($tenant, $records);
        $slug = $tenant['company']->slug;

        $pages = [
            'técnico: edificios' => [$tenant['technician'], "/{$slug}/buildings"],
            'técnico: órdenes' => [$tenant['technician'], "/{$slug}/work-orders"],
            'técnico: remitos' => [$tenant['technician'], "/{$slug}/delivery-notes"],
            'técnico: plantilla' => [$tenant['technician'], "/{$slug}/my-templates"],
            'admin: edificios' => [$tenant['admin'], '/admin/buildings'],
            'admin: órdenes' => [$tenant['admin'], '/admin/work-orders'],
            'admin: remitos' => [$tenant['admin'], '/admin/delivery-notes'],
            'admin: presupuestos' => [$tenant['admin'], '/admin/quotes'],
            'admin: reportes' => [$tenant['admin'], '/admin/reports'],
            'superadmin: empresas' => [$superAdmin, '/admin/companies'],
        ];

        $counts = [];

        foreach ($pages as $name => [$user, $url]) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($user)->get($url)->assertOk();

            $counts[$name] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    public function test_query_count_does_not_grow_with_rows(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $one = $this->queryCounts(1, $superAdmin);
        $many = $this->queryCounts(10, $superAdmin);

        foreach ($one as $page => $count) {
            $this->assertLessThanOrEqual($count, $many[$page], "N+1 en {$page}: {$count} queries con 1 fila, {$many[$page]} con 10.");
        }
    }
}
