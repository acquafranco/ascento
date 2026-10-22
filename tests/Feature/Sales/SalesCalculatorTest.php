<?php

namespace Tests\Feature\Sales;

use App\Filament\Pages\ImplementationRates;
use App\Filament\Resources\SalesLeads\SalesLeadResource;
use App\Models\Company;
use App\Models\ImplementationRate;
use App\Models\PlatformAuditLog;
use App\Models\SalesLead;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\App\SalesLeadNotification;
use App\Services\Sales\CalculatorInput;
use App\Services\Sales\ImplementationQuote;
use App\Services\Sales\PlanAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * Calculadora comercial: recomienda con los planes y límites reales, calcula la
 * implementación con las tarifas publicadas (o «a confirmar»), registra los
 * pedidos sin duplicar y no pide datos personales para mostrar el resultado.
 */
class SalesCalculatorTest extends TestCase
{
    use InteractsWithTenants, PassesRegistrationChallenge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function input(int $b, int $e, int $c, int $t, string $state = 'tidy', array $opts = []): CalculatorInput
    {
        return new CalculatorInput($b, $e, $c, $t, $state, $opts);
    }

    private function publishRates(array $overrides = []): ImplementationRate
    {
        $rate = new ImplementationRate;
        $rate->fill([
            'currency' => 'ARS', 'base_fee' => 100000, 'included_clients' => 10, 'included_buildings' => 10, 'included_elevators' => 10,
            'per_client' => 1000, 'per_building' => 2000, 'per_elevator' => 1500,
            'factor_tidy' => 1, 'factor_mixed' => 1.25, 'factor_paper' => 1.5,
            'training_fee' => 50000, 'launch_support_fee' => null, 'max_quote' => 2000000, 'published' => true, ...$overrides,
        ])->save();

        return $rate;
    }

    public function test_the_recommendation_uses_the_real_plans_and_limits(): void
    {
        $plans = SubscriptionPlan::offered();
        $inicial = $plans->first();
        $advisor = app(PlanAdvisor::class);

        // Pequeña: el plan más barato que alcanza (el de la base, no uno inventado).
        $small = $advisor->advise($this->input(5, 8, 4, 1));
        $this->assertSame($inicial->slug, $small['recommended']);
        $this->assertFalse($small['none']);

        // Más técnicos de los que permite el primer plan: ese no es compatible.
        $techs = $advisor->advise($this->input(5, 8, 4, $inicial->max_technicians + 1));
        $this->assertNotSame($inicial->slug, $techs['recommended']);
        $this->assertFalse(collect($techs['plans'])->firstWhere('slug', $inicial->slug)['fits']);

        // Muchos ascensores no cambian el plan (no hay límite de ascensores).
        $this->assertSame($small['recommended'], $advisor->advise($this->input(5, 5000, 4, 1))['recommended']);

        // Si ninguno alcanza, no se fuerza un plan.
        $max = $plans->max('max_buildings');
        $huge = $advisor->advise($this->input($max + 1, $max + 1, 1, 1));
        $this->assertTrue($huge['none']);
        $this->assertNull($huge['recommended']);

        // Cambiar el límite de un plan en la base cambia la recomendación (una sola fuente de verdad).
        $inicial->forceFill(['max_technicians' => 1])->save();
        $this->assertNotSame($inicial->slug, $advisor->advise($this->input(5, 8, 4, 2))['recommended']);
    }

    public function test_without_published_rates_the_implementation_is_pending_never_invented(): void
    {
        $quote = app(ImplementationQuote::class)->quote($this->input(30, 50, 15, 3));
        $this->assertSame('pending', $quote['status']);
        $this->assertNull($quote['total']);

        // Con tarifas cargadas pero sin publicar: igual.
        $this->publishRates(['published' => false]);
        $this->assertSame('pending', app(ImplementationQuote::class)->quote($this->input(30, 50, 15, 3))['status']);
    }

