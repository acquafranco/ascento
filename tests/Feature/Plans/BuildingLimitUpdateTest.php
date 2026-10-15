<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReachedException;
use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Models\Building;
use App\Models\SubscriptionPlan;
use App\Support\Plans\PlanGuard;
use App\Support\Sales\SalesAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Nuevos topes de edificios: Inicial 20, Profesional 120, Empresa 400. Se
 * validan en el servidor (no solo en la pantalla), por empresa, y las
 * suscripciones existentes no cambian.
 */
class BuildingLimitUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function fill(int $companyId, int $clientId, int $count): void
    {
        $now = now();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['company_id' => $companyId, 'client_id' => $clientId, 'name' => "Edificio {$i}", 'address' => 'Calle '.$i, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('buildings')->insert($chunk);
        }
    }

    public function test_the_plans_have_the_new_building_limits_and_nothing_else_changed(): void
    {
        $plans = SubscriptionPlan::offered()->keyBy('slug');
        $this->assertSame([20, 120, 400], [$plans['inicial']->max_buildings, $plans['profesional']->max_buildings, $plans['empresa']->max_buildings]);
        // Precios y demás límites, como estaban.
        $this->assertSame([69000.0, 119000.0, 169000.0], [(float) $plans['inicial']->price, (float) $plans['profesional']->price, (float) $plans['empresa']->price]);
        $this->assertSame([50, 150, 420], [$plans['inicial']->max_clients, $plans['profesional']->max_clients, $plans['empresa']->max_clients]);
        $this->assertSame([3, 10, 25], [$plans['inicial']->max_technicians, $plans['profesional']->max_technicians, $plans['empresa']->max_technicians]);
        $this->assertSame(30, SubscriptionPlan::TRIAL_DAYS);
        $this->assertSame(['Hasta 20', '21 a 120', '121 a 400', 'Más de 400'], SalesAssistant::buildingRanges());
    }

    public function test_each_plan_allows_exactly_its_limit_and_the_next_one_is_rejected_on_the_server(): void
    {
        foreach (['inicial' => 20, 'profesional' => 120, 'empresa' => 400] as $slug => $max) {
            $a = $this->makeTenant(); // ya trae 1 edificio
            $this->giveCompanyPlan($a['company'], $slug);
            $this->fill($a['company']->id, $a['building']->client_id, $max - 2);
            $this->actingAs($a['admin']);

            Building::create(['client_id' => $a['building']->client_id, 'name' => 'Último permitido', 'address' => '1']);
            $this->assertSame($max, PlanLimit::Buildings->usage($a['company']->fresh()));

            // Aunque se arme el pedido a mano (sin pasar por el formulario), el modelo lo rechaza.
            try {
                Building::create(['client_id' => $a['building']->client_id, 'name' => 'Uno más', 'address' => '1']);
                $this->fail("{$slug} permitió más de {$max} edificios.");
            } catch (PlanLimitReachedException $e) {
                $this->assertStringContainsString((string) $max, PlanGuard::for($a['company']->fresh())->limitReachedMessage(PlanLimit::Buildings));
            }
            auth()->logout();
        }
    }

    public function test_the_create_form_shows_a_clear_message_at_the_limit(): void
    {
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'profesional');
        $this->fill($a['company']->id, $a['building']->client_id, 118);
        $this->actingInPanel($a['admin']);

        $form = Livewire::test(CreateBuilding::class)
            ->fillForm(['client_id' => $a['building']->client_id, 'name' => 'Otro', 'address' => '1', 'equipment' => [['kind' => 'elevator', 'machine_type' => 'traction']]]);
        // Mientras tanto se ocupa el último lugar: el servidor vuelve a contar al guardar.
        $this->fill($a['company']->id, $a['building']->client_id, 1);
        $form->call('create')
            ->assertNotified('Alcanzaste el límite de 120 edificios de tu plan Profesional.');
        $this->assertSame(120, PlanLimit::Buildings->usage($a['company']));
    }

    public function test_buildings_of_another_company_do_not_count(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'inicial');
        $this->giveCompanyPlan($b['company'], 'inicial');
        $this->fill($b['company']->id, $b['building']->client_id, 60);

        $this->assertSame(1, PlanLimit::Buildings->usage($a['company']));
        $this->actingAs($a['admin']);
        Building::create(['client_id' => $a['building']->client_id, 'name' => 'Propio', 'address' => '1']);
        $this->assertSame(2, PlanLimit::Buildings->usage($a['company']));
    }

    public function test_existing_subscriptions_keep_their_data_and_a_company_over_an_old_limit_keeps_access(): void
    {
        $a = $this->makeTenant();
        $this->giveCompanyPlan($a['company'], 'profesional');
        $snapshot = fn () => (array) DB::table('subscriptions')->where('company_id', $a['company']->id)->first();
        $before = $snapshot();

        // Rehacer la migración no toca las suscripciones.
        $migration = require database_path('migrations/2027_01_08_100000_raise_building_limits.php');
        $migration->down();
        $this->assertSame(70, (int) SubscriptionPlan::where('slug', 'profesional')->value('max_buildings'));
        $migration->up();
        $this->assertSame(120, (int) SubscriptionPlan::where('slug', 'profesional')->value('max_buildings'));
        $this->assertSame($before, $snapshot());

        // Un tope cambiado a mano no se pisa.
        SubscriptionPlan::where('slug', 'empresa')->update(['max_buildings' => 450]);
        $migration->up();
        $this->assertSame(450, (int) SubscriptionPlan::where('slug', 'empresa')->value('max_buildings'));

        // Una empresa que ya supera su tope (por ejemplo, tras bajar de plan) no pierde nada ni el acceso: solo no suma más.
        $this->giveCompanyPlan($a['company'], 'inicial');
        $this->fill($a['company']->id, $a['building']->client_id, 30);
        $this->actingInPanel($a['admin']);
        $this->get('/admin/buildings')->assertOk();
        $this->assertSame(31, Building::where('company_id', $a['company']->id)->count());
        $this->assertFalse(PlanGuard::for($a['company']->fresh())->canAdd(PlanLimit::Buildings));
    }
}
