<?php

namespace App\Support\Help\Content;

use App\Enums\PlanFeature as Feature;
use App\Support\Help\HelpGuides as H;

/**
 * Artículos de la ayuda de la app del técnico (celular). Solo lo que un
 * técnico puede hacer: nada del panel de la empresa.
 */
final class TechnicianGuides
{
    /** @return list<array> */
    public static function all(): array
    {
        $a = fn (string $category, string $key, string $title, string $summary, array $steps, array $o = []) => H::make(H::TECHNICIAN, $category, $key, $title, $summary, $steps, $o);

        return [
            $a('t-dia', 't_day', 'Cómo está organizada la app', 'Todo lo tuyo, desde el celular.', [
                '«Inicio»: lo próximo que tenés que hacer.',
                '«Visitas»: los mantenimientos e inspecciones del mes en tus edificios.',
                '«Trabajos»: las órdenes que te asignó la oficina.',
                '«Reportes»: las fallas que cargaste.',
                '«Guardias»: cuándo te toca cubrir fuera de horario y los cambios de guardia.',
                'En «Más» están los remitos, la planilla del mes, todos los edificios, tu perfil y esta ayuda.',
            ], ['keywords' => ['app', 'menu', 'inicio', 'donde esta'], 'related' => ['t_visit', 't_work_order']]),

            $a('t-dia', 't_visit', 'Hacer un mantenimiento o una inspección', 'De la visita al remito firmado.', [
                'En «Visitas», buscá el edificio y tocá «Marcar mantenimiento» (o «Marcar inspección»).',
                'Completá el remito: equipos, si el trabajo se realizó correctamente y observaciones.',
                'Firmá en el recuadro. Si está el encargado, que firme y escriba su aclaración.',
                'Tocá «Generar remito».',
            ], [
                'after' => 'La visita queda registrada en la oficina y el remito queda en «Más → Remitos».',
                'notes' => ['Si no se pudo hacer, destildá «Trabajo realizado correctamente»: la oficina lo ve y coordina otra visita.'],
                'related' => ['t_share', 't_fix_sign', 't_offline'],
                'keywords' => ['mantenimiento', 'inspeccion', 'visita', 'remito', 'marcar', 'firmar'],
            ]),

            $a('t-dia', 't_photos', 'Fotos de antes y después', 'Si tu empresa las habilitó.', [
                'En la tarjeta del edificio, usá «Tomar foto» o «Elegir de la galería» en cada zona.',
                'Después hacé el remito como siempre.',
            ], ['keywords' => ['fotos', 'camara', 'galeria'], 'feature' => Feature::WorkPhotos, 'related' => ['t_visit']]),

            $a('t-dia', 't_share', 'Compartir un remito', 'Mandarle el remito al encargado o al cliente.', [
                'Abrí el remito desde «Más → Remitos».',
                'Tocá «Compartir» y elegí «Enviar por WhatsApp», «Enviar por correo», el PDF o copiar el enlace.',
            ], ['keywords' => ['compartir', 'whatsapp', 'correo', 'pdf', 'enviar remito'], 'related' => ['t_visit']]),

            $a('t-ordenes', 't_work_order', 'Tomar y cerrar una orden de trabajo', 'Los reclamos y trabajos puntuales.', [
                'En «Trabajos», abrí la orden y tocá «Tomar trabajo».',
                'Al terminar, tocá «Hacer remito y finalizar».',
                'Declará los materiales que usaste con «+ Agregar material», firmá y generá el remito.',
            ], [
                'after' => 'La orden queda completada y los materiales se descuentan del stock de la empresa.',
                'keywords' => ['orden', 'trabajo', 'reclamo', 'tomar', 'finalizar', 'materiales'],
                'related' => ['t_visit', 't_on_call'],
            ]),

            $a('t-ordenes', 't_report', 'Cargar un reporte de falla', 'Para que la oficina sepa qué falla y dónde.', [
                'En «Reportes», tocá «Nuevo».',
                'Elegí el edificio y el equipo, contá qué parte falla y la prioridad.',
                'Sacá una foto o elegí una de la galería.',
                'Tocá «Enviar reporte».',
            ], ['after' => 'A la oficina le llega un aviso.', 'keywords' => ['reporte', 'falla', 'problema', 'averia', 'foto'], 'related' => ['t_video']]),

            $a('t-ordenes', 't_video', 'Agregar un video al reporte', 'Un video corto donde se vea la falla.', [
                'Al crear el reporte, tocá «Agregar video» y grabá uno corto.',
            ], ['keywords' => ['video', 'grabar'], 'feature' => Feature::ReportVideos, 'related' => ['t_report']]),

            $a('t-guardias', 't_on_call', 'Tus guardias', 'Cuándo te toca cubrir fuera de horario.', [
                'En «Guardias» ves si estás de guardia ahora (arriba, destacado), tus próximas guardias y las anteriores.',
                'Si la oficina cambia una guardia, te llega un aviso y el cambio aparece en tus cambios de guardia.',
                'Los trabajos que te asignen durante una guardia los hacés en «Trabajos», como siempre.',
            ], [
                'notes' => ['La planificación la arma la oficina: si no podés cubrir una guardia, avisales.'],
                'keywords' => ['guardia', 'turno', 'noche', 'fin de semana', 'reemplazo'],
                'feature' => Feature::OnCall,
                'related' => ['t_work_order'],
            ]),

            $a('t-cuenta', 't_notifications', 'Recibir avisos de trabajos nuevos', 'Para enterarte en el momento.', [
                'En «Mi perfil», activá los avisos en este celular.',
                'Si tu empresa lo tiene, también podés conectar Telegram en «Avisos por Telegram».',
            ], ['notes' => ['En iPhone, agregá Ascento a la pantalla de inicio desde Safari para recibir avisos.'], 'keywords' => ['avisos', 'notificaciones', 'push', 'telegram'], 'related' => ['t_fix_push']]),

            $a('t-cuenta', 't_profile', 'Tu perfil y tu foto', 'Tus datos y la foto que ve tu empresa y sus clientes.', [
                'En «Mi perfil», tocá «Subir foto» (o «Cambiar foto»). JPG, PNG o WebP de hasta 2 MB.',
                'Ahí también cambiás tu contraseña.',
            ], ['keywords' => ['perfil', 'foto', 'contrasena', 'clave'], 'related' => ['t_notifications']]),

            $a('t-problemas', 't_fix_building', 'No veo un edificio', 'Las asignaciones las hace la oficina.', [
                'Pedile a la oficina que te asigne el edificio para ese trabajo (mantenimiento o inspección). Mientras tanto, podés buscarlo en «Todos los edificios».',
            ], ['keywords' => ['no veo', 'edificio', 'asignacion']]),
            $a('t-problemas', 't_fix_sign', 'No me deja generar el remito', 'Falta tu firma u otro dato obligatorio.', [
                'Tocá el recuadro de firma y firmá. El mensaje en rojo te dice qué falta.',
            ], ['keywords' => ['remito', 'firma', 'error', 'no me deja']]),
            $a('t-problemas', 't_offline', 'No tengo señal al terminar', 'El remito no se manda sin conexión.', [
                'Si no hay conexión, Ascento te avisa y no envía el remito, pero no se pierde lo que cargaste.',
                'Esperá a tener señal sin cerrar la página y tocá «Generar remito» de nuevo.',
            ], ['keywords' => ['sin senal', 'sin conexion', 'internet', 'offline', 'datos']]),
            $a('t-problemas', 't_fix_push', 'No me llegan los avisos', 'Los avisos se activan en cada celular.', [
                'En «Mi perfil», activalos de nuevo y aceptá el permiso. En iPhone, desde la app agregada a la pantalla de inicio.',
            ], ['keywords' => ['avisos', 'notificaciones', 'no llegan']]),
        ];
    }
}
