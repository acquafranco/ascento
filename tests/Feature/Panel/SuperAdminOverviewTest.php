<?php

namespace Tests\Feature\Panel;

use App\Filament\Widgets\AdminStats;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Inicio del SuperAdmin: estado de la plataforma; nunca para admins de empresa. */
class SuperAdminOverviewTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_the_superadmin_sees_the_platform_and_company_admins_see_only_their_company(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $b['admin']->forceFill(['email_verified_at' => null])->save();
        $super = User::factory()->create(['is_super_admin' => true]);

        $this->actingInPanel($super)->get('/admin/dashboard')->assertOk()
            ->assertSee('Salud operativa')->assertSee('Correo sin confirmar')->assertSee('calculado')
            ->assertSee($a['company']->name)->assertSee($b['company']->name)
            ->assertDontSeeLivewire(AdminStats::class); // los números de una empresa no

        $this->flushSession();
        $this->actingInPanel($a['admin'])->get('/admin/dashboard')->assertOk()
            ->assertDontSee('Salud operativa')->assertDontSee($b['company']->name)
            ->assertSeeLivewire(AdminStats::class);

        // El SuperAdmin dentro de una empresa ve los números de esa empresa.
        $this->flushSession();
        $this->actingInPanel($super)->withSession(['selected_company_id' => $a['company']->id])->get('/admin/dashboard')->assertOk()
            ->assertSeeLivewire(AdminStats::class)->assertDontSee('Salud operativa');
    }
}
