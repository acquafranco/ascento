<?php

namespace Tests\Feature\Performance;

use App\Models\Company;
use App\Models\User;
use App\Services\Staff\StaffManager;
use App\Support\Access\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Presupuesto de consultas por página del panel. El menú, el botón «?» y la
 * ayuda llegaron a hacer ~4.000 consultas (dueño) y ~13.000 (administrativo)
 * por página, porque verificaban plan y permisos por cada artículo de ayuda
 * sin memoria. Con RequestMemo quedan en unas 20.
 */
class PanelQueryBudgetTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const BUDGET = 60;

    private function queriesFor(User $user, string $url): int
    {
        $this->flushSession();
        $this->actingInPanel($user->fresh());
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->get($url)->assertOk();

        return $count;
    }

    public function test_panel_pages_stay_within_the_query_budget_for_owner_and_staff(): void
    {
        $this->withoutVite();
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'empresa');
        $this->actingAs($a['admin']);
        $staff = app(StaffManager::class)->create($a['admin'], ['name' => 'Oficina', 'email' => 'of@demo.test', 'password' => 'clave-segura-123'], ['clients' => 'manage', 'buildings' => 'manage', 'work_orders' => 'manage', 'visits' => 'view', 'technicians' => 'view']);

        foreach (['/admin/dashboard', '/admin/clients', '/admin/work-orders', '/admin/ayuda', '/admin/mapa?vista=tecnicos'] as $url) {
            foreach (['dueño' => $a['admin'], 'administrativo' => $staff] as $who => $user) {
                $n = $this->queriesFor($user, $url);
                $this->assertLessThan(self::BUDGET, $n, "{$url} ({$who}) hizo {$n} consultas.");
            }
        }
    }

    public function test_the_memory_never_keeps_an_old_company_state_after_a_write(): void
    {
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'empresa');
        $this->actingAs($a['admin']);
        $staff = app(StaffManager::class)->create($a['admin'], ['name' => 'Oficina', 'email' => 'of2@demo.test', 'password' => 'clave-segura-123'], ['clients' => 'view']);

        $this->assertTrue(Access::isActiveStaff($staff));
        // Escritura masiva (sin eventos del modelo): igual se ve el cambio.
        Company::whereKey($a['company']->id)->update(['is_active' => false]);
        $this->assertFalse(Access::isActiveStaff($staff->fresh()));
        DB::table('companies')->where('id', $a['company']->id)->update(['is_active' => true]);
        $this->assertTrue(Access::isActiveStaff($staff->fresh()));
    }
}
