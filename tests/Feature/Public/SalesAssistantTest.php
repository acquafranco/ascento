<?php

namespace Tests\Feature\Public;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Plans\PlanFeatureManager;
use App\Support\Sales\SalesAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Tests\TestCase;

/**
 * Etapa 10: asistente de consultas de las páginas públicas. Responde con
 * los datos reales de los planes, no promete funciones que el plan no
 * tiene, no deriva urgencias ni vecinos a ventas y no guarda nada.
 */
class SalesAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function home(): string
    {
        $this->withoutVite();

        return $this->get('/')->assertOk()->getContent();
    }

    private function topic(string $key): ?array
    {
        return collect(SalesAssistant::topics())->firstWhere('key', $key);
    }

    private function text(string $key): string
    {
        return implode("\n", collect($this->topic($key)['answer'])->flatten()->all());
    }

    /** Quita funciones de planes como lo haría el SuperAdmin desde «Planes». */
    private function withoutFeatures(array $slugs, array $remove): void
    {
        $super = User::factory()->superAdmin()->create();
        foreach (SubscriptionPlan::offered()->whereIn('slug', $slugs) as $plan) {
            $enabled = collect(PlanFeature::toggleable())->filter(fn ($f) => $plan->allows($f) && ! in_array($f, $remove, true))->map->value->values()->all();
            app(PlanFeatureManager::class)->update($super, $plan, $enabled);
        }
        Once::flush(); // las respuestas se calculan una vez por request
    }

    public function test_the_plans_answer_uses_the_real_prices_limits_and_features(): void
    {
        $text = $this->text('plans');

        foreach (SubscriptionPlan::offered() as $plan) {
            $this->assertStringContainsString($plan->shortName().': '.$plan->formattedPrice().' por mes.', $text);
            $this->assertStringContainsString($plan->limit(PlanLimit::Buildings) === null ? 'Edificios sin límite' : 'Hasta '.$plan->limit(PlanLimit::Buildings).' edificios', $text);
        }
        // Lo que suma cada plan sale de la comparativa real.
        $this->assertMatchesRegularExpression('/Profesional: .*Todo lo de Inicial, más: [^\n]*portal para clientes/u', $text);
        $this->assertMatchesRegularExpression('/Empresa: .*Todo lo de Profesional, más: [^\n]*fotos de antes y después/u', $text);
        $this->assertDoesNotMatchRegularExpression('/Inicial: [^\n]*portal/u', $text);
        // La prueba gratis: plan y días de la configuración.
        $this->assertStringContainsString('gratis '.SubscriptionPlan::TRIAL_DAYS.' días con todas las funciones del plan Empresa', $text);
        $this->assertSame(30, SubscriptionPlan::TRIAL_DAYS);
    }

    public function test_answers_follow_plan_changes_and_never_offer_a_feature_no_plan_has(): void
    {
        $this->assertStringContainsString('Está desde el plan Profesional.', $this->text('portal'));

        // Si el portal pasa a ser solo de Empresa, la respuesta lo dice.
        $this->withoutFeatures(['profesional'], [PlanFeature::ClientPortal]);
        $this->assertStringContainsString('Está en el plan Empresa.', $this->text('portal'));
        $this->assertDoesNotMatchRegularExpression('/Profesional: [^\n]*portal/u', $this->text('plans'));

        // Si ningún plan lo incluye, el tema desaparece y los planes no lo mencionan.
        $this->withoutFeatures(['empresa'], [PlanFeature::ClientPortal]);
        $this->assertNull($this->topic('portal'));
        $this->assertStringNotContainsString('portal para clientes', $this->text('plans'));
        $this->assertStringNotContainsString('data-wa-topic="portal"', $this->home());
    }

    public function test_essential_features_are_in_every_plan(): void
    {
        $this->assertStringContainsString('Está en todos los planes.', $this->text('delivery_notes'));
        $this->assertStringContainsString('remitos digitales para el cliente', $this->text('plans'));
    }

    public function test_emergencies_and_residents_get_a_fixed_answer_without_sales_handoff(): void
    {
        $emergency = $this->topic('emergency');
        $residents = $this->topic('residents');
        $this->assertFalse($emergency['handoff']);
        $this->assertFalse($residents['handoff']);
        $this->assertSame([], $emergency['actions']);

        $text = $this->text('emergency');
        $this->assertStringContainsString('no es un servicio de emergencias ni de mantenimiento', $text);
        $this->assertStringContainsString('servicios de emergencia de tu zona', $text);
        $this->assertStringContainsString('empresa responsable del mantenimiento', $text);
        $this->assertStringNotContainsString('911', $text); // no se da un número de un solo país

        $this->assertStringContainsString('sistema de gestión para empresas de mantenimiento de ascensores', $this->text('residents'));
        $this->assertStringContainsString('administración del edificio o con la empresa que hace el mantenimiento', $this->text('residents'));

        // En la página, esas respuestas no tienen botón a WhatsApp.
        $html = $this->home();
        foreach (['emergency', 'residents'] as $key) {
            preg_match('#<section class="wa-answer" data-wa-view="topic" data-wa-topic="'.$key.'".*?</section>#s', $html, $m);
            $this->assertNotEmpty($m, $key);
            $this->assertStringNotContainsString('data-wa-handoff', $m[0], $key);
        }
        preg_match('#data-wa-topic="trial".*?</section>#s', $html, $m);
        $this->assertStringContainsString('data-wa-handoff', $m[0]);
    }

    public function test_the_page_renders_every_topic_with_escaped_answers_and_no_server_endpoint(): void
    {
        $html = $this->home();

        foreach (SalesAssistant::keys() as $key) {
            $this->assertStringContainsString('data-wa-topic="'.$key.'"', $html, $key);
        }
        // Solo arma un enlace: no hay formulario que envíe al servidor ni rutas nuevas.
        $this->assertMatchesRegularExpression('#<form id="wa-form" data-wa-view="form" novalidate hidden>#', $html);
        $this->assertStringNotContainsString('type="password"', substr($html, strpos($html, 'id="wa-dialog"')));
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'asistente') || str_contains($r->uri(), 'consulta')));
        $this->assertStringContainsString('Nunca te vamos a pedir contraseñas', $html);
        $this->assertStringContainsString('No escribas contraseñas ni códigos', $html);

        // Rangos de edificios: de los topes reales de los planes.
        $this->assertSame(['Hasta 20', '21 a 70', '71 a 300', 'Más de 300'], SalesAssistant::buildingRanges());
        $this->assertStringContainsString('<option>21 a 70</option>', $html);
    }

    public function test_the_hours_are_not_published_until_configured(): void
    {
        $this->assertNull(SalesAssistant::hours());
        $this->assertStringNotContainsString('data-wa-hours', $this->home());

        config(['app.whatsapp_hours' => 'lunes a viernes de 9 a 18']);
        $this->assertStringContainsString('Horario de atención: lunes a viernes de 9 a 18.', $this->home());
    }

    public function test_without_a_number_there_is_no_whatsapp_handoff(): void
    {
        config(['app.whatsapp_contact' => '']);
        $html = $this->home();

        $this->assertStringNotContainsString('data-wa-handoff>Escribir por WhatsApp', $html);
        $this->assertStringContainsString('mailto:'.config('app.support_email'), $html);
    }
}
