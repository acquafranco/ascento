<?php

namespace Tests\Feature\Ux;

use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\StockItems\Pages\CreateStockItem;
use App\Models\Elevator;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Insights\ElevatorHistory;
use App\Services\Staff\StaffManager;
use Filament\Schemas\Components\Grid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * QA de experiencia de usuario: defectos encontrados en el navegador y
 * corregidos (ver el informe de la etapa).
 */
class QaFixesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        Subscription::create(['company_id' => $this->a['company']->id, 'provider' => 'mercadopago', 'plan' => 'empresa', 'status' => 'authorized', 'amount' => 1, 'current_period_end' => now()->addMonth()]);
    }

    public function test_the_floating_whatsapp_button_keeps_a_name_when_its_text_is_hidden_on_phones(): void
    {
        config(['app.whatsapp_contact' => '5491173728421']);

        $this->get('/')->assertOk()->assertSee('data-wa-launcher aria-haspopup="dialog" aria-label="Consultas por WhatsApp"', false);
    }

    /** Desde Filament 4 una grilla ocupa una sola de las dos columnas del formulario si no se indica otra cosa. */
    public function test_root_grids_of_these_forms_use_the_full_width(): void
    {
        $this->actingInPanel($this->a['admin']);

        foreach ([CreateMaintenanceService::class, CreateStockItem::class, CreateBuilding::class] as $page) {
            $grids = collect(Livewire::test($page)->instance()->getSchema('form')->getComponents())
                ->filter(fn ($component) => $component instanceof Grid);

            $this->assertNotEmpty($grids, $page);
            foreach ($grids as $grid) {
                $this->assertSame('full', $grid->getColumnSpan()['default'] ?? null, $page.': una grilla no ocupa todo el ancho');
            }
        }
    }

    public function test_the_item_subtotal_follows_quantity_and_price(): void
    {
        $this->actingInPanel($this->a['admin']);

        $page = Livewire::test(CreateQuote::class);
        $key = array_key_first($page->get('data.items'));

        $page->set("data.items.{$key}.quantity", 3)
            ->set("data.items.{$key}.unit_price", 425000)
            ->assertSet("data.items.{$key}.subtotal_preview", '$1.275.000,00');
    }

    public function test_the_elevator_history_only_links_to_records_the_viewer_can_open(): void
    {
        $elevator = Elevator::where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->sole();
        Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'elevator_number' => 'Ascensor 1', 'description' => 'Ruido en puertas']);

        // Administrativo que ve edificios y documentos, pero no reportes de inspección.
        $staff = User::factory()->create(['company_id' => $this->a['company']->id, 'role' => User::ROLE_STAFF,
            'permissions' => StaffManager::keysFromLevels(['buildings' => 'manage', 'documents' => 'view'])]);

        $this->actingInPanel($staff);
        $report = app(ElevatorHistory::class)->events($elevator, advanced: true)->firstWhere('type', 'report');
        $this->assertNotNull($report);
        $this->assertNull($report['url'], 'Sin permiso no debe mostrarse un enlace que termina en 403.');

        $this->actingInPanel($this->a['admin']);
        $report = app(ElevatorHistory::class)->events($elevator, advanced: true)->firstWhere('type', 'report');
        $this->assertNotNull($report['url']);
    }
}
