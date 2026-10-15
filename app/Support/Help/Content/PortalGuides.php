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

            $a('p-ayuda', 'p_emergencies', 'Pedidos de ayuda desde el QR', 'Los pedidos por posible atrapamiento hechos desde el QR de tus edificios.', [
                'Si alguien toca «Estoy atrapado en el ascensor» en el QR de un edificio que tenés autorizado, te llega un aviso.',
                'En «Pedidos de ayuda» ves cada pedido con su edificio, su hora y su estado.',
            ], [
                'notes' => ['La función depende de que tu empresa de mantenimiento la tenga habilitada.', 'No es un servicio de emergencias: no garantiza una atención inmediata. Ante peligro, usá la alarma del ascensor o llamá al 911.'],
                'keywords' => ['emergencia', 'atrapado', 'pedido de ayuda', 'qr', 'alerta'],
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
