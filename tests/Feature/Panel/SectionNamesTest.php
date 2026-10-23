<?php

namespace Tests\Feature\Panel;

use App\Enums\PermissionArea;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Claims\ClientClaimResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * El menú distingue lo interno de lo externo: «Reportes de técnicos» (lo que
 * cargan los técnicos) y «Reclamos y avisos externos» (portal y QR), cada uno
 * explicando dónde está el otro. Solo cambian los nombres: permisos y datos
 * siguen igual.
 */
class SectionNamesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @return list<string> */
    private function menuLabels(User $user): array
    {
        $this->actingInPanel($user);
        $this->get(Dashboard::getUrl())->assertOk();

        return collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map->getLabel())->values()->all();
    }

    public function test_internal_reports_and_external_claims_have_distinct_names_and_explain_each_other(): void
    {
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'empresa');
        $labels = $this->menuLabels($a['admin']);

        $this->assertContains('Reportes de técnicos', $labels);
        $this->assertContains('Reclamos y avisos externos', $labels);
        $this->assertNotContains('Reportes', $labels);
        $this->assertNotContains('Reclamos y reportes', $labels);

        $this->get(ReportResource::getUrl())->assertOk()->assertSee('Lo que registran tus técnicos durante su trabajo')->assertSee('«Reclamos y avisos externos»');
        $this->get(ClientClaimResource::getUrl())->assertOk()->assertSee('Lo que llega desde afuera')->assertSee('«Reportes de técnicos»');
    }

    public function test_permissions_did_not_change_with_the_names(): void
    {
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'empresa');
        auth()->logout();
        $this->flushSession();

        // Un administrativo sin «Inspecciones y reportes» sigue sin ver los reportes de técnicos.
        $staff = User::factory()->create(['company_id' => $a['company']->id, 'role' => User::ROLE_STAFF]);
        $staff->forceFill(['permissions' => [PermissionArea::Clients->key(PermissionArea::VIEW)]])->save();
        $labels = $this->menuLabels($staff->fresh());
        $this->assertNotContains('Reportes de técnicos', $labels);
        $this->get(ReportResource::getUrl())->assertForbidden();

    }

    public function test_each_company_still_sees_only_its_own_reports_and_claims(): void
    {
        $a = $this->makeTenant();
        $a['building']->forceFill(['name' => 'Torre Exclusiva de A'])->save();
        Report::create(['company_id' => $a['company']->id, 'user_id' => $a['technician']->id, 'building_id' => $a['building']->id,
            'elevator_number' => 'Ascensor 1', 'description' => 'Falla privada de A', 'priority' => 'media']);
        $b = $this->makeTenant();
        auth()->logout();

        $this->actingInPanel($b['admin']);
        $this->get(ReportResource::getUrl())->assertOk()->assertDontSee('Torre Exclusiva de A');
        $this->get(ClientClaimResource::getUrl())->assertOk()->assertDontSee('Torre Exclusiva de A');
    }
}
