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
     * Temas en el orden en que se muestran: primero lo que lleva a probar o a
     * hablar con nosotros, al final las consultas que no son comerciales
     * (vecinos y urgencias).
     *
     * Bloques de la respuesta: string = párrafo; lista de strings = viñetas;
     * ['details' => título, 'items' => [...]] = detalle desplegable.
     * subjects = consultas puntuales («Plan Profesional») con su botón.
     *
     * @return list<array{key: string, label: string, kind: string, answer: list<mixed>, actions: list<string>, handoff: bool, cta: string, subjects: list<string>}>
     */
    public static function topics(): array
    {
        return once(function () {
            $plans = SubscriptionPlan::offered();
            $trial = SubscriptionPlan::findBySlug(SubscriptionPlan::TRIAL_PLAN)?->shortName();
            $trialLine = 'Podés probarlo gratis '.SubscriptionPlan::TRIAL_DAYS.' días'.($trial ? ' con todas las funciones del plan '.$trial : '').', sin tarjeta.';
            // Sin número válido, el contacto es por correo (y los textos lo dicen).
            $whatsapp = self::whatsappNumber() !== null;
            $channel = $whatsapp ? 'por WhatsApp' : 'a '.config('app.support_email');
            $t = fn (string $key, string $label, array $answer, array $actions = [], bool $handoff = true, string $kind = self::SALES, string $cta = 'Consultar por WhatsApp', array $subjects = []) => compact('key', 'label', 'kind', 'answer', 'actions', 'handoff', 'cta', 'subjects');

            $topics = [
                $t('plans', 'Planes y precios', self::plansAnswer($plans, $trialLine), ['trial', 'plans'],
                    subjects: $plans->map(fn (SubscriptionPlan $p) => 'Plan '.$p->shortName())->all()),
                $t('trial', 'Probar gratis o pedir una demostración', [
                    $trialLine.' Te registrás desde la página y empezás a cargar tu empresa.',
                    'Si preferís que te lo mostremos, escribinos '.$channel.' y coordinamos una demostración.',
                ], ['trial'], cta: 'Pedir una demostración'),
                $t('what', 'Qué es Ascento', [
                    'Un sistema para empresas de mantenimiento de ascensores: clientes, edificios, equipos, técnicos, mantenimientos, órdenes de trabajo y remitos en un solo lugar.',
                    'Se usa desde la computadora y el celular, sin instalar nada.',
                ], ['trial', 'plans']),
                $t('how', 'Cómo funciona', [[
                    'Cargás tu empresa, tus edificios, ascensores y técnicos.',
                    'Cada técnico ve en el celular lo suyo y cierra el trabajo con el remito firmado.',
                    'Desde el panel ves qué falta, qué se hizo y qué necesita atención.',
                ]], ['trial']),
            ];

            if ($available = self::availability($plans, PlanFeature::Agenda)) {
                $topics[] = $t('maintenance', 'Mantenimientos e inspecciones', [
                    'Cada edificio tiene su frecuencia de visitas y su técnico. La agenda del mes muestra lo hecho, lo pendiente y lo vencido; una visita cuenta como hecha solo con el remito firmado.',
                    $available,
                ], ['trial']);
            }
            if ($available = self::availability($plans, PlanFeature::WorkOrders, PlanFeature::DigitalDeliveryNotes)) {
                $topics[] = $t('delivery_notes', 'Remitos digitales', [
                    'El técnico firma el remito en el celular (y el cliente también, si querés). Queda numerado y lo compartís en PDF o con un enlace por WhatsApp o correo.',
                    $available,
                ], ['trial']);
            }
            if ($available = self::availability($plans, PlanFeature::OnCall)) {
                $topics[] = $t('on_call', 'Guardias técnicas', [
                    'Organizás qué técnico está de guardia cada noche, fin de semana o feriado: por período, de forma permanente o en rotación. Los cambios de guardia quedan registrados con su motivo.',
                    'Cada técnico ve sus guardias en el celular, y los trabajos que surgen se cargan como órdenes de trabajo vinculadas a la guardia. Organiza a tu equipo: no es un servicio de atención de emergencias.',
                    $available.' Sin costo adicional.',
                ], ['trial']);
            }
            if ($available = self::availability($plans, PlanFeature::ClientPortal)) {
                $topics[] = $t('portal', 'Portal para tus clientes', [
                    'Cada consorcio o administración entra con su usuario y ve solo lo que le compartís de sus edificios: remitos, reportes, presupuestos y documentos.',
                    $available,
                ], ['plans']);
            }
            if ($available = self::availability($plans, PlanFeature::PublicQr)) {
                $topics[] = $t('qr', 'QR público del edificio', [
                    'Quien escanea el QR del edificio ve, sin cuenta, solo lo que la empresa publica: estado informado de cada ascensor, último mantenimiento e inspección, pruebas de paracaídas y componentes en reparación.',
                    'No muestra datos del cliente ni montos. Es información administrativa, no una certificación de seguridad.',
                    $available,
                    ...(($help = self::availability($plans, PlanFeature::PublicQr, PlanFeature::EmergencyRequests))
                        ? ['Si la empresa lo activa, el QR también muestra «Estoy atrapado en el ascensor»: avisa a la empresa y a la administración del edificio. Es un aviso, no un servicio de emergencias. '.$help]
                        : []),
                ], ['trial']);
            }

            $topics[] = $t('support', 'Ya uso Ascento y necesito ayuda', [
                'En Ascento, entrá a «Ayuda»: hay guías paso a paso según lo que podés usar.',
                'Si no se resuelve, escribinos '.($whatsapp ? 'por WhatsApp o a '.config('app.support_email') : 'a '.config('app.support_email')).'. Nunca te vamos a pedir tu contraseña ni códigos.',
            ]);
            $topics[] = $t('human', 'Hablar con una persona', [$whatsapp
                ? 'Se abre WhatsApp con tu consulta lista. Los datos son opcionales.'
                : 'Escribinos a '.config('app.support_email').' con tu consulta.']);

            // Al final, con respuesta fija y sin pasar a ventas: no son consultas comerciales.
            $topics[] = $t('residents', 'Soy vecino o de la administración de un edificio', [
                'Ascento es un sistema de gestión para empresas de mantenimiento de ascensores. No atiende edificios ni tiene información de tu ascensor.',
                'Para cualquier consulta sobre el ascensor, comunicate con la administración del edificio o con la empresa que hace el mantenimiento.',
            ], [], false, self::FIXED);
            $topics[] = $t('emergency', 'Tengo una urgencia con un ascensor', [
                'Ascento no es un servicio de emergencias ni de mantenimiento, y no puede resolver una falla desde acá.',
                'Si hay peligro inmediato, por ejemplo una persona atrapada o herida, comunicate con el servicio de emergencias de tu zona y con la empresa responsable del mantenimiento del ascensor.',
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
     * planes: "Hasta 20", "21 a 120", "121 a 400", "Más de 400".
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

    /**
     * Número de WhatsApp comercial (solo dígitos) o null si no hay uno válido:
     * internacional, de 10 a 15 dígitos y sin 0 inicial (E.164). Sin número,
     * el asistente ofrece el correo de soporte.
     */
    public static function whatsappNumber(): ?string
    {
        $number = (string) config('app.whatsapp_contact');

        return preg_match('/^[1-9]\d{9,14}$/', $number) ? $number : null;
    }

    /** El número para mostrar: «+54 9 11 7372-8421» (otros países: «+<dígitos>»). */
    public static function displayNumber(): ?string
    {
        $number = self::whatsappNumber();
        if ($number === null) {
            return null;
        }

        return preg_match('/^549(11)(\d{4})(\d{4})$/', $number, $m) ? "+54 9 {$m[1]} {$m[2]}-{$m[3]}" : '+'.$number;
    }

    /** Enlace de WhatsApp con el mensaje armado (lo envía la persona). */
    public static function whatsappUrl(string $text = ''): ?string
    {
        $number = self::whatsappNumber();

        return $number === null ? null : 'https://wa.me/'.$number.($text === '' ? '' : '?text='.rawurlencode($text));
    }

    /** Horario de atención publicado (null = no se muestra). */
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
     * Planes en pocas líneas (precio, límites principales y lo que suma cada
     * uno) y el detalle completo desplegable. Sale de la misma comparativa de
     * la página de inicio (comparisonRows) y de PlanLimit::planLabel().
     *
     * @return list<mixed>
     */
    private static function plansAnswer(Collection $plans, string $trialLine): array
    {
        if ($plans->isEmpty()) {
            return ['Escribinos y te contamos los planes disponibles.'];
        }

        $rows = SubscriptionPlan::comparisonRows($plans);
        $features = array_values(array_filter($rows, fn ($row) => is_bool(reset($row[1]))));
        $everywhere = array_column(array_filter($features, fn ($row) => ! in_array(false, $row[1], true)), 0);
        $main = [PlanLimit::Buildings, PlanLimit::Technicians, PlanLimit::ReportsPerMonth];
        $label = fn (SubscriptionPlan $plan, PlanLimit $l) => mb_strtolower(mb_substr($text = $l->planLabel($plan->limit($l)), 0, 1)).mb_substr($text, 1);

        $items = [];
        $limits = [];
        $adds = [];
        // En la línea del plan van hasta 3 funciones; el resto, en «Qué suma cada plan».
        $short = fn (array $labels) => count($labels) > 3
            ? self::join(array_slice($labels, 0, 3)).' y '.(count($labels) - 3).' más'
            : self::join($labels);
        $previous = null;
        foreach ($plans as $plan) {
            $parts = array_map(fn (PlanLimit $l) => $label($plan, $l), $main);
            $line = $plan->shortName().' · '.$plan->formattedPrice().': '.implode(', ', array_slice($parts, 0, -1)).' y '.end($parts).'.';

            $has = array_column(array_filter($features, fn ($row) => $row[1][$plan->slug]), 0);
            $own = array_values(array_diff($has, $everywhere));
            if ($previous !== null) {
                $before = array_column(array_filter($features, fn ($row) => $row[1][$previous->slug]), 0);
                $added = array_values(array_diff($has, $before));
                // Si el plan anterior tiene algo que este no, se lista todo lo propio (no «suma»).
                [$word, $list] = array_diff($before, $has) === [] ? ['Suma', $added] : ['Incluye', $own];
            } else {
                [$word, $list] = ['Incluye', $own];
            }
            if ($list !== []) {
                $line .= ' '.$word.': '.$short($list).'.';
                $adds[] = $plan->shortName().' ('.mb_strtolower($word).'): '.self::join($list).'.';
            }
            $items[] = $line;
            $limits[] = $plan->shortName().': '.implode(' · ', array_map(fn (PlanLimit $l) => $l->planLabel($plan->limit($l)), PlanLimit::cases())).'.';
            $previous = $plan;
        }

        return [
            ($plans->every(fn (SubscriptionPlan $p) => $p->currency === 'ARS') ? 'Precios por mes, en pesos argentinos.' : 'Precios por mes.'),
            $items,
            $trialLine,
            ...($adds === [] ? [] : [['details' => 'Qué suma cada plan', 'items' => $adds]]),
            ['details' => 'Qué incluyen todos los planes', 'items' => array_map(fn (string $l) => mb_strtoupper(mb_substr($l, 0, 1)).mb_substr($l, 1), $everywhere)],
            ['details' => 'Todos los límites', 'items' => $limits],
        ];
    }

    private static function join(array $labels): string
    {
        // «Tareas y…» → «tareas y…», pero las siglas quedan («QR público…»).
        return implode(', ', array_map(fn (string $l) => preg_match('/^\p{Lu}\p{Ll}/u', $l) ? mb_strtolower(mb_substr($l, 0, 1)).mb_substr($l, 1) : $l, $labels));
    }
}
