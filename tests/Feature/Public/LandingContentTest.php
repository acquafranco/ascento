<?php

namespace Tests\Feature\Public;

use App\Enums\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Plans\PlanFeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etapa 9.1: la página de inicio muestra las funciones reales, con la
 * etiqueta de plan que sale de la configuración (no de un texto fijo), sin
 * afirmaciones que no se puedan respaldar.
 */
class LandingContentTest extends TestCase
{
    use RefreshDatabase;

    private function home(): string
    {
        $this->withoutVite();

        return $this->get('/')->assertOk()->getContent();
    }

    public function test_it_shows_the_real_features_with_the_plan_they_belong_to(): void
    {
        $html = $this->home();

        foreach (['Clientes, edificios y ascensores', 'Órdenes y remitos digitales', 'QR público del edificio', 'Pruebas de paracaídas',
            'Componentes en reparación', 'Tareas y recordatorios', 'Portal para tus clientes', 'Historial de contactos', 'Análisis e indicadores'] as $title) {
            $this->assertStringContainsString($title, $html, $title);
        }

        // Etiquetas: de la configuración real de los planes.
        $this->assertMatchesRegularExpression('#Presupuestos profesionales</h3>\s*<span[^>]*>Desde Profesional</span>#u', $html);
        $this->assertMatchesRegularExpression('#Fotos de antes y después</h3>\s*<span[^>]*>Plan Empresa</span>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#QR público del edificio</h3>\s*<span#u', $html); // en todos los planes
        $this->assertDoesNotMatchRegularExpression('#Órdenes y remitos digitales</h3>\s*<span#u', $html);

        // La comparativa incluye las funciones nuevas.
        foreach (['QR público de los edificios', 'Pruebas de paracaídas', 'Componentes enviados a reparación', 'Tareas y recordatorios', 'Historial de contactos con clientes'] as $row) {
            $this->assertStringContainsString('<td class="px-4 py-2.5 text-ink/80">'.$row.'</td>', $html, $row);
        }
    }

    public function test_no_claims_that_cannot_be_backed(): void
    {
        $html = $this->home();

        $this->assertStringNotContainsString('Usado por empresas', $html);
        $this->assertStringNotContainsString('toda la región', $html);
        // La prueba gratis es con el plan Empresa completo (SubscriptionPlan::TRIAL_PLAN).
        $this->assertStringContainsString('30 días con todas las funciones del plan Empresa', $html);
        $this->assertStringNotContainsString('funciones del plan Profesional, sin tarjeta', $html);
        $this->assertStringContainsString('Desde el plan Profesional, cada consorcio', $html);
        $this->assertStringContainsString('no una certificación de seguridad', $html);
        // Sin promesas de tiempo de configuración.
        $this->assertStringContainsString('Empezá a organizar tu empresa desde el primer día', $html);
        $this->assertStringNotContainsString('en minutos', $html);
    }

    public function test_a_feature_no_plan_includes_is_not_advertised(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super);
        foreach (SubscriptionPlan::offered() as $plan) {
            $enabled = collect(PlanFeature::toggleable())->filter(fn ($f) => $plan->allows($f) && $f !== PlanFeature::Repairs)->map->value->values()->all();
            app(PlanFeatureManager::class)->update($super, $plan, $enabled);
        }
        auth()->logout();

        $html = $this->home();
        $this->assertStringNotContainsString('Componentes en reparación</h3>', $html);
        $this->assertStringContainsString('QR público del edificio</h3>', $html);
    }

    /** Quita funciones de un plan como lo haría el SuperAdmin desde «Planes». */
    private function withoutFeatures(string $slug, array $remove): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super);
        $plan = SubscriptionPlan::offered()->firstWhere('slug', $slug);
        $enabled = collect(PlanFeature::toggleable())->filter(fn ($f) => $plan->allows($f) && ! in_array($f, $remove, true))->map->value->values()->all();
        app(PlanFeatureManager::class)->update($super, $plan, $enabled);
        auth()->logout();
    }

    public function test_the_analysis_card_does_not_promise_empresa_comparisons_to_profesional(): void
    {
        $html = $this->home();
        $card = '#Análisis e indicadores</h3>\s*<span[^>]*>Desde Profesional</span>\s*<p[^>]*>([^<]*)</p>#u';
        $this->assertMatchesRegularExpression($card, $html);
        preg_match($card, $html, $m);
        $this->assertStringNotContainsString('comparativa', mb_strtolower($m[1]));
        $this->assertStringContainsString('fallan seguido', $m[1]);

        // La etiqueta cuenta las dos funciones que anuncia: si Profesional pierde una, pasa a «Plan Empresa».
        $this->withoutFeatures('profesional', [PlanFeature::FailureAnalysis]);
        $this->assertMatchesRegularExpression('#Análisis e indicadores</h3>\s*<span[^>]*>Plan Empresa</span>#u', $this->home());
    }

    public function test_a_comparison_row_with_two_features_needs_both(): void
    {
        $row = fn (string $label) => collect(SubscriptionPlan::comparisonRows(SubscriptionPlan::offered()))->firstWhere(0, $label)[1];

        $this->assertSame(['inicial' => false, 'profesional' => false, 'empresa' => true], $row('Indicadores avanzados y alertas de tendencias'));
        $this->assertSame(['inicial' => false, 'profesional' => true, 'empresa' => true], $row('Análisis de fallas e historial avanzado'));
        $this->assertSame(['inicial' => false, 'profesional' => true, 'empresa' => true], $row('Centro de atención avanzado e indicadores de empresa'));

        // Con una sola de las dos, la fila ya no figura como incluida.
        $this->withoutFeatures('empresa', [PlanFeature::AdvancedAlerts]);
        $this->withoutFeatures('profesional', [PlanFeature::ElevatorHistoryAdvanced, PlanFeature::AttentionAdvanced]);
        $this->assertFalse($row('Indicadores avanzados y alertas de tendencias')['empresa']);
        $this->assertFalse($row('Análisis de fallas e historial avanzado')['profesional']);
        $this->assertFalse($row('Centro de atención avanzado e indicadores de empresa')['profesional']);
        $this->assertTrue($row('Análisis de fallas e historial avanzado')['empresa']);

        // Y la página lo muestra así.
        $html = $this->home();
        $this->assertStringContainsString('Indicadores avanzados y alertas de tendencias', $html);
    }

    public function test_the_hero_keeps_the_animated_floor_indicator(): void
    {
        // Decisión del dueño (13/10/2026): la portada vuelve a la ilustración
        // animada original (indicador de piso), en lugar de la captura.
        $html = $this->home();

        $this->assertStringContainsString('id="floorDigit"', $html);
        $this->assertStringContainsString('Indicador de posición', $html);
        $this->assertStringContainsString("const floorDigit = document.getElementById('floorDigit');", $html);
        $this->assertStringNotContainsString('data-hero-shot', $html);
    }
}