    public function test_the_implementation_price_follows_the_formula_and_is_deterministic(): void
    {
        $this->publishRates();
        $q = fn (...$a) => app(ImplementationQuote::class)->quote($this->input(...$a));

        // 30 edificios, 50 ascensores, 15 clientes; 10 de cada uno incluidos.
        // variable = 5×1000 + 20×2000 + 40×1500 = 105.000; base 100.000.
        $tidy = $q(30, 50, 15, 3);
        $this->assertSame(['priced', 205000.0], [$tidy['status'], $tidy['implementation']]);
        $this->assertSame($tidy, $q(30, 50, 15, 3)); // mismos datos, mismo resultado

        // Datos desordenados: ×1,25 solo sobre la parte variable (+26.250).
        $this->assertSame(231250.0, $q(30, 50, 15, 3, 'mixed')['implementation']);
        // Los técnicos no suman.
        $this->assertSame(205000.0, $q(30, 50, 15, 25)['implementation']);
        // Dentro de lo incluido: solo la base.
        $this->assertSame(100000.0, $q(5, 8, 4, 1)['implementation']);

        // Opcionales por separado; uno sin precio deja el total «a confirmar».
        $training = $q(30, 50, 15, 3, 'tidy', ['training']);
        $this->assertSame([50000.0, 255000.0], [$training['options_total'], $training['total']]);
        $both = $q(30, 50, 15, 3, 'tidy', ['training', 'launch_support']);
        $this->assertNull($both['total']);
        $this->assertSame(205000.0, $both['implementation']);

        // Por encima del tope: propuesta personalizada, sin precio.
        $custom = $q(2000, 4000, 1500, 3);
        $this->assertSame(['custom', null], [$custom['status'], $custom['total']]);
    }

    public function test_the_welcome_shows_the_calculator_and_the_result_needs_no_personal_data(): void
    {
        $this->get('/')->assertOk()->assertSee('id="calculadora"', false)->assertSee('Ver qué plan me conviene');

        $plan = SubscriptionPlan::offered()->first();
        $this->get(route('sales.calculator.result', ['edificios' => 5, 'ascensores' => 8, 'clientes' => 4, 'tecnicos' => 1]))->assertOk()
            ->assertSee('Recomendado para vos')->assertSee($plan->formattedPrice())
            ->assertSee('Empiezo por mi cuenta')->assertSee('Quiero que Ascento me ayude')
            ->assertSee('Las tarifas del servicio todavía no están confirmadas')
            ->assertSee(route('register', ['plan' => $plan->slug]), false);

        // Sin JavaScript: la página completa con el mismo resultado.
        $this->get(route('sales.calculator', ['edificios' => 5, 'ascensores' => 8, 'clientes' => 4, 'tecnicos' => 1]))->assertOk()->assertSee('Recomendado para vos');

        // Validaciones comprensibles.
        $this->get(route('sales.calculator.result', ['edificios' => 10, 'ascensores' => 5, 'clientes' => 1, 'tecnicos' => 1]))->assertStatus(422)
            ->assertSee('Cada edificio tiene al menos un ascensor');
        $this->get(route('sales.calculator.result', ['edificios' => 0, 'ascensores' => 1, 'clientes' => 1, 'tecnicos' => 1]))->assertStatus(422)->assertSee('al menos 1');

        // Si ninguno alcanza: propuesta personalizada.
        $max = SubscriptionPlan::offered()->max('max_buildings');
        $this->get(route('sales.calculator.result', ['edificios' => $max + 1, 'ascensores' => $max + 1, 'clientes' => 1, 'tecnicos' => 1]))->assertOk()
            ->assertSee('Pedí una propuesta personalizada')->assertDontSee('Recomendado para vos');
    }

