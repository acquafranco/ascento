<?php

namespace Tests\Feature\Onboarding;

use App\Enums\PlanLimit;
use App\Filament\Pages\CompanySetupWizard;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HelpCenter;
use App\Jobs\SendWorkOrderAssignedNotification;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Onboarding\CompanySetup;
use App\Services\Staff\StaffManager;
use App\Support\Plans\PlanGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * Configuración inicial de empresas nuevas en un asistente propio: cada paso
 * se completa dentro del asistente (sin ir a las pantallas del menú), se
 * guarda al continuar con las mismas reglas del panel, se puede abandonar y
 * retomar, y nunca duplica registros ni cruza empresas.
 */
class CompanySetupTest extends TestCase
{
    use InteractsWithTenants, PassesRegistrationChallenge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function newCompany(): array
    {
        $company = Company::factory()->create(['name' => 'Ascensores Nuevos']);
        $company->forceFill(['setup_status' => CompanySetup::PENDING])->save();
        $owner = User::factory()->admin()->create(['company_id' => $company->id]);

        return [$company->fresh(), $owner->fresh()];
    }

    private function progress(Company $company): array
    {
        return app(CompanySetup::class)->progress($company->fresh());
    }

    private function technicianData(array $extra = []): array
    {
        return ['name' => 'Juan Técnico', 'email' => 'juan@nuevos.test', 'phone' => '11 2345-6789', 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', ...$extra];
    }

    /** Recorre el asistente completo como lo haría el dueño. */
    private function walkTheWizard(): Testable
    {
        return Livewire::test(CompanySetupWizard::class)
            ->assertSet('screen', 'welcome')->call('next')
            ->assertSet('screen', 'company')
            ->set('companyData.name', 'Ascensores del Sur')->set('companyData.phone', '011 4000-1000')->set('companyData.email', 'hola@sur.test')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'brand')
            ->set('brandData.primary_color', '#0E7490')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'technician')
            ->fillForm($this->technicianData(), 'technicianForm')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'client')
            ->fillForm(['name' => 'Consorcio Rivadavia 4000', 'type' => 'consorcio'], 'clientForm')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'building')
            ->fillForm(['name' => 'Rivadavia', 'address' => '4000', 'locality' => 'Caballito', 'equipment' => [
                'a' => ['id' => null, 'label' => null, 'kind' => 'elevator', 'machine_type' => array_key_first(Elevator::MACHINE_TYPES)],
                'b' => ['id' => null, 'label' => null, 'kind' => 'elevator', 'machine_type' => array_key_first(Elevator::MACHINE_TYPES)],
            ]], 'buildingForm')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'work_order')
            ->fillForm(['unit' => 'Ascensor 2', 'type' => 'claim', 'priority' => 'high', 'notes' => 'Ruido en la cabina'], 'orderForm')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'done');
    }

    public function test_registration_opens_the_wizard_and_existing_companies_never_see_it(): void
    {
        $old = $this->makeTenant();
        $this->assertNull($old['company']->fresh()->setup_status);
        $this->actingInPanel($old['admin'])->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-setup-resume-banner', false);
        $this->get(CompanySetupWizard::getUrl())->assertForbidden();

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

        // Cualquier pantalla del panel abre el asistente: una pantalla propia, sin el menú.
        $this->actingInPanel($owner->fresh())->get(Dashboard::getUrl())->assertRedirect(CompanySetupWizard::getUrl());
        $this->get('/admin/company-settings')->assertRedirect(CompanySetupWizard::getUrl());
        $this->get('/admin')->assertRedirect();
        $this->get(CompanySetupWizard::getUrl())->assertOk()
            ->assertSee('data-setup-wizard', false)->assertSee('Configuremos Ascensores Nuevos')
            ->assertSee('Empezar')->assertDontSee('<aside', false);
    }

    public function test_the_whole_setup_is_done_inside_the_wizard_with_the_usual_rules_and_effects(): void
    {
        Bus::fake([SendWorkOrderAssignedNotification::class]);
        [$company, $owner] = $this->newCompany();
        $this->giveCompanyPlan($company, 'empresa');
        $this->actingInPanel($owner);

        $wizard = $this->walkTheWizard()->assertSee('¡Listo, Ascensores del Sur ya está configurada!')->assertSee('Juan Técnico');

        $company->refresh();
        $this->assertSame(['Ascensores del Sur', '011 4000-1000', 'hola@sur.test', '#0E7490'], [$company->name, $company->phone, $company->email, $company->primary_color]);

        // Técnico: de ESTA empresa, con su rol, su contraseña cifrada y el teléfono normalizado como en el panel.
        $tech = User::where('email', 'juan@nuevos.test')->sole();
        $this->assertSame([$company->id, 'technician'], [$tech->company_id, $tech->role]);
        $this->assertTrue(Hash::check('Kiosco-Verde-8241', $tech->password));

        $client = Client::withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $building = Building::withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertSame([$client->id, 2], [$building->client_id, (int) $building->elevator_count]);
        $this->assertSame(['Ascensor 1', 'Ascensor 2'], Elevator::withoutGlobalScopes()->where('building_id', $building->id)->orderBy('label')->pluck('label')->all());

        // La orden: en ese edificio, asignada a ese técnico, y el técnico recibe el aviso (como desde el panel).
        $order = WorkOrder::withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertSame([$building->id, 'Ascensor 2', 'claim', 'high', 'pending'], [$order->building_id, $order->unit, $order->type, $order->priority, $order->status]);
        $this->assertSame([$tech->id], $order->users()->pluck('users.id')->all());
        Bus::assertDispatchedAfterResponse(SendWorkOrderAssignedNotification::class, fn ($job) => $job->workOrderId === $order->id && $job->userId === $tech->id);

        // Al terminar: confirmación y al inicio habitual.
        $wizard->call('finish')->assertRedirect(Dashboard::getUrl().'?configuracion=lista');
        $this->assertSame(CompanySetup::COMPLETED, $company->fresh()->setup_status);
        $this->actingInPanel($owner->fresh());
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-setup-resume-banner', false);
        $this->get(CompanySetupWizard::getUrl())->assertRedirect(Dashboard::getUrl());
    }

    public function test_each_step_uses_the_validations_of_the_panel_forms(): void
    {
        [$company, $owner] = $this->newCompany();
        User::factory()->technician()->create(['company_id' => $this->makeTenant()['company']->id, 'email' => 'tomado@otra.test']);
        $company->forceFill(['setup_acks' => ['welcome', 'company', 'brand']])->save();
        $this->actingInPanel($owner);

        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'technician')
            ->fillForm($this->technicianData(['email' => 'tomado@otra.test', 'phone' => '123', 'password' => 'corta', 'password_confirmation' => 'otra']), 'technicianForm')
            ->call('next')
            ->assertHasFormErrors(['email', 'phone', 'password'], 'technicianForm')
            ->assertSet('screen', 'technician');
        $this->assertSame(0, User::where('company_id', $company->id)->where('role', 'technician')->count());

        // Edificio sin equipos: no se crea (en el panel, tampoco).
        User::factory()->technician()->create(['company_id' => $company->id]);
        Client::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Cliente', 'type' => 'consorcio']);
        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'building')
            ->fillForm(['name' => 'Calle', 'address' => '123', 'equipment' => []], 'buildingForm')
            ->call('next')->assertHasFormErrors(['equipment'], 'buildingForm');
        $this->assertSame(0, Building::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_leaving_and_coming_back_keeps_the_progress_and_never_duplicates(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);

        Livewire::test(CompanySetupWizard::class)->call('next')
            ->set('companyData.name', 'Ascensores del Sur')->call('next')->call('next')
            ->fillForm($this->technicianData(), 'technicianForm')->call('next')->assertSet('screen', 'client')
            // «Seguir después»: al inicio, con el aviso para retomar; no se vuelve a abrir solo.
            ->call('pause')->assertRedirect(Dashboard::getUrl());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('data-setup-resume-banner', false)->assertSee('1 de 5 pasos listos');

        // Al volver, sigue en el cliente; «Volver» muestra el técnico ya creado, sin pedirlo de nuevo.
        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'client')
            ->call('back')->assertSet('screen', 'technician')->assertSee('Ya está creado')->assertDontSee('Repetir contraseña')
            ->call('next')->assertSet('screen', 'client');
        $this->assertSame(1, User::where('company_id', $company->id)->where('role', 'technician')->count());

        // No se puede saltar pasos ni terminar antes.
        Livewire::test(CompanySetupWizard::class)->call('finish')->assertStatus(422);
        $this->assertSame(CompanySetup::PENDING, $company->fresh()->setup_status);

        // Omitida: se retoma desde Ayuda, donde quedó.
        Livewire::test(CompanySetupWizard::class)->call('skip')->assertRedirect(Dashboard::getUrl());
        $this->assertSame(CompanySetup::SKIPPED, $company->fresh()->setup_status);
        Livewire::test(HelpCenter::class)->call('resumeSetup')->assertRedirect(CompanySetupWizard::getUrl());
        $this->assertSame(CompanySetup::PENDING, $company->fresh()->setup_status);
        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'client');
    }

    public function test_records_already_loaded_from_the_panel_count_and_are_not_created_again(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);
        $company->forceFill(['setup_acks' => ['welcome', 'company', 'brand']])->save();
        $tech = User::factory()->technician()->create(['company_id' => $company->id, 'name' => 'Ya Existía']);

        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'client')
            ->call('back')->assertSee('Ya Existía')->call('next')->assertSet('screen', 'client');
        $this->assertSame(1, User::where('company_id', $company->id)->where('role', 'technician')->count());
        $this->assertSame($tech->id, $this->progress($company)['technician']->id);
    }

    public function test_state_is_per_company_and_only_the_owner_uses_it(): void
    {
        [$a, $ownerA] = $this->newCompany();
        [$b] = $this->newCompany();

        User::factory()->technician()->create(['company_id' => $a->id]);
        $this->assertTrue($this->progress($a)['done']['technician']);
        $this->assertFalse($this->progress($b)['done']['technician']);

        // Lo que crea el dueño de A queda en A (la empresa nunca sale del navegador).
        $a->forceFill(['setup_acks' => ['welcome', 'company', 'brand']])->save();
        $this->actingInPanel($ownerA);
        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'client')
            ->fillForm(['name' => 'Cliente de A', 'type' => 'consorcio'], 'clientForm')->call('next');
        $this->assertSame($a->id, Client::withoutGlobalScopes()->where('name', 'Cliente de A')->sole()->company_id);
        $this->assertFalse($this->progress($b)['done']['client']);

        // Un administrativo, un técnico o el SuperAdmin no la usan.
        $this->giveCompanyPlan($a, 'empresa');
        $staff = app(StaffManager::class)->create($ownerA, ['name' => 'Ofi', 'email' => 'ofi@demo.test', 'password' => 'clave-segura-123'], ['work_orders' => 'manage']);
        $tech = User::where('company_id', $a->id)->where('role', 'technician')->first();
        $this->flushSession();
        $this->actingInPanel($staff->fresh())->get(CompanySetupWizard::getUrl())->assertForbidden();
        $this->flushSession();
        $this->actingInPanel(User::factory()->superAdmin()->create())->get(CompanySetupWizard::getUrl())->assertRedirect();
        $this->flushSession();
        $this->actingAs($tech)->get(CompanySetupWizard::getUrl())->assertRedirect(); // al inicio del técnico
        $this->assertSame(CompanySetup::PENDING, $a->fresh()->setup_status);
    }

    public function test_plan_limits_still_apply_inside_the_wizard(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->giveCompanyPlan($company, 'inicial');
        $company->forceFill(['setup_acks' => ['welcome', 'company', 'brand']])->save();
        $this->actingInPanel($owner);
        $limit = PlanGuard::for($company->fresh())->limit(PlanLimit::Technicians);
        $this->assertNotNull($limit);

        // Ya está en el tope con técnicos de antes, pero «el primer técnico» ya existe:
        // el paso cuenta como hecho y no se intenta crear otro.
        User::factory()->count($limit)->technician()->create(['company_id' => $company->id]);
        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'client');
        $this->assertSame($limit, User::where('company_id', $company->id)->where('role', 'technician')->count());

        // Clientes en el tope: el asistente no crea uno más.
        $clients = PlanGuard::for($company->fresh())->limit(PlanLimit::Clients);
        if ($clients !== null) {
            $company->forceFill(['setup_acks' => ['welcome', 'company', 'brand']])->save();
            DB::table('clients')->insert(array_map(fn ($i) => ['company_id' => $company->id, 'name' => "C{$i}", 'type' => 'consorcio', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], range(1, $clients)));
            $this->assertTrue($this->progress($company)['done']['client']);
        }
    }
}
