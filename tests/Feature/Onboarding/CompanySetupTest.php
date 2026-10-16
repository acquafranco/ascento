<?php

namespace Tests\Feature\Onboarding;

use App\Exceptions\PlanLimitReachedException;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\HelpCenter;
use App\Livewire\AdminOnboarding;
use App\Livewire\CompanySetupCoach;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Onboarding\CompanySetup;
use App\Services\Staff\StaffManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * Configuración inicial guiada de empresas nuevas: de la bienvenida a la
 * primera orden asignada a un técnico, con registros reales, estado por
 * empresa, pausa y retoma, y sin tocar a las empresas que ya existían.
 */
class CompanySetupTest extends TestCase
{
    use InteractsWithTenants, PassesRegistrationChallenge, RefreshDatabase;

    private function newCompany(): array
    {
        $company = Company::factory()->create();
        $company->forceFill(['setup_status' => CompanySetup::PENDING])->save();
        $owner = User::factory()->admin()->create(['company_id' => $company->id]);

        return [$company->fresh(), $owner->fresh()];
    }

    private function progress(Company $company): array
    {
        return app(CompanySetup::class)->progress($company->fresh());
    }

    public function test_registration_starts_the_setup_and_existing_companies_never_see_it(): void
    {
        $this->withoutVite();
        $old = $this->makeTenant();
        $this->giveCompanyPlan($old['company'], 'profesional');
        $this->assertNull($old['company']->fresh()->setup_status);
        $this->actingInPanel($old['admin'])->get('/admin/company-settings')->assertOk()->assertDontSee('data-company-setup', false);

        auth()->logout();
        $this->flushSession();
        $this->post('/register', [
            ...$this->registrationProof(),
            'company_name' => 'Ascensores Nuevos', 'name' => 'Dueña Nueva', 'email' => 'nueva@example.com',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241',
            'cuit' => '30-71234567-1', 'phone' => '11 4567-8900', 'province' => 'Buenos Aires', 'locality' => 'Lomas de Zamora', 'terms' => '1',
        ])->assertRedirect();

        $owner = User::where('email', 'nueva@example.com')->sole();
        $this->assertSame(CompanySetup::PENDING, $owner->company->setup_status);
        $this->actingInPanel($owner->fresh())->get('/admin/company-settings')->assertOk()
            ->assertSee('data-setup-welcome', false)->assertSee('¡Bienvenido a Ascento!')
            // La introducción del menú espera hasta que termine la configuración.
            ->assertSee('data-autostart="false"', false);
    }

    public function test_progress_comes_from_real_records_created_with_the_usual_flows(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);

        Livewire::test(CompanySetupCoach::class)->call('start');
        $this->assertSame('company', $this->progress($company)['steps'][$this->progress($company)['current']]['key']);

        // «Mi empresa» guardada desde su formulario de siempre.
        Livewire::test(CompanySettings::class)->fillForm(['name' => 'Ascensores Nuevos SRL', 'email' => 'hola@nuevos.test', 'phone' => '11 4567-8900'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->progress($company)['steps'][0]['done']);

        $tech = User::factory()->technician()->create(['company_id' => $company->id, 'name' => 'Juan Técnico']);
        $client = Client::factory()->create(['company_id' => $company->id]);
        $this->assertSame('building', $this->progress($company)['steps'][$this->progress($company)['current']]['key']);

        // Un edificio sin equipos no alcanza.
        $building = Building::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
        Elevator::withoutGlobalScopes()->where('building_id', $building->id)->delete();
        $this->assertSame('building', $this->progress($company)['steps'][$this->progress($company)['current']]['key']);
        Elevator::withoutGlobalScopes()->forceCreate(['company_id' => $company->id, 'building_id' => $building->id, 'label' => 'Ascensor 1', 'kind' => 'elevator', 'is_active' => true]);

        // Una orden sin técnico tampoco: tiene que estar asignada.
        $order = WorkOrder::factory()->create(['company_id' => $company->id, 'building_id' => $building->id]);
        $order->users()->detach();
        $this->assertSame('work_order', $this->progress($company)['steps'][$this->progress($company)['current']]['key']);
        $order->users()->attach($tech->id);
        $progress = $this->progress($company);
        $this->assertSame('technician_view', $progress['steps'][$progress['current']]['key']);
        $this->assertSame($order->id, $progress['order']->id);

        // Vista del técnico con los datos reales de su orden, y el cierre.
        Livewire::test(CompanySetupCoach::class)->call('openPreview')->assertSee('Así ve Juan Técnico su orden')->assertSee($building->name)
            ->call('acknowledgeTechnicianView')->assertSee('¡Felicitaciones!')
            ->call('finish')->assertRedirect(CompanySetup::dashboardUrl().'?configuracion=lista');

        $company->refresh();
        $this->assertSame(CompanySetup::COMPLETED, $company->setup_status);
        $this->assertNotNull($company->setup_completed_at);
        // Nada ficticio: solo lo que se cargó.
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->where('company_id', $company->id)->count());