    public function test_a_lead_is_saved_once_with_the_server_quote_and_the_team_is_notified(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->publishRates();
        $k = (string) Str::uuid();
        $payload = ['tipo' => 'implementation', 'k' => $k, 'plan' => 'empresa', 'edificios' => 30, 'ascensores' => 50, 'clientes' => 15, 'tecnicos' => 3, 'datos' => 'mixed',
            'nombre' => 'Laura Pérez', 'email' => 'Laura@Empresa.test', 'empresa' => 'Ascensores Laura',
            // Un total manipulado desde el navegador se ignora.
            'total' => 1, 'quote' => ['total' => 1]];

        $this->postJson(route('sales.lead'), $payload, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('Recibimos tu pedido');
        $this->postJson(route('sales.lead'), $payload, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk(); // reenvío: el mismo

        $lead = SalesLead::sole();
        $this->assertSame(['implementation', 'laura@empresa.test', 231250.0], [$lead->type, $lead->email, (float) $lead->quote['implementation']]);
        $this->assertSame(1, $super->notifications()->where('type', SalesLeadNotification::class)->count());

        // Faltan datos de contacto: no se guarda.
        $this->postJson(route('sales.lead'), ['k' => (string) Str::uuid(), 'tipo' => 'custom', 'edificios' => 1, 'ascensores' => 1, 'clientes' => 1, 'tecnicos' => 1])->assertStatus(422);
        // Robot (campo trampa): nada.
        $this->post(route('sales.lead'), $payload + ['sitio_web' => 'x', 'k' => (string) Str::uuid()])->assertStatus(422);
        $this->assertSame(1, SalesLead::count());
    }

    public function test_choosing_a_plan_is_remembered_after_registering(): void
    {
        $plan = SubscriptionPlan::offered()->first();
        $this->get(route('register', ['plan' => $plan->slug]))->assertOk()->assertSee('Elegiste el plan')->assertSee($plan->shortName());
        $this->get(route('register', ['plan' => 'inventado']))->assertOk()->assertDontSee('Elegiste el plan');

        $this->post('/register', [...$this->registrationProof(), 'company_name' => 'Ascensores Calc', 'name' => 'Dueño', 'email' => 'calc@empresa.test',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', 'cuit' => '30-71234567-1', 'phone' => '11 4567-8900',
            'province' => 'Buenos Aires', 'locality' => 'Lomas', 'terms' => '1', 'intended_plan' => $plan->slug])->assertRedirect();

        $company = Company::where('name', 'Ascensores Calc')->sole();
        $this->assertSame($plan->slug, $company->intended_plan);
        $this->assertTrue($company->onTrial()); // la prueba no cambia
    }

    public function test_only_the_super_admin_manages_rates_and_leads(): void
    {
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);
        $this->get(ImplementationRates::getUrl())->assertForbidden();
        $this->assertNotSame(200, $this->get(SalesLeadResource::getUrl())->status());
    }

    public function test_the_super_admin_publishes_rates_only_when_complete(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);
        // Publicar sin las tarifas obligatorias: no.
        Livewire::test(ImplementationRates::class)->set('data.published', true)->call('save')->assertNotified('Falta completar tarifas');
        $this->assertFalse(ImplementationRate::current()->isReady());

        Livewire::test(ImplementationRates::class)
            ->set('data.base_fee', 100000)->set('data.per_client', 1000)->set('data.per_building', 2000)->set('data.per_elevator', 1500)->set('data.published', true)
            ->call('save')->assertNotified('Tarifas guardadas');
        $this->assertTrue(ImplementationRate::current()->isReady());
        // Queda registrado quién cambió las tarifas (acción de plataforma, sin empresa).
        $this->assertTrue(PlatformAuditLog::where('action', 'implementation_rates.updated')->where('user_id', $super->id)->exists());
        $this->get(SalesLeadResource::getUrl())->assertOk();

        $this->expectException(ValidationException::class);
        CalculatorInput::fromRequest(['edificios' => 'mil', 'ascensores' => 1, 'clientes' => 1, 'tecnicos' => 1]);
    }
}
