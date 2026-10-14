<?php

namespace Tests\Feature\SuperAdmin;

use App\Filament\Pages\CompanyOverview;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Building;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Contadores por empresa en las vistas del SuperAdmin. El filtro global por
 * empresa (BelongsToCompany) usa la empresa elegida con «Entrar»: dentro del
 * conteo de cada fila dejaba en 0 a todas las empresas salvo la elegida.
 */
class CompanyCountsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $super;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::factory()->superAdmin()->create();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        Building::factory()->count(2)->create(['company_id' => $this->a['company']->id]); // A: 3, B: 1

        $this->actingAs($this->super);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function assertBuildingCounts(int $a, int $b): void
    {
        Livewire::test(ListCompanies::class)
            ->assertTableColumnStateSet('buildings_count', $a, $this->a['company'])
            ->assertTableColumnStateSet('buildings_count', $b, $this->b['company']);
    }

    public function test_the_first_load_shows_each_company_buildings(): void
    {
        $this->assertBuildingCounts(3, 1);
    }

    public function test_entering_a_company_and_coming_back_does_not_change_the_counts(): void
    {
        session(['selected_company_id' => $this->a['company']->id]); // «Entrar»
        $this->assertBuildingCounts(3, 1);

        session(['selected_company_id' => $this->b['company']->id]);
        $this->assertBuildingCounts(3, 1);
    }

    public function test_counts_follow_new_and_deleted_buildings(): void
    {
        Building::factory()->create(['company_id' => $this->b['company']->id]);
        $this->a['building']->delete(); // baja (soft delete): deja de contarse, como en el resto del panel

        $this->assertBuildingCounts(2, 2);
    }

    public function test_the_count_is_one_query_for_all_rows(): void
    {
        $this->makeTenant();
        DB::enableQueryLog();
        Livewire::test(ListCompanies::class);
        $standalone = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^select [^()]* from ["`]buildings["`]/i', $sql));

        $this->assertCount(0, $standalone, 'Sin una consulta de edificios por fila (N+1).');
    }

    public function test_the_company_overview_counts_ignore_the_selected_company(): void
    {
        session(['selected_company_id' => $this->a['company']->id]);

        $stats = Livewire::test(CompanyOverview::class, ['company' => $this->b['company']])->instance()->getStats();

        $this->assertSame(1, $stats['Edificios']);
        $this->assertSame(2, $stats['Usuarios']);
    }
}