        // Después sigue la ayuda de inicio con los próximos pasos (sin repetir los de la configuración).
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('data-company-setup', false)->assertSee('data-autostart="true"', false);
        $keys = array_column(AdminOnboarding::steps($owner->fresh()), 'key');
        $this->assertNotContains('technicians', $keys);
        Livewire::test(AdminOnboarding::class)->assertSee('Próximos pasos')->assertSee('Asigná técnicos a tus edificios');
    }

    public function test_finishing_early_or_skipping_steps_is_not_possible(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);
        Livewire::test(CompanySetupCoach::class)->call('start')->call('finish')->assertStatus(422);
        Livewire::test(CompanySetupCoach::class)->call('acknowledgeTechnicianView')->assertStatus(422);
        $this->assertSame(CompanySetup::PENDING, $company->fresh()->setup_status);
        $this->assertNotContains('technician_view', (array) $company->fresh()->setup_acks);
    }

    public function test_pausing_keeps_progress_and_it_can_be_resumed_from_help(): void
    {
        $this->withoutVite();
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);
        Livewire::test(CompanySetupCoach::class)->call('start')->call('pause');
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('data-company-setup', false);
        $this->assertContains('welcome', (array) $company->fresh()->setup_acks);

        // Nueva sesión: vuelve sola, donde quedó.
        $this->flushSession();
        $this->actingInPanel($owner->fresh())->get('/admin/dashboard')->assertOk()->assertSee('data-setup-panel', false)->assertDontSee('data-setup-welcome', false);

        // Omitida: ya no vuelve sola, pero se retoma desde Ayuda.
        Livewire::test(CompanySetupCoach::class)->call('skip');
        $this->assertSame(CompanySetup::SKIPPED, $company->fresh()->setup_status);
        $this->flushSession();
        $this->actingInPanel($owner->fresh())->get('/admin/dashboard')->assertOk()->assertDontSee('data-company-setup', false);
        $this->get('/admin/ayuda')->assertOk()->assertSee('data-help-setup', false);
        Livewire::test(HelpCenter::class)->call('resumeSetup')->assertRedirect();
        $this->assertSame(CompanySetup::PENDING, $company->fresh()->setup_status);
        $this->get('/admin/dashboard')->assertOk()->assertSee('data-setup-panel', false);
    }

    public function test_state_is_per_company_and_only_the_owner_uses_it(): void
    {
        [$a, $ownerA] = $this->newCompany();
        [$b, $ownerB] = $this->newCompany();

        // El progreso de una empresa no cuenta para otra.
        $techA = User::factory()->technician()->create(['company_id' => $a->id]);
        $this->assertTrue($this->progress($a)['steps'][1]['done']);
        $this->assertFalse($this->progress($b)['steps'][1]['done']);
        $this->actingInPanel($ownerA);
        Livewire::test(CompanySetupCoach::class)->call('start');
        $this->assertContains('welcome', (array) $a->fresh()->setup_acks);
        $this->assertNull($b->fresh()->setup_acks);

        // Un administrativo, un técnico o el SuperAdmin no la usan ni la cambian.
        $this->giveCompanyPlan($a, 'empresa');
        $this->actingAs($ownerA);
        $staff = app(StaffManager::class)->create($ownerA, ['name' => 'Ofi', 'email' => 'ofi@demo.test', 'password' => 'clave-segura-123'], ['work_orders' => 'manage']);
        foreach ([$staff, $techA, User::factory()->superAdmin()->create()] as $user) {
            $this->flushSession();
            $this->actingInPanel($user->fresh());
            $this->assertFalse(CompanySetup::isActiveFor($user->fresh()));
            Livewire::test(CompanySetupCoach::class)->call('skip')->assertForbidden();
        }
        $this->assertSame(CompanySetup::PENDING, $a->fresh()->setup_status);
    }

    public function test_plan_limits_still_apply_while_guiding(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->giveCompanyPlan($company, 'inicial');
        $this->actingAs($owner);
        User::factory()->count(3)->technician()->create(['company_id' => $company->id]);
        $this->expectException(PlanLimitReachedException::class);
        User::create(['name' => 'Uno más', 'email' => 'mas@demo.test', 'password' => 'x', 'role' => 'technician', 'company_id' => $company->id]);
    }
}
