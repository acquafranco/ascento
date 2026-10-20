<?php

namespace App\Support\Help\Content;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature as Feature;
use App\Models\SubscriptionPlan;
use App\Support\Help\HelpGuides as H;

/**
 * Preguntas frecuentes del Centro de ayuda. Cada una sigue las mismas
 * reglas que los artículos (perfil, plan, permisos) y puede llevar a la
 * guía completa (`guide`).
 */
final class HelpFaq
{
    /** @return list<array{key: string, audience: string, q: string, a: string, guide: ?string, keywords: list<string>, feature: ?Feature, area: ?Area, owner: bool, super: bool}> */
    public static function all(): array
    {
        $f = fn (string $audience, string $key, string $q, string $a, ?string $guide = null, array $o = []) => [
            'key' => $key, 'audience' => $audience, 'q' => $q, 'a' => $a, 'guide' => $guide,
            'keywords' => $o['keywords'] ?? [], 'feature' => $o['feature'] ?? null, 'area' => $o['area'] ?? null,
            'owner' => $o['owner'] ?? false, 'super' => $o['super'] ?? false,
        ];
        $P = H::PANEL;
        $T = H::TECHNICIAN;
        $C = H::PORTAL;

        return [
            $f($P, 'faq_visit_done', '¿Cuándo un mantenimiento cuenta como hecho?', 'Cuando el técnico firma el remito marcando que el trabajo se realizó correctamente. Abrir una pantalla o cargar fotos no lo da por hecho, y un remito «no realizado» tampoco.', 'work_cycle', ['area' => Area::Visits, 'keywords' => ['hecho', 'cumplido', 'realizado']]),
            $f($P, 'faq_services_visits', '¿Contratar un servicio crea las visitas?', 'No. Las visitas salen de las asignaciones de técnicos y de la frecuencia de visitas de cada edificio. El servicio contratado genera los cobros.', 'services_overview', ['area' => Area::Receivables, 'keywords' => ['visitas', 'contrato', 'servicio']]),
            $f($P, 'faq_frequency', '¿La frecuencia de visitas cambia lo que cobro?', 'No. La frecuencia de visitas (Edificios) y la frecuencia de cobro (Servicios contratados) son independientes.', 'assignments', ['area' => Area::Buildings, 'keywords' => ['frecuencia', 'cobro', 'visitas']]),
            $f($P, 'faq_delete', '¿Puedo borrar un remito, un cliente o un edificio?', 'Los remitos no se borran: son el historial del trabajo. Los clientes, edificios y técnicos se desactivan: dejan de aparecer pero conservan su historial y se pueden reactivar.', 'deactivate', ['keywords' => ['borrar', 'eliminar', 'baja']]),
            $f($P, 'faq_emergency_guarantee', '¿Un reporte desde el QR garantiza que alguien vaya?', 'No. Ascento registra el reporte y avisa a las personas de tu empresa que corresponden, pero no puede confirmar que lo vean ni garantizar una respuesta inmediata. No es un servicio de emergencias: la página del QR siempre indica usar la alarma del ascensor y, ante peligro, llamar al 911.', 'claims', ['feature' => Feature::QrReports, 'keywords' => ['emergencia', 'garantia', 'reporte', 'qr', 'atrapado']]),
            $f($P, 'faq_emergency_plan', '¿El QR permite reportar problemas en todos los planes?', 'No. El QR público está en todos los planes; «Reportar un problema» está en Profesional y Empresa, y el dueño lo puede apagar en «Mi empresa». El antiguo pedido de ayuda por atrapamiento ya no existe.', 'qr_reports_setup', ['feature' => Feature::PublicQr, 'keywords' => ['qr', 'plan', 'reporte', 'emergencia']]),
            $f($P, 'faq_portal_what', '¿Qué ve el cliente en el portal?', 'Solo sus edificios autorizados: las visitas registradas, los técnicos que los atienden y lo que vos compartas (remitos, reportes, presupuestos y documentos). Nada es visible hasta que lo compartís.', 'portal_share', ['feature' => Feature::ClientPortal, 'area' => Area::Clients, 'keywords' => ['portal', 'cliente', 've']]),
            $f($P, 'faq_staff_vs_portal', '¿Cuál es la diferencia entre un administrativo y un usuario del portal?', 'Un administrativo trabaja en tu empresa y entra al panel con permisos por área. Un usuario del portal es de tu cliente (por ejemplo, la administración del consorcio) y solo ve sus edificios autorizados.', 'staff', ['keywords' => ['administrativo', 'administrador', 'portal', 'consorcio']]),
            $f($P, 'faq_on_call_dispatch', '¿Las guardias asignan los reclamos solas?', 'No. Las guardias organizan quién cubre cada horario. Las órdenes las asignás vos; el formulario de la orden te muestra quién está de guardia en ese momento.', 'on_call_work', ['feature' => Feature::OnCall, 'keywords' => ['guardia', 'automatico', 'reclamo']]),
            $f($P, 'faq_trial', '¿Cuánto dura la prueba gratis?', SubscriptionPlan::TRIAL_DAYS.' días desde el registro. Cuándo termina y cómo suscribirte lo ves en «Mi suscripción».', 'subscription', ['owner' => true, 'keywords' => ['prueba', 'gratis', 'trial', 'dias']]),
            $f($P, 'faq_data_safe', '¿Otras empresas pueden ver mis datos?', 'No. Cada empresa ve solo lo suyo, y cada usuario solo lo que su rol y sus permisos le permiten. Los permisos se controlan en el servidor en cada pantalla.', null, ['keywords' => ['seguridad', 'privacidad', 'datos']]),
            $f($P, 'faq_menu_memory', '¿Por qué el menú aparece con las categorías cerradas?', 'Para que veas primero lo importante. Abrí las que uses: Ascento recuerda en este navegador cuáles dejaste abiertas, solo para tu usuario.', 'nav', ['keywords' => ['menu', 'cerrado', 'categorias']]),

            $f($T, 'faq_t_not_done', '¿Qué hago si no pude hacer el trabajo?', 'Hacé el remito igual y destildá «Trabajo realizado correctamente», con una observación. La oficina lo ve y coordina otra visita.', 't_visit', ['keywords' => ['no pude', 'no realizado']]),
            $f($T, 'faq_t_swap', '¿Puedo cambiar mi guardia con un compañero?', 'Los cambios los registra la oficina. Avisales y, cuando lo carguen, te llega el aviso y lo ves en «Guardias».', 't_on_call', ['feature' => Feature::OnCall, 'keywords' => ['cambiar guardia', 'cambio']]),
            $f($T, 'faq_t_offline', '¿Se pierde el remito si me quedo sin señal?', 'No se envía sin conexión, pero lo que cargaste queda en la pantalla. Cuando vuelva la señal, tocá «Generar remito» de nuevo.', 't_offline', ['keywords' => ['senal', 'conexion']]),

            $f($C, 'faq_p_share', '¿Por qué no veo todos los remitos?', 'Tu empresa de mantenimiento decide qué compartir en el portal. Si necesitás uno, pedíselo.', 'p_fix_document', ['keywords' => ['remitos', 'no veo']]),
            $f($C, 'faq_p_emergency', '¿Un reclamo o un reporte reemplaza la alarma del ascensor?', 'No. Ante un atrapamiento o un peligro, usá siempre la alarma o el intercomunicador del ascensor y llamá al 911. Los reclamos y los reportes del QR son para avisar problemas, no para pedir asistencia urgente.', 'p_claims', ['keywords' => ['emergencia', 'alarma', '911', 'atrapado']]),
        ];
    }
}
