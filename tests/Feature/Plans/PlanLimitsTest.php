<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReachedException;
use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\PlanGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Límites reales de los planes (backend, no solo UI).
 */
class PlanLimitsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
    }

    /** La empresa queda con el plan pedido y una suscripción vigente. */
    private function onPlan(string $slug, ?array $tenant = null): void
    {
        $tenant ??= $this->a;
        $plan = SubscriptionPlan::findBySlug($slug);

        Subscription::updateOrCreate(['company_id' => $tenant['company']->id], [
            'provider' => 'mercadopago',
            'provider_subscription_id' => 'PRE-'.$tenant['company']->id,
            'plan' => $slug,
            'status' => Subscription::AUTHORIZED,
            'amount' => $plan->price,
            'currency' => 'ARS',
            'current_period_end' => now()->addMonth(),
        ]);

        $tenant['company']->forgetPlan();
    }

    /** Llena el cupo hasta dejar $free lugares (sin sesión: no pasa por el guardia). */
    private function fillBuildings(int $total): void
    {
        $missing = $total - PlanLimit::Buildings->usage($this->a['company']);

        if ($missing > 0) {
            Building::factory()->count($missing)->create([
                'company_id' => $this->a['company']->id,
                'client_id' => $this->a['building']->client_id,
            ]);
        }
    }

    private function guard(): PlanGuard
    {
        return PlanGuard::for($this->a['company']->fresh());
    }

    /*
    |--------------------------------------------------------------------------
    | PRECIOS Y DEFINICIÓN
    |--------------------------------------------------------------------------
    */

    public function test_the_three_plans_have_exactly_the_agreed_prices_and_limits(): void
    {
        $plans = SubscriptionPlan::offered();

        $this->assertSame(['inicial', 'profesional', 'empresa'], $plans->pluck('slug')->all());
        $this->assertSame([69000.0, 119000.0, 169000.0], $plans->map(fn ($p) => (float) $p->price)->all());
        $this->assertSame(['$69.000', '$119.000', '$169.000'], $plans->map->formattedPrice()->all());
        $this->assertTrue($plans[1]->is_recommended);

        $this->assertSame([20, 50, 3, 15], [$plans[0]->maxBuildings(), $plans[0]->maxClients(), $plans[0]->maxTechnicians(), $plans[0]->maxReportsPerMonth()]);
        $this->assertSame([70, 150, 10, null], [$plans[1]->maxBuildings(), $plans[1]->maxClients(), $plans[1]->maxTechnicians(), $plans[1]->maxReportsPerMonth()]);
        $this->assertSame([300, 420, 25, null], [$plans[2]->maxBuildings(), $plans[2]->maxClients(), $plans[2]->maxTechnicians(), $plans[2]->maxReportsPerMonth()]);

        // El plan de $149.000 ya no se ofrece (pero no se borró).
        $this->assertFalse($plans->contains(fn ($p) => (float) $p->price === 149000.0));
    }

    public function test_core_features_are_in_all_plans_and_extras_from_profesional(): void
    {
        foreach (SubscriptionPlan::offered() as $plan) {
            foreach ([PlanFeature::Reports, PlanFeature::Map, PlanFeature::WorkOrders, PlanFeature::Maintenances, PlanFeature::History, PlanFeature::Buildings, PlanFeature::Clients, PlanFeature::Technicians] as $feature) {
                $this->assertTrue($plan->allows($feature), "{$plan->slug} debería incluir {$feature->value}");
            }
        }

        $this->assertFalse(SubscriptionPlan::findBySlug('inicial')->allows(PlanFeature::Quotes));
        $this->assertFalse(SubscriptionPlan::findBySlug('inicial')->allows(PlanFeature::DigitalDeliveryNotes));
        $this->assertTrue(SubscriptionPlan::findBySlug('profesional')->allows(PlanFeature::Quotes));
        $this->assertTrue(SubscriptionPlan::findBySlug('empresa')->allows(PlanFeature::DigitalDeliveryNotes));
    }

    /*
    |--------------------------------------------------------------------------
    | EDIFICIOS
    |--------------------------------------------------------------------------
    */

    public function test_inicial_can_create_up_to_20_buildings_but_not_the_21st(): void
    {
        $this->onPlan('inicial');
        $this->fillBuildings(19);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateBuilding::class)
            ->fillForm(['client_id' => $this->a['building']->client_id, 'name' => 'Edificio 20', 'address' => '20'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(20, PlanLimit::Buildings->usage($this->a['company']));

        // El formulario del 21 ni se abre: explica y lleva a los planes.
        Livewire::test(CreateBuilding::class)
            ->assertRedirect(SubscriptionPage::getUrl(['limite' => 'buildings'], panel: 'ascensores_app').'#planes')
            ->assertNotified('Alcanzaste el límite de 20 edificios de tu plan Inicial.');

        // Y el backend lo frena aunque se saltee la pantalla.
        $this->expectException(PlanLimitReachedException::class);
        Building::create(['client_id' => $this->a['building']->client_id, 'name' => 'Edificio 21', 'address' => '21']);
    }

    public function test_the_limit_is_also_checked_when_saving_a_form_that_was_already_open(): void
    {
        $this->onPlan('inicial');
        $this->fillBuildings(19);
        $this->actingInPanel($this->a['admin']);

        $form = Livewire::test(CreateBuilding::class)
            ->fillForm(['client_id' => $this->a['building']->client_id, 'name' => 'Último', 'address' => '1']);

        // Otro admin ocupa el último lugar mientras tanto.
        $this->fillBuildings(20);

        $form->call('create')->assertNotified('Alcanzaste el límite de 20 edificios de tu plan Inicial.');
        $this->assertSame(20, PlanLimit::Buildings->usage($this->a['company']));
    }

    public function test_profesional_allows_70_and_empresa_300_buildings(): void
    {
        foreach (['profesional' => 70, 'empresa' => 300] as $slug => $max) {
            $this->onPlan($slug);
            $this->fillBuildings($max - 1);
            $this->actingAs($this->a['admin']);

            Building::create(['client_id' => $this->a['building']->client_id, 'name' => "Edificio {$max}", 'address' => '1']);
            $this->assertSame($max, PlanLimit::Buildings->usage($this->a['company']));
            $this->assertFalse($this->guard()->canAdd(PlanLimit::Buildings));

            try {
                Building::create(['client_id' => $this->a['building']->client_id, 'name' => 'Uno más', 'address' => '1']);
                $this->fail("{$slug} permitió más de {$max} edificios.");
            } catch (PlanLimitReachedException) {
                // esperado
            }

            auth()->logout();
        }
    }

    public function test_deactivated_buildings_free_a_slot_but_reactivating_respects_the_limit(): void
    {
        $this->onPlan('inicial');
        $this->fillBuildings(20);
        $deactivated = Building::where('company_id', $this->a['company']->id)->latest('id')->first();
        $deactivated->delete();

        $this->assertSame(19, PlanLimit::Buildings->usage($this->a['company']));

        // Ocupa el lugar con otro edificio y después intenta reactivar el viejo.
        $this->fillBuildings(20);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $deactivated)
            ->assertNotified('Alcanzaste el límite de 20 edificios de tu plan Inicial.');

        $this->assertTrue($deactivated->fresh()->trashed());
    }

    public function test_the_building_list_shows_usage_and_warns_near_the_limit(): void
    {
        $this->onPlan('inicial');
        $this->fillBuildings(18);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->assertSee('18 / 20 edificios · Plan Inicial')
            ->assertSee('Estás cerca del límite de tu plan.')
            ->assertActionVisible('plans');

        $this->fillBuildings(20);

        Livewire::test(ListBuildings::class)
            ->assertSee('20 / 20 edificios')
            ->assertSee('Alcanzaste el límite de tu plan. Actualizá tu plan para continuar.');
    }

    /*
    |--------------------------------------------------------------------------
    | CLIENTES Y TÉCNICOS
    |--------------------------------------------------------------------------
    */

    public function test_client_limit_works(): void
    {
        $this->onPlan('inicial');
        $missing = 50 - PlanLimit::Clients->usage($this->a['company']);
        Client::factory()->count($missing)->create(['company_id' => $this->a['company']->id]);

        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateClient::class)
            ->assertNotified('Alcanzaste el límite de 50 clientes de tu plan Inicial.');

        $this->expectException(PlanLimitReachedException::class);
        Client::create(['name' => 'Cliente 51', 'type' => 'consorcio']);
    }

    public function test_technician_limit_works_and_admins_do_not_consume_it(): void
    {
        $this->onPlan('inicial');

        // El tenant ya tiene 1 técnico y 1 admin. Se suman 2 técnicos y 2 admins más.
        User::factory()->technician()->count(2)->create(['company_id' => $this->a['company']->id]);
        User::factory()->admin()->count(2)->create(['company_id' => $this->a['company']->id]);
        User::factory()->superAdmin()->create();

        $this->assertSame(3, PlanLimit::Technicians->usage($this->a['company']));
        $this->assertSame('3 / 3 técnicos', $this->guard()->usageLabel(PlanLimit::Technicians));

        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateUser::class)
            ->assertNotified('Alcanzaste el límite de 3 técnicos de tu plan Inicial.');

        $this->expectException(PlanLimitReachedException::class);
        User::create(['name' => 'Cuarto técnico', 'email' => 'cuarto@ascento.test', 'password' => 'secret123']);
    }

    public function test_deactivated_technicians_do_not_count(): void
    {
        $this->onPlan('inicial');
        $extra = User::factory()->technician()->count(2)->create(['company_id' => $this->a['company']->id]);

        $this->assertFalse($this->guard()->canAdd(PlanLimit::Technicians));

        $extra->first()->delete();

        $this->assertTrue($this->guard()->canAdd(PlanLimit::Technicians));
    }

    public function test_super_admin_support_is_not_blocked_by_company_limits(): void
    {
        $this->onPlan('inicial');
        $this->fillBuildings(20);
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super);
        session(['selected_company_id' => $this->a['company']->id]);

        Building::create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id, 'name' => 'Soporte', 'address' => '1']);

        $this->assertSame(21, PlanLimit::Buildings->usage($this->a['company']));
    }

    /*
    |--------------------------------------------------------------------------
    | REPORTES POR MES
    |--------------------------------------------------------------------------
    */

    private function report(): Report
    {
        return Report::create([
            'company_id' => $this->a['company']->id,
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
            'elevator_number' => 'Ascensor 1',
            'description' => 'Ruido en la cabina',
            'priority' => 'media',
            'photo' => 'reports/x.jpg',
        ]);
    }

    public function test_inicial_can_create_15_reports_a_month_but_not_the_16th(): void
    {
        $this->onPlan('inicial');
        Report::factory()->count(14)->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

        $this->actingAs($this->a['technician']);
        $this->report(); // el 15

        $this->assertSame('15 / 15 reportes este mes', $this->guard()->usageLabel(PlanLimit::ReportsPerMonth));

        // La app del técnico explica el límite antes de completar el formulario.
        $this->get("/{$this->a['company']->slug}/reports/create")
            ->assertOk()
            ->assertSee('Alcanzaste el límite de 15 reportes de este mes de tu plan Inicial.');

        // El envío directo tampoco crea nada.
        $this->post("/{$this->a['company']->slug}/reports", ['building_id' => $this->a['building']->id])
            ->assertRedirect("/{$this->a['company']->slug}/reports/create");

        $this->expectException(PlanLimitReachedException::class);
        $this->report();
    }

    public function test_deleting_a_report_does_not_give_the_slot_back(): void
    {
        $this->onPlan('inicial');
        Report::factory()->count(15)->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

        Report::where('company_id', $this->a['company']->id)->first()->delete();

        $this->assertFalse($this->guard()->canAdd(PlanLimit::ReportsPerMonth));
    }

    public function test_the_report_counter_resets_each_month(): void
    {
        $this->onPlan('inicial');
        Report::factory()->count(15)->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

        $this->assertFalse($this->guard()->canAdd(PlanLimit::ReportsPerMonth));

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());

        $this->assertSame(0, PlanLimit::ReportsPerMonth->usage($this->a['company']));
        $this->assertTrue($this->guard()->canAdd(PlanLimit::ReportsPerMonth));
    }

    public function test_profesional_and_empresa_have_no_monthly_report_limit(): void
    {
        foreach (['profesional', 'empresa'] as $slug) {
            $this->onPlan($slug);
            Report::factory()->count(20)->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

            $this->actingAs($this->a['technician']);
            $this->report();

            $this->assertTrue($this->guard()->canAdd(PlanLimit::ReportsPerMonth));
            $this->assertNull($this->guard()->limit(PlanLimit::ReportsPerMonth));
        }
    }

    public function test_reaching_the_report_limit_notifies_the_admin_once_a_month(): void
    {
        $this->onPlan('inicial');
        Report::factory()->count(15)->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

        $this->actingAs($this->a['technician']);
        $this->get("/{$this->a['company']->slug}/reports/create");
        // En los tests la app se reutiliza entre requests: se limpian los
        // trabajos "after response" ya ejecutados (en producción cada request
        // es un proceso nuevo).
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
        $this->get("/{$this->a['company']->slug}/reports/create");

        $notifications = $this->a['admin']->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('15 reportes', $notifications[0]->data['title']);
        $this->assertStringContainsString('Con Profesional generás reportes sin límite mensual', $notifications[0]->data['body']);
    }

    /*
    |--------------------------------------------------------------------------
    | FUNCIONES EN TODOS LOS PLANES
    |--------------------------------------------------------------------------
    */

    public function test_map_work_orders_and_reports_open_on_the_cheapest_plan(): void
    {
        $this->onPlan('inicial');
        $this->actingInPanel($this->a['admin']);

        $this->get(BuildingsMap::getUrl())->assertOk();
        $this->get(WorkOrderResource::getUrl('index'))->assertOk();
        $this->get(ReportResource::getUrl('index'))->assertOk();
        $this->get(MaintenanceResource::getUrl('index'))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | UPGRADE
    |--------------------------------------------------------------------------
    */

    public function test_the_upgrade_message_says_what_you_get(): void
    {
        $this->onPlan('inicial');

        $this->assertSame('Alcanzaste el límite de 20 edificios de tu plan Inicial.', $this->guard()->limitReachedMessage(PlanLimit::Buildings));
        $this->assertSame(
            'Con Profesional podés administrar hasta 70 edificios y además obtenés hasta 150 clientes, hasta 10 técnicos, reportes sin límite mensual, presupuestos y remitos digitales para el cliente.',
            $this->guard()->upgradePitch(PlanLimit::Buildings),
        );

        $this->onPlan('profesional');
        $this->assertSame('Con Empresa podés administrar hasta 300 edificios y además obtenés hasta 420 clientes y hasta 25 técnicos.', $this->guard()->upgradePitch(PlanLimit::Buildings));
    }

    public function test_the_plans_screen_explains_the_limit_and_shows_the_three_plans(): void
    {
        $this->onPlan('inicial');
        $this->fillBuildings(20);
        $this->actingInPanel($this->a['admin']);

        $this->get(SubscriptionPage::getUrl(['limite' => 'buildings']))
            ->assertOk()
            ->assertSee('Alcanzaste el límite de 20 edificios de tu plan Inicial.')
            ->assertSee('Con Profesional podés administrar hasta 70 edificios')
            ->assertSeeInOrder(['Inicial', '$69.000', 'Recomendado', 'Profesional', '$119.000', 'Empresa', '$169.000'])
            ->assertSee('Tu plan')
            ->assertSee('20 / 20');
    }
}
