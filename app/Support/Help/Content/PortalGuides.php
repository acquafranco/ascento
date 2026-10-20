<?php

namespace App\Support\Help\Content;

use App\Enums\PlanFeature as Feature;
use App\Support\Help\HelpGuides as H;

/**
 * Artículos de la ayuda del portal del cliente (consorcio o administración).
 * Solo lo que el portal muestra: nada del panel de la empresa.
 */
final class PortalGuides
{
    /** @return list<array> */
    public static function all(): array
    {
        $a = fn (string $category, string $key, string $title, string $summary, array $steps, array $o = []) => H::make(H::PORTAL, $category, $key, $title, $summary, $steps, $o);

        return [
            $a('p-portal', 'p_home', 'Qué ves en el portal', 'La información que tu empresa de mantenimiento comparte de tus edificios.', [
                'En «Inicio» ves tus edificios con su último mantenimiento e inspección, quiénes los atienden y lo último compartido.',
                'Solo ves los edificios que la empresa te habilitó.',
            ], ['keywords' => ['portal', 'inicio', 'edificios', 'que veo'], 'related' => ['p_documents', 'p_visits']]),

            $a('p-portal', 'p_companies', 'Si trabajás con más de una empresa', 'Una cuenta para todas.', [
                'Arriba, elegí la empresa cuya información querés ver.',
            ], ['keywords' => ['empresas', 'cambiar empresa', 'varias']]),

            $a('p-documentos', 'p_documents', 'Documentos, remitos y presupuestos', 'Todo lo que te compartieron, en un lugar.', [
                'En «Documentos» filtrá por tipo (remitos, reportes, presupuestos, documentación técnica) y por edificio.',
                'Abrí cada uno para verlo o descargar el PDF.',
            ], ['keywords' => ['documentos', 'remitos', 'presupuestos', 'pdf', 'descargar'], 'related' => ['p_fix_document']]),

            $a('p-documentos', 'p_visits', 'Mantenimientos e inspecciones', 'Las visitas de cada mes.', [
                'En «Mantenimientos» e «Inspecciones» ves las visitas registradas de tus edificios.',
            ], ['keywords' => ['mantenimiento', 'inspeccion', 'visitas', 'mes']]),

            $a('p-ayuda', 'p_claims', 'Cómo presentar un reclamo y seguir las incidencias', 'Para avisarle a tu empresa de mantenimiento que un ascensor tiene un problema.', [
                'Entrá a «Reclamos e incidencias» y tocá «Nuevo reclamo».',
                'Elegí el edificio, el ascensor si sabés cuál, y qué pasó.',
                'Contá brevemente lo que sucede. Si querés, agregá cuándo pasó y hasta 3 fotos.',
                'Tocá «Enviar reclamo». Te mostramos el número de reclamo.',
            ], [
                'after' => 'En «Reclamos e incidencias» ves su estado y las respuestas. También aparecen los problemas que alguien reportó desde el QR de tus edificios, si la empresa los compartió con vos. Que figure registrado no significa que ya lo hayan leído.',
                'notes' => ['Los estados son el seguimiento administrativo de la empresa: no confirman una reparación.', 'Si hay alguien atrapado o un peligro inmediato, usá la alarma o el intercomunicador del ascensor y llamá al 911: un reclamo no es un canal de emergencias.'],
                'keywords' => ['reclamo', 'queja', 'problema', 'ascensor roto', 'avisar', 'falla', 'incidencia', 'qr'],
                'feature' => Feature::ClientPortal,
            ]),

            $a('p-documentos', 'p_summary', 'Resumen del mes de un edificio', 'Visitas, documentos, reclamos y presupuestos del mes, con lo que la empresa registró.', [
                'Entrá a un edificio desde «Inicio» y tocá «Resumen del mes».',
                'Con «Mes anterior» y «Mes siguiente» cambiás de mes.',
            ], [
                'after' => 'Cada número sale de lo que la empresa registró y compartió en Ascento. Si no hay registros, lo dice.',
                'notes' => ['No es un certificado de cumplimiento ni una evaluación técnica de seguridad.', 'Las visitas previstas aparecen solo si la empresa cargó una frecuencia de visitas para el edificio.'],
                'keywords' => ['resumen', 'mensual', 'informe', 'mes', 'transparencia'],
            ]),

            $a('p-ayuda', 'p_emergencies', 'Pedidos de ayuda (historial)', 'Los pedidos por posible atrapamiento que se registraron mientras esa opción existía.', [
                'En «Pedidos de ayuda (historial)» ves los que se registraron en tus edificios, con su hora y su estado.',
                'Esa opción ya no existe en el QR: hoy el QR permite reportar problemas.',
            ], [
                'notes' => ['Ante un atrapamiento o un peligro, usá la alarma del ascensor y llamá al 911.'],
                'keywords' => ['emergencia', 'atrapado', 'pedido de ayuda', 'qr', 'historial'],
                'feature' => Feature::EmergencyRequests,
                'related' => ['p_notifications'],
            ]),

            $a('p-cuenta', 'p_notifications', 'Avisos', 'Enterate cuando te compartan algo.', [
                'En «Inicio», tocá «Activar» en «Avisos en este dispositivo».',
                'Todos los avisos quedan en «Avisos».',
            ], ['notes' => ['En iPhone los avisos funcionan solo desde Safari.'], 'keywords' => ['avisos', 'notificaciones', 'push']]),

            $a('p-cuenta', 'p_password', 'Si olvidaste tu contraseña', 'Cómo volver a entrar.', [
                'En la pantalla de ingreso, tocá «¿Olvidaste tu contraseña?» y escribí tu correo.',
                'Seguí el enlace que te llega para elegir una contraseña nueva.',
            ], ['keywords' => ['contrasena', 'clave', 'olvide', 'no puedo entrar']]),

            $a('p-problemas', 'p_fix_document', 'No veo un documento o un edificio', 'Lo decide tu empresa de mantenimiento.', [
                'Pedile a la empresa que lo comparta o que te habilite el edificio.',
            ], ['keywords' => ['no veo', 'documento', 'edificio', 'falta']]),
        ];
    }
}
