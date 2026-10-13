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
}
