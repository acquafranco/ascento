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
            $this->assertStringContainsString($plan->shortName().' · '.$plan->formattedPrice().': ', $text);
            $this->assertStringContainsString($plan->limit(PlanLimit::Buildings) === null ? 'edificios sin límite' : 'hasta '.$plan->limit(PlanLimit::Buildings).' edificios', $text);
            // El detalle desplegable tiene todos los límites del plan.
            $this->assertStringContainsString($plan->shortName().': '.implode(' · ', array_map(fn (PlanLimit $l) => $l->planLabel($plan->limit($l)), PlanLimit::cases())).'.', $text);
        }
        // Lo que suma cada plan sale de la comparativa real.
        $this->assertMatchesRegularExpression('/Profesional · [^\n]*Suma: [^\n]*portal para clientes/u', $text);
        $this->assertMatchesRegularExpression('/Empresa · [^\n]*Suma: [^\n]*fotos de antes y después/u', $text);
        $this->assertDoesNotMatchRegularExpression('/Inicial · [^\n]*portal/u', $text);
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
        $this->assertDoesNotMatchRegularExpression('/Profesional · [^\n]*portal/u', $this->text('plans'));

        // Si ningún plan lo incluye, el tema desaparece y los planes no lo mencionan.
        $this->withoutFeatures(['empresa'], [PlanFeature::ClientPortal]);
        $this->assertNull($this->topic('portal'));
        $this->assertStringNotContainsString('portal para clientes', $this->text('plans'));
        $this->assertStringNotContainsString('data-wa-topic="portal"', $this->home());
    }

    public function test_essential_features_are_in_every_plan(): void
    {
        $this->assertStringContainsString('Está en todos los planes.', $this->text('delivery_notes'));
        $this->assertStringContainsString('Remitos digitales para el cliente', $this->text('plans'));
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
        $this->assertStringContainsString('servicio de emergencias de tu zona', $text);
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
        $this->assertSame(['Hasta 20', '21 a 120', '121 a 400', 'Más de 400'], SalesAssistant::buildingRanges());
        $this->assertStringContainsString('<option>21 a 120</option>', $html);
    }

    public function test_commercial_topics_come_first_and_the_emergency_option_last(): void
    {
        $keys = SalesAssistant::keys();
        $this->assertSame(['plans', 'trial'], array_slice($keys, 0, 2));
        $this->assertSame('emergency', end($keys));

        // En la página, la urgencia es el último botón de la lista y no hay ninguno antes de los temas.
        $html = $this->home();
        preg_match_all('#data-wa-pick="([a-z_]+)"#', $html, $m);
        $this->assertSame($keys[0], $m[1][0]);
        $this->assertSame('emergency', end($m[1]));
        $this->assertSame(1, substr_count($html, 'data-wa-pick="emergency"'));
    }

    public function test_answers_are_short_with_details_and_a_button_per_plan(): void
    {
        $html = $this->home();
        preg_match('#data-wa-topic="plans".*?</section>#s', $html, $m);
        $section = $m[0];

        // Lo largo queda plegado.
        $this->assertStringContainsString('<summary>Qué incluyen todos los planes</summary>', $section);
        $this->assertStringContainsString('<summary>Todos los límites</summary>', $section);
        // La línea del plan es corta («y N más») y la lista completa está en «Qué suma cada plan».
        $this->assertStringContainsString('<summary>Qué suma cada plan</summary>', $section);
        $this->assertMatchesRegularExpression('/Profesional · [^<]*Suma: [^<]* y \\d+ más\\./u', $section);
        $this->assertMatchesRegularExpression('/Profesional \\(suma\\): [^<]*centro de atención avanzado e indicadores de empresa\\./u', $section);
        // Los botones por plan van antes de las acciones.
        $this->assertLessThan(strpos($section, 'class="wa-actions"'), strpos($section, 'data-wa-subject='));
        // Un botón por plan ofrecido, que pasa a WhatsApp con ese plan como tema.
        foreach (SubscriptionPlan::offered() as $plan) {
            $this->assertStringContainsString('data-wa-handoff data-wa-subject="Plan '.$plan->shortName().'"', $section);
        }
        $this->assertStringContainsString('>Pedir una demostración</button>', $html);
    }

    public function test_the_visitor_can_continue_to_whatsapp_without_personal_data(): void
    {
        $html = $this->home();
        preg_match('#<form id="wa-form".*?</form>#s', $html, $m);

        $this->assertStringNotContainsString('required', $m[0]);
        $this->assertStringNotContainsString('name="email"', $m[0]); // por WhatsApp ya hay contacto
        $this->assertStringContainsString('Todos los datos son opcionales', $m[0]);
        // El mensaje solo suma lo que la persona completó.
        $this->assertStringContainsString("if (data.name) lines.push('Nombre: ' + data.name);", $html);
        $this->assertStringContainsString("if (data.company) lines.push('Empresa: ' + data.company);", $html);
    }

    public function test_the_assistant_does_not_get_in_the_way_of_the_login(): void
    {
        $this->withoutVite();
        $html = $this->get('/login')->assertOk()->getContent();

        // Cerrado hasta que se toca «Escribinos»: el formulario de ingreso sigue siendo lo principal.
        $this->assertMatchesRegularExpression('#<dialog id="wa-dialog" class="wa-dialog"[^>]*>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<dialog id="wa-dialog"[^>]*\sopen#', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringNotContainsString('class="wa-launch"', $html); // sin botón flotante en el ingreso
        $this->assertLessThan(strpos($html, 'id="wa-dialog"'), strpos($html, 'name="password"'));
    }

    public function test_the_confirmed_number_is_used_for_every_commercial_whatsapp_link(): void
    {
        $this->assertSame('5491173728421', SalesAssistant::whatsappNumber());
        $this->assertSame('+54 9 11 7372-8421', SalesAssistant::displayNumber());
        $this->assertSame('https://wa.me/5491173728421?text=Hola%2C%20equipo%20de%20Ascento.%0ATema%3A%20Planes%20y%20precios',
            SalesAssistant::whatsappUrl("Hola, equipo de Ascento.\nTema: Planes y precios"));

        $html = $this->home();
        // El asistente arma el enlace con ese número y el pie lo muestra (sin números escritos a mano).
        $this->assertStringContainsString('const number = "5491173728421";', $html);
        $this->assertStringContainsString("'https://wa.me/' + number + '?text=' + encodeURIComponent(lines.join('\\n'))", $html);
        $this->assertStringContainsString('WhatsApp: +54 9 11 7372-8421', $html);
        preg_match_all('#wa\.me/(\d+)#', $html, $m);
        $this->assertSame([], array_values(array_diff(array_unique($m[1]), ['5491173728421'])));
    }

    /** Lee config/app.php con esas variables de entorno (null = la variable no existe). */
    private function appConfigWith(array $vars): array
    {
        $backup = [];
        foreach ($vars as $name => $value) {
            $backup[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
            if ($value !== null) {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
        try {
            return require config_path('app.php');
        } finally {
            foreach ($backup as $name => [$env, $server, $put]) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($put !== false) {
                    putenv("{$name}={$put}");
                }
            }
        }
    }

    public function test_the_environment_variables_resolve_as_documented(): void
    {
        $hours = 'todos los días, las 24 horas, incluidos fines de semana y feriados';

        // Sin las variables: el número y la atención confirmados.
        $c = $this->appConfigWith(['ASCENTO_WHATSAPP_NUMBER' => null, 'ASCENTO_WHATSAPP_HOURS' => null]);
        $this->assertSame('5491173728421', $c['whatsapp_contact']);
        $this->assertSame($hours, $c['whatsapp_hours']);

        // Con valor: se usa ese (el número, solo dígitos).
        $c = $this->appConfigWith(['ASCENTO_WHATSAPP_NUMBER' => '+54 9 11 5555-1234', 'ASCENTO_WHATSAPP_HOURS' => 'lunes a viernes']);
        $this->assertSame('5491155551234', $c['whatsapp_contact']);
        $this->assertSame('lunes a viernes', $c['whatsapp_hours']);

        // Definidas vacías: sin WhatsApp (contacto por correo) y sin horario.
        $c = $this->appConfigWith(['ASCENTO_WHATSAPP_NUMBER' => '', 'ASCENTO_WHATSAPP_HOURS' => '']);
        $this->assertSame('', $c['whatsapp_contact']);
        $this->assertSame('', $c['whatsapp_hours']);
        config(['app.whatsapp_contact' => $c['whatsapp_contact'], 'app.whatsapp_hours' => $c['whatsapp_hours']]);
        $this->assertNull(SalesAssistant::whatsappNumber());
        $this->assertNull(SalesAssistant::hours());
    }

    public function test_round_the_clock_attention_is_shown_without_out_of_hours_messages(): void
    {
        $this->assertSame('todos los días, las 24 horas, incluidos fines de semana y feriados', SalesAssistant::hours());
        $html = $this->home();
        $this->assertStringContainsString('Horario de atención: todos los días, las 24 horas, incluidos fines de semana y feriados.', $html);
        foreach (['fuera de horario', 'día hábil', 'te respondemos en', 'apenas podamos'] as $promise) {
            $this->assertStringNotContainsStringIgnoringCase($promise, $html, $promise);
        }

        // Se puede ocultar sin tocar código (ASCENTO_WHATSAPP_HOURS vacío).
        config(['app.whatsapp_hours' => '']);
        $this->assertStringNotContainsString('data-wa-hours', $this->home());
    }

    public function test_without_a_valid_number_the_contact_is_by_email(): void
    {
        foreach (['', '0111234567', '12345', '5491173728421999999'] as $invalid) {
            config(['app.whatsapp_contact' => $invalid]);
            $this->assertNull(SalesAssistant::whatsappNumber(), $invalid);
            $this->assertNull(SalesAssistant::whatsappUrl('Hola'), $invalid);
        }

        config(['app.whatsapp_contact' => '']);
        Once::flush();
        $html = $this->home();
        $this->assertStringNotContainsString('data-wa-handoff>Escribir por WhatsApp', $html);
        $this->assertStringNotContainsString('data-wa-number', $html);
        $this->assertStringContainsString('mailto:'.config('app.support_email'), $html);
        $this->assertStringContainsString('Escribinos a '.config('app.support_email').' con tu consulta.', $this->text('human'));
        $this->assertStringNotContainsString('WhatsApp', $this->text('trial'));
    }
}
