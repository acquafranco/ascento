<?php

namespace Tests\Feature\Technicians;

use App\Filament\Resources\Users\Pages\TechnicianSheetPage;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\User;
use App\Services\Staff\StaffManager;
use App\Services\Technicians\TechnicianSheet;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Planillas de los técnicos: cada técnico ve solo la suya; la de un técnico
 * la ve el dueño de su empresa (en el panel). Nadie más: ni otro técnico, ni
 * un administrativo, ni otra empresa. Y la planilla dice lo correcto: sus
 * trabajos, día por día, sin los de otros ni los que están en curso.
 */
class TechnicianSheetTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private User $other;

    private Building $arenales;

    private Building $juncal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-20 12:00'));
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->a['technician']->forceFill(['name' => 'Juan Pérez'])->save();
        $this->other = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'name' => 'Ana Técnica']);
        $this->arenales = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Arenales 1200']);
        $this->juncal = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Juncal 900']);
    }

    private function visit(Building $building, User $by, string $type, string $at, array $participants = [], array $extra = []): BuildingVisit
    {
        $visit = BuildingVisit::factory()->create(['building_id' => $building->id, 'user_id' => $by->id, 'assignment_type' => $type, 'visited_at' => Carbon::parse($at), ...$extra]);
        foreach ($participants as $user) {
            $visit->participants()->attach($user->id, ['role' => $user->id === $by->id ? 'creator' : 'participant']);
        }

        return $visit;
    }

    /** Juan: un mantenimiento propio, una inspección propia y uno con Ana. Ana: uno sola. */
    private function seedWork(): void
    {
        $juan = $this->a['technician'];
        $this->visit($this->arenales, $juan, 'maintenance', '2026-10-06 09:30', [$juan]);
        $this->visit($this->arenales, $juan, 'inspection', '2026-10-07 11:00');
        $this->visit($this->juncal, $this->other, 'maintenance', '2026-10-07 15:00', [$this->other, $juan]);
        $this->visit($this->juncal, $this->other, 'inspection', '2026-10-08 10:00'); // solo de Ana
        // En curso (sin remito): no es historial.
        $this->visit($this->juncal, $juan, 'maintenance', '2026-10-09 10:00', [$juan], ['in_progress' => true, 'slot' => 2]);
    }

    private function slug(): string
    {
        return $this->a['company']->slug;
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    public function test_the_technician_sees_only_his_own_work_ordered_by_day(): void
    {
        $this->seedWork();

        $sheet = app(TechnicianSheet::class)->month($this->a['technician'], 10, 2026);
        $this->assertSame(['total' => 3, 'maintenance' => 2, 'inspection' => 1, 'work_order' => 0, 'days' => 2], $sheet['summary']);

        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/my-templates?month=10&year=2026")
            ->assertOk()
            ->assertSee('Mi planilla')
            ->assertSeeInOrder(['Martes 06', 'Arenales 1200', 'Miércoles 07', 'Arenales 1200', 'Inspección', 'Juncal 900', 'Participó con Ana Técnica'])
            ->assertDontSee('Jueves 08')   // la inspección de Ana
            ->assertDontSee('Viernes 09'); // en curso

        // Ana ve lo suyo (incluido el que hizo con Juan), no lo de Juan solo.
        $this->actingAs($this->other)->get("/{$this->slug()}/my-templates?month=10&year=2026")
            ->assertOk()->assertSee('Juncal 900')->assertSee('Con Juan Pérez')->assertDontSee('Arenales 1200');

        // El detalle del día, igual.
        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/my-templates/day/2026-10-08")->assertOk()->assertSee('No hay trabajos registrados');
        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/my-templates/day/2026-10-07")->assertOk()->assertSee('Juncal 900')->assertSee('Inspección');
    }

    public function test_a_technician_can_never_open_another_technicians_sheet(): void
    {
        $this->seedWork();
        $juan = $this->a['technician'];
        $this->actingAs($juan);

        // Ni por la dirección vieja del admin, ni por el panel.
        $this->get("/{$this->slug()}/users/{$this->other->id}/template")->assertNotFound();
        $this->get("/{$this->slug()}/users/{$this->other->id}/template/day/2026-10-08")->assertNotFound();
        $this->assertNotSame(200, $this->get(TechnicianSheetPage::getUrl(['record' => $this->other]))->status());

        $this->assertFalse(TechnicianSheet::canView($juan, $this->other));
        $this->assertTrue(TechnicianSheet::canView($juan, $juan));
    }

    public function test_the_owner_sees_any_technician_of_his_company_in_the_panel(): void
    {
        $this->seedWork();
        $this->inPanel($this->a['admin']);

        $this->get(TechnicianSheetPage::getUrl(['record' => $this->a['technician'], 'month' => 10, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Planilla de Juan Pérez')
            ->assertSeeInOrder(['Octubre 2026', 'Arenales 1200', 'Juncal 900'])
            ->assertDontSee('Lo hiciste vos');

        // Desde la ficha del técnico, «Ver planilla».
        Livewire::test(ViewUser::class, ['record' => $this->a['technician']->getRouteKey()])->assertActionVisible('sheet');

        // Las direcciones viejas llevan al panel.
        $this->get("/{$this->slug()}/users/{$this->a['technician']->id}/template?month=10&year=2026")
            ->assertRedirect(TechnicianSheetPage::getUrl(['record' => $this->a['technician'], 'month' => 10, 'year' => 2026]));
        $this->get("/{$this->slug()}/users/{$this->a['technician']->id}/template/day/2026-10-07")
            ->assertRedirect(TechnicianSheetPage::getUrl(['record' => $this->a['technician'], 'month' => 10, 'year' => 2026]));
    }

    public function test_staff_and_other_companies_cannot_see_any_sheet(): void
    {
        $this->seedWork();

        // Administrativo, aun con «Técnicos» para gestionar: no.
        $this->actingAs($this->a['admin']);
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina', 'email' => 'oficina@a.test', 'password' => 'clave-segura-123'], ['technicians' => 'manage']);
        $this->inPanel($staff);
        $this->get(TechnicianSheetPage::getUrl(['record' => $this->a['technician']]))->assertNotFound();
        Livewire::test(ViewUser::class, ['record' => $this->a['technician']->getRouteKey()])->assertActionHidden('sheet');
        $this->assertFalse(TechnicianSheet::canView($staff, $this->a['technician']));

        // Dueño de otra empresa: no existe para él.
        $this->inPanel($this->b['admin']);
        $this->assertNotSame(200, $this->get(TechnicianSheetPage::getUrl(['record' => $this->a['technician']]))->status());
        $this->assertFalse(TechnicianSheet::canView($this->b['admin'], $this->a['technician']));
        $this->actingAs($this->b['admin'])->get("/{$this->b['company']->slug}/users/{$this->a['technician']->id}/template")->assertNotFound();
    }

    public function test_work_of_another_company_never_shows_up(): void
    {
        $juan = $this->a['technician'];
        $foreign = $this->b['building'];
        // Una visita mal cargada con Juan como participante en otra empresa: no cuenta.
        $visit = BuildingVisit::factory()->create(['building_id' => $foreign->id, 'user_id' => $this->b['technician']->id, 'visited_at' => Carbon::parse('2026-10-10 10:00')]);
        $visit->participants()->attach($juan->id, ['role' => 'participant']);

        $this->assertSame(0, app(TechnicianSheet::class)->month($juan, 10, 2026)['summary']['total']);
        $this->actingAs($juan)->get("/{$this->slug()}/my-templates?month=10&year=2026")->assertOk()->assertDontSee($foreign->name);
    }

    public function test_month_navigation_is_validated(): void
    {
        $juan = $this->a['technician'];
        $this->actingAs($juan)->get("/{$this->slug()}/my-templates?month=13&year=2026")->assertRedirect();
        $this->actingAs($juan)->get("/{$this->slug()}/my-templates?month=9&year=2026")->assertOk()->assertSee('Septiembre 2026')->assertSee('No hay trabajos en septiembre 2026');

        $this->inPanel($this->a['admin']);
        $this->get(TechnicianSheetPage::getUrl(['record' => $juan, 'month' => 99, 'year' => 1]))->assertOk()->assertSee('Octubre 2026');
        $this->assertSame(UserResource::getUrl('sheet', ['record' => $juan]), TechnicianSheetPage::getUrl(['record' => $juan]));
    }
}
