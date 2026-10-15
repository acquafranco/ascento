<?php

namespace Tests\Feature\OnCall;

use App\Enums\PlanFeature;
use App\Exceptions\PlanFeatureUnavailableException;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Filament\Resources\OnCall\Pages\CreateOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\ViewOnCallSchedule;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\SubscriptionPlan;
use App\Services\OnCall\OnCallService;
use App\Services\Staff\StaffManager;
use App\Support\Sales\SalesAssistant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Guardias técnicas incluidas, sin costo adicional, en Inicial, Profesional y
 * Empresa: habilitadas de verdad (panel, servicio y app del técnico), visibles
 * en la tabla comercial y en el asistente, y sin tocar precios ni límites.
 */
class OnCallPlansTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 12:00'));
        $this->a = $this->makeTenant();
    }

    private function onPlan(string $slug): void
    {
        $this->giveCompanyPlan($this->a['company'], $slug);
        $this->a['company']->refresh();
        $this->flushSession();
        $this->actingInPanel($this->a['admin']->fresh());
    }

    /** @return array<string, array{string}> */
    public static function plans(): array
    {
        return ['Inicial' => ['inicial'], 'Profesional' => ['profesional'], 'Empresa' => ['empresa']];
    }

    #[DataProvider('plans')]
    public function test_every_plan_can_use_on_call_guards(string $slug): void
    {
        $this->onPlan($slug);
        $this->assertTrue($this->a['company']->plan()->allows(PlanFeature::OnCall));

        // Panel: entra, crea la guardia y asigna un técnico.
        $this->get(OnCallScheduleResource::getUrl('index'))->assertOk()->assertSee('De guardia ahora');
        Livewire::test(CreateOnCallSchedule::class)
            ->fillForm(['name' => 'Guardia nocturna', 'days' => [1, 2, 3, 4, 5], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual'])
            ->call('create')->assertHasNoFormErrors();
        $schedule = OnCallSchedule::sole();
        Livewire::test(ViewOnCallSchedule::class, ['record' => $schedule->getRouteKey()])
            ->callAction('assign', data: ['user_id' => $this->a['technician']->id, 'starts_at' => '2026-10-14 18:00', 'ends_at' => '2026-10-21 18:00', 'reason' => null])
            ->assertHasNoActionErrors();
        $this->assertSame(1, OnCallShift::count());

        // App del técnico: la pestaña está y muestra su guardia.
        $this->flushSession();
        $this->actingAs($this->a['technician'])->get(route('on-call.index', ['company' => $this->a['company']->slug]))
            ->assertOk()->assertSee('Próximas guardias')
            ->assertSee('href="'.route('on-call.index', ['company' => $this->a['company']->slug]).'"', false);
    }

    public function test_the_plan_check_is_real_and_permissions_still_apply(): void
    {
        $this->onPlan('profesional'); // Inicial no admite usuarios administrativos

        // Permisos administrativos: sin el área «Guardias», un administrativo no entra.
        $staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Sin guardias', 'email' => 'sin-guardias@demo.test', 'password' => 'clave-segura-123'], ['clients' => 'view']);
        $this->flushSession();
        $this->actingInPanel($staff);
        $this->get(OnCallScheduleResource::getUrl('index'))->assertForbidden();

        // Si un plan no la incluyera (por ejemplo, porque el SuperAdmin la quita), no se usa en ningún lado.
        $plan = SubscriptionPlan::findBySlug('profesional');
        $plan->update(['feature_keys' => array_values(array_diff($plan->feature_keys, ['on_call']))]);
        $this->a['company']->forgetPlan();
        $this->onPlan('profesional');
        $this->assertFalse($this->a['company']->fresh()->plan()->allows(PlanFeature::OnCall));

        $this->get(OnCallScheduleResource::getUrl('index'))->assertRedirect();
        $schedule = OnCallSchedule::create(['name' => 'G', 'days' => [1], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        try {
            app(OnCallService::class)->assign($schedule, $this->a['technician'], now()->addDay(), now()->addDays(2));
            $this->fail('Se asignó una guardia sin la funcionalidad en el plan.');
        } catch (PlanFeatureUnavailableException) {
        }
        $this->flushSession();
        $this->actingAs($this->a['technician'])->get(route('on-call.index', ['company' => $this->a['company']->slug]))->assertNotFound();
        $this->actingAs($this->a['technician'])->get(route('dashboard', ['company' => $this->a['company']->slug]))
            ->assertOk()->assertDontSee('href="'.route('on-call.index', ['company' => $this->a['company']->slug]).'"', false);
    }

    public function test_the_commercial_table_and_landing_show_it_included_in_the_three_plans(): void
    {
        $plans = SubscriptionPlan::offered();
        $this->assertSame(['inicial', 'profesional', 'empresa'], $plans->pluck('slug')->all());

        $row = collect(SubscriptionPlan::comparisonRows($plans))->firstWhere(0, 'Gestión de guardias técnicas');
        $this->assertSame(['inicial' => true, 'profesional' => true, 'empresa' => true], $row[1]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<section[^>]*\bid="planes".*?</section>#s', $html, $section));
        $this->assertSame(1, preg_match('#<tr>\s*<td[^>]*>Gestión de guardias técnicas</td>(.*?)</tr>#s', $section[0], $cells));
        $this->assertSame(3, substr_count($cells[1], 'aria-label="Incluido"'));
        $this->assertStringNotContainsString('No incluido', $cells[1]);

        // Beneficios: como función de todos los planes (sin etiqueta «Desde…» ni «Plan…»).
        $this->assertSame(1, preg_match('#<section[^>]*\bid="beneficios".*?</section>#s', $html, $benefits));
        $this->assertStringContainsString('Gestión de guardias técnicas', $benefits[0]);
        $this->assertDoesNotMatchRegularExpression('#Gestión de guardias técnicas</h3>\s*<span[^>]*>(Desde|Plan) #', $benefits[0]);
    }

    public function test_the_sales_assistant_says_it_is_included_in_every_plan(): void
    {
        $topic = collect(SalesAssistant::topics())->firstWhere('key', 'on_call');

        $this->assertNotNull($topic);
        $this->assertSame('Guardias técnicas', $topic['label']);
        $this->assertContains('Está en todos los planes. Sin costo adicional.', $topic['answer']);
        $this->assertStringContainsString('no es un servicio de atención de emergencias', implode(' ', $topic['answer']));
    }

    public function test_prices_and_limits_did_not_change(): void
    {
        $plans = SubscriptionPlan::query()->get()->keyBy('slug');

        $this->assertSame(['69000.00', '119000.00', '169000.00'], [$plans['inicial']->price, $plans['profesional']->price, $plans['empresa']->price]);
        $limits = fn ($p) => [$p->max_buildings, $p->max_clients, $p->max_technicians, $p->max_reports_per_month, $p->max_admin_users];
        $this->assertSame([[20, 50, 3, 15, 0], [120, 150, 10, null, 1], [400, 420, 25, null, 5]], [$limits($plans['inicial']), $limits($plans['profesional']), $limits($plans['empresa'])]);
        foreach ($plans as $plan) {
            $this->assertTrue($plan->allows(PlanFeature::OnCall), $plan->slug); // también los planes históricos
        }
    }
}
