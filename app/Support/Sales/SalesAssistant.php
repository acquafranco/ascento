<?php

namespace App\Support\Sales;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

/**
 * Asistente de consultas comerciales de las páginas públicas (Etapa 10):
 * respuestas a las dudas frecuentes antes de pasar a WhatsApp.
 *
 * Todo sale de la configuración real, igual que la página de inicio:
 * precios y límites de SubscriptionPlan, funciones por plan de
 * comparisonRows() y cheapestWith(), y la prueba gratis de TRIAL_PLAN y
 * TRIAL_DAYS. Un tema cuya función no está en ningún plan no se ofrece.
 *
 * Las respuestas viajan con la página: no hay endpoint ni se guarda nada.
 * El mensaje de WhatsApp lo arma y lo envía la persona.
 *
 * Cada respuesta es una lista de bloques: string = párrafo, array = lista.
 */
final class SalesAssistant
{
    /** Temas comerciales (pasan a una persona por WhatsApp si hace falta). */
    public const SALES = 'sales';

    /** Temas con respuesta fija que no pasan a ventas (urgencias, vecinos). */
    public const FIXED = 'fixed';

    /**
     * @return list<array{key: string, label: string, kind: string, answer: list<string|list<string>>, actions: list<string>, handoff: bool}>
     */
    public static function topics(): array
    {
        return once(function () {
            $plans = SubscriptionPlan::offered();
            $trial = SubscriptionPlan::findBySlug(SubscriptionPlan::TRIAL_PLAN)?->shortName();
            $trialLine = $trial
                ? 'Podés probarlo gratis '.SubscriptionPlan::TRIAL_DAYS.' días con todas las funciones del plan '.$trial.', sin tarjeta.'
                : 'Podés probarlo gratis '.SubscriptionPlan::TRIAL_DAYS.' días, sin tarjeta.';
            $t = fn (string $key, string $label, array $answer, array $actions = [], bool $handoff = true, string $kind = self::SALES) => compact('key', 'label', 'kind', 'answer', 'actions', 'handoff');

            $topics = [
                $t('what', 'Qué es Ascento', [
                    'Ascento es un sistema para empresas de mantenimiento de ascensores. Reúne clientes, edificios, ascensores, técnicos, mantenimientos, órdenes de trabajo y remitos en un solo lugar.',
                    'Funciona en la computadora y en el celular, sin instalar nada.',
                ], ['trial', 'plans']),
                $t('how', 'Cómo funciona', [[
                    'Creás tu cuenta y cargás los datos de tu empresa.',
                    'Sumás edificios, ascensores, clientes y técnicos.',
                    'Cada técnico ve en el celular solo lo suyo y cierra cada trabajo con el remito firmado.',
                    'Desde el panel ves qué falta, qué se hizo y qué necesita atención.',
                ]], ['trial']),
                $t('plans', 'Planes y precios', self::plansAnswer($plans, $trialLine), ['plans', 'trial']),
                $t('trial', 'Probar gratis o pedir una demostración', [
                    $trialLine.' Creás la cuenta desde la página y empezás a cargar tu empresa.',
                    'Si preferís que te lo mostremos, escribinos por WhatsApp y coordinamos una demostración.',
                ], ['trial']),
            ];

            if ($available = self::availability($plans, PlanFeature::ClientPortal)) {
                $topics[] = $t('portal', 'Portal para tus clientes', [
                    'Cada consorcio o administración entra con su usuario y ve solo los edificios que le habilitás y lo que marcás como compartido: remitos, reportes, presupuestos y documentos.',
                    'Lo invitás por correo desde la ficha del cliente.',
                    $available,
                ], ['plans']);
            }
            if ($available = self::availability($plans, PlanFeature::Agenda)) {
                $topics[] = $t('maintenance', 'Mantenimientos e inspecciones', [
                    'Cada edificio tiene su frecuencia de visitas y su técnico. La agenda del mes muestra qué está hecho, qué no se pudo hacer, qué está pendiente y qué venció.',
                    'Una visita cuenta como hecha solo con el remito firmado.',
                    $available,
                ], ['trial']);
            }
            if ($available = self::availability($plans, PlanFeature::WorkOrders, PlanFeature::DigitalDeliveryNotes)) {
                $topics[] = $t('delivery_notes', 'Remitos digitales', [
                    'El técnico cierra el trabajo en el celular con su firma y, si querés, la del cliente. El remito queda numerado y lo compartís en PDF o con un enlace por WhatsApp o correo.',
                    $available,
                ], ['trial']);
            }
            if ($available = self::availability($plans, PlanFeature::PublicQr)) {
                $topics[] = $t('qr', 'QR público del edificio', [
                    'Cada edificio puede tener un QR. Quien lo escanea ve, sin cuenta, solo lo que la empresa eligió publicar: estado informado de cada ascensor, último mantenimiento e inspección registrados, pruebas de paracaídas y componentes en reparación.',
                    'No muestra datos del cliente ni montos. Es información administrativa, no una certificación de seguridad.',
                    $available,
                ], ['trial']);
            }

            $topics[] = $t('support', 'Ya uso Ascento y necesito ayuda', [
                'Dentro de Ascento, en «Ayuda», tenés guías paso a paso según lo que podés usar.',
                'Si no se resuelve, escribinos a '.config('app.support_email').' o por WhatsApp.',
                'Nunca te vamos a pedir tu contraseña ni códigos de verificación.',
            ]);
            $topics[] = $t('human', 'Hablar con una persona', ['Dejanos tus datos y se abre WhatsApp con tu consulta lista.']);

            // Respuestas fijas: no son consultas comerciales y no se derivan a ventas.
            $topics[] = $t('residents', 'Soy vecino o de la administración de un edificio', [
                'Ascento es un sistema de gestión para empresas de mantenimiento de ascensores. No atiende edificios ni tiene información de tu ascensor para compartir por acá.',
                'Para cualquier consulta sobre el ascensor, comunicate con la administración del edificio o con la empresa que hace el mantenimiento.',
            ], [], false, self::FIXED);
            $topics[] = $t('emergency', 'Hay una urgencia con un ascensor', [
                'Ascento no es un servicio de emergencias ni de mantenimiento, y no puede ayudarte con una falla desde acá.',
                'Si hay peligro inmediato, por ejemplo una persona atrapada o herida, comunicate con los servicios de emergencia de tu zona. Avisá también a la empresa responsable del mantenimiento del ascensor o a la administración del edificio.',
            ], [], false, self::FIXED);

            return $topics;
        });
    }

    /** @return list<string> claves de los temas */
    public static function keys(): array
    {
        return array_column(self::topics(), 'key');
    }

    /**
     * Rangos de edificios para el mensaje (opcional), según los topes de los
     * planes: "Hasta 20", "21 a 70", "71 a 300", "Más de 300".
     *
     * @return list<string>
     */
    public static function buildingRanges(): array
    {
        $limits = SubscriptionPlan::offered()->map(fn (SubscriptionPlan $p) => $p->limit(PlanLimit::Buildings))
            ->filter()->unique()->sort()->values();
        $ranges = [];
        $from = 1;
        foreach ($limits as $max) {
            $ranges[] = $from === 1 ? "Hasta {$max}" : "{$from} a {$max}";
            $from = $max + 1;
        }
        if ($limits->isNotEmpty()) {
            $ranges[] = 'Más de '.$limits->last();
        }

        return $ranges;
    }

    /** Horario de atención publicado (null = todavía no se publica). */
    public static function hours(): ?string
    {
        return filled($hours = config('app.whatsapp_hours')) ? (string) $hours : null;
    }

    /**
     * «Está en todos los planes.» / «Está desde el plan X.» / «Está en el
     * plan X.»; false si ningún plan ofrecido incluye todas las funciones.
     */
    private static function availability(Collection $plans, PlanFeature ...$features): string|false
    {
        if ($plans->isEmpty()) {
            return false;
        }
        if ($plans->every(fn (SubscriptionPlan $p) => $p->allowsAll($features))) {
            return 'Está en todos los planes.';
        }
        $from = SubscriptionPlan::cheapestWith(...$features);
        if ($from === null) {
            return false;
        }

        return $from->is($plans->last()) ? 'Está en el plan '.$from->shortName().'.' : 'Está desde el plan '.$from->shortName().'.';
    }

    /**
     * Planes con precio, límites y lo que suma cada uno, a partir de la misma
     * comparativa de la página de inicio (comparisonRows).
     *
     * @return list<string|list<string>>
     */
    private static function plansAnswer(Collection $plans, string $trialLine): array
    {
        if ($plans->isEmpty()) {
            return ['Escribinos y te contamos los planes disponibles.'];
        }

        $rows = SubscriptionPlan::comparisonRows($plans);
        $features = array_values(array_filter($rows, fn ($row) => is_bool(reset($row[1]))));
        $everywhere = array_column(array_filter($features, fn ($row) => ! in_array(false, $row[1], true)), 0);

        $items = [];
        $previous = null;
        foreach ($plans as $plan) {
            // Los límites, con las mismas frases que «Mi suscripción» (PlanLimit::planLabel).
            $line = $plan->shortName().': '.$plan->formattedPrice().' por mes. '
                .implode(' · ', array_map(fn (PlanLimit $l) => $l->planLabel($plan->limit($l)), PlanLimit::cases())).'.';

            $own = array_column(array_filter($features, fn ($row) => $row[1][$plan->slug] && ! in_array($row[0], $everywhere, true)), 0);
            if ($previous !== null) {
                $before = array_column(array_filter($features, fn ($row) => $row[1][$previous->slug]), 0);
                $added = array_values(array_diff($own, $before));
                // Si el plan anterior tiene algo que este no, se lista todo lo propio (no «todo lo de…»).
                $line .= array_diff($before, array_column(array_filter($features, fn ($row) => $row[1][$plan->slug]), 0)) === []
                    ? ($added === [] ? '' : ' Todo lo de '.$previous->shortName().', más: '.self::join($added).'.')
                    : ($own === [] ? '' : ' Incluye además: '.self::join($own).'.');
            } elseif ($own !== []) {
                $line .= ' Incluye además: '.self::join($own).'.';
            }
            $items[] = $line;
            $previous = $plan;
        }

        return [
            ($plans->every(fn (SubscriptionPlan $p) => $p->currency === 'ARS') ? 'Precios mensuales en pesos argentinos. ' : '')
                .'En todos los planes: '.self::join($everywhere).'.',
            $items,
            $trialLine.' La comparativa completa está en la página, en «Planes».',
        ];
    }

    private static function join(array $labels): string
    {
        // «Tareas y…» → «tareas y…», pero las siglas quedan («QR público…»).
        return implode(', ', array_map(fn (string $l) => preg_match('/^\p{Lu}\p{Ll}/u', $l) ? mb_strtolower(mb_substr($l, 0, 1)).mb_substr($l, 1) : $l, $labels));
    }
}
