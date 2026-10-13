<?php

namespace App\Support\Help;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use App\Support\Portal\PortalAccess;

/**
 * Guías prácticas de Ascento (Etapa 9.3): una sola definición para el panel
 * de la empresa, la app del técnico y el portal del cliente. Cada guía dice
 * a quién está dirigida, qué función del plan necesita y, en el panel, qué
 * área de permisos (o si es solo del dueño): cada usuario ve solo las que
 * puede usar. Los textos describen el comportamiento real de la app.
 *
 * Las ayudas cortas de cada pantalla (botón «?» y tarjetas) siguen en
 * AdminOnboarding::sectionHelp y HelpTopics; acá van los pasos completos.
 */
final class HelpGuides
{
    public const PANEL = 'panel';

    public const TECHNICIAN = 'technician';

    public const PORTAL = 'portal';

    public const TROUBLESHOOTING = 'Problemas frecuentes';

    /**
     * @return list<array{key: string, audience: string, module: string, title: string, summary: string, steps: list<string>, notes: list<string>, feature: ?PlanFeature, area: ?PermissionArea, owner: bool}>
     */
    public static function all(): array
    {
        $g = fn (string $audience, string $module, string $key, string $title, string $summary, array $steps, array $notes = [], ?PlanFeature $feature = null, ?PermissionArea $area = null, bool $owner = false) => compact('key', 'audience', 'module', 'title', 'summary', 'steps', 'notes', 'feature', 'area', 'owner');
        $P = self::PANEL;
        $T = self::TECHNICIAN;
        $C = self::PORTAL;

        return [
            /* ============================== PANEL ============================== */
            $g($P, 'Primeros pasos', 'start', 'Poner en marcha tu empresa', 'El orden recomendado para empezar a usar Ascento.', [
                'En «Mi empresa», completá los datos y subí el logo: salen en los remitos y presupuestos.',
                'En «Técnicos», creá a cada técnico con su correo y una contraseña. Con eso entran desde el celular.',
                'En «Clientes», cargá cada consorcio, administración o empresa.',
                'En «Edificios», cargá cada edificio con su cliente, dirección y cantidad de ascensores y montacargas: los equipos se crean solos.',
                'En la lista de edificios, usá «Asignar empleado» para elegir quién hace el mantenimiento y quién la inspección.',
                'Si un edificio no se visita una vez por mes, cambiá su «Frecuencia de visitas».',
            ], ['Desde ese momento cada técnico ve sus visitas del mes en el celular.'], owner: true),

            $g($P, 'Clientes, edificios y ascensores', 'clients_buildings', 'Clientes y edificios', 'Cómo se organiza la cartera.', [
                'Cada edificio pertenece a un cliente. Creá primero el cliente y después sus edificios.',
                'La dirección del edificio sirve para ubicarlo en el mapa y para que el técnico lo encuentre.',
                'Para dar de baja un edificio o un cliente, usá «Desactivar»: el historial se conserva y se puede reactivar.',
            ], ['La cantidad de edificios y clientes depende de tu plan: la ves en «Mi suscripción».'], area: PermissionArea::Buildings),

            $g($P, 'Clientes, edificios y ascensores', 'elevators', 'Ascensores y legajo técnico', 'La ficha, la documentación y el historial de cada equipo.', [
                'Entrá a «Ascensores» y abrí el equipo.',
                'Completá la ficha técnica de a poco: fabricante, modelo, número de serie, año, capacidad, paradas, y la marca y el modelo del control.',
                'Subí la documentación (planos, manuales, certificados). A los certificados ponéles vencimiento para que avise.',
                'Para que el cliente vea un documento en su portal, usá «Compartir con el cliente».',
                'Abajo está el historial del equipo: reportes, órdenes, presupuestos y mantenimientos, sin cargar nada aparte.',
            ], feature: PlanFeature::ElevatorFile, area: PermissionArea::Buildings),

            $g($P, 'Clientes, edificios y ascensores', 'assignments', 'Asignar técnicos y frecuencia de visitas', 'Quién atiende cada edificio y cada cuánto.', [
                'En «Edificios», tocá «Asignar empleado» en la fila del edificio.',
                'Elegí los empleados y el trabajo: mantenimiento o inspección.',
                'Con «Frecuencia de visitas» definís si va mensual, quincenal o cada varios meses.',
            ], ['Un técnico solo ve los edificios que tiene asignados para cada tipo de trabajo.'], area: PermissionArea::Buildings),

            $g($P, 'Clientes, edificios y ascensores', 'map', 'Mapa de edificios', 'Toda la cartera en el mapa.', [
                'Los edificios se ubican solos con la dirección cargada.',
                'Si alguno no se pudo ubicar, aparece en «Edificios sin ubicar»: corregí la dirección o marcalo a mano.',
                'Podés filtrar por zona, técnico y estado del mes.',
            ], feature: PlanFeature::Map, area: PermissionArea::Buildings),

            $g($P, 'Clientes, edificios y ascensores', 'public_qr', 'QR público del edificio', 'Un código para que residentes y visitantes vean información del ascensor sin cuenta.', [
                'Entrá a «QR públicos» y abrí el edificio.',
                'Elegí qué se publica y tocá «Generar QR».',
                'Descargalo en SVG (para imprimir) o PNG y pegalo en el edificio.',
                'Si cambiás el estado informado de un ascensor, la página pública lo muestra al instante.',
            ], ['«Regenerar» o «Desactivar» dejan sin efecto el código impreso.', 'Nunca se publican datos del cliente, montos ni notas internas. No es una certificación de seguridad.'], PlanFeature::PublicQr, PermissionArea::PublicPages),

            $g($P, 'Operación diaria', 'visits', 'Mantenimientos e inspecciones', 'Cómo se registra cada visita.', [
                'Cada mes, la «Agenda» arma la lista de visitas según las asignaciones y la frecuencia de cada edificio.',
                'El técnico hace la visita desde el celular y la cierra con el remito firmado.',
                'La ves en «Mantenimientos» o «Inspecciones», con su remito.',
            ], ['Un remito marcado como «no realizado» no cuenta como visita hecha: la agenda la sigue mostrando pendiente.'], area: PermissionArea::Visits),

            $g($P, 'Operación diaria', 'agenda', 'Agenda del mes', 'Qué toca, quién lo tiene y qué falta.', [
                'Entrá a «Agenda» y elegí el mes.',
                'Arriba ves el progreso: visitas previstas, realizadas y pendientes.',
                'Primero aparecen las vencidas y las que no tienen técnico asignado.',
            ], feature: PlanFeature::Agenda, area: PermissionArea::Visits),

            $g($P, 'Operación diaria', 'work_orders', 'Órdenes de trabajo', 'Reclamos y trabajos puntuales.', [
                'En «Órdenes de trabajo», creá una nueva: edificio, ascensor, técnicos, tipo de trabajo, prioridad y detalle.',
                'Al técnico le llega el aviso. La toma con «Tomar trabajo».',
                'Al terminar, la cierra con «Hacer remito y finalizar», donde declara los materiales usados.',
            ], ['Los materiales declarados se descuentan solos del stock.'], area: PermissionArea::WorkOrders),

            $g($P, 'Operación diaria', 'delivery_notes', 'Remitos digitales y firmas', 'El cierre de cada trabajo, firmado en el celular.', [
                'El técnico completa el remito al terminar y lo firma. El cliente puede firmar también, con su aclaración.',
                'En «Remitos» ves todos, con las firmas.',
                'Desde cada remito podés descargar el PDF o compartirlo con el cliente por enlace, WhatsApp o correo.',
            ], ['Los remitos no se borran: son el historial del trabajo.'], area: PermissionArea::Documents),

            $g($P, 'Operación diaria', 'reports', 'Reportes de fallas', 'Lo que detectan los técnicos en los equipos.', [
                'El técnico carga el reporte desde el celular: edificio, equipo, qué parte falla, prioridad y fotos.',
                'En «Reportes», cambiá el estado a «En revisión» o «Resuelto» a medida que lo atendés.',
            ], area: PermissionArea::Inspections),

            $g($P, 'Operación diaria', 'report_videos', 'Video en los reportes', 'Un video corto para mostrar la falla.', [
                'El técnico puede agregar un video al crear el reporte.',
                'En el reporte, «Ver video» lo reproduce. Cada reporte admite un solo video.',
            ], feature: PlanFeature::ReportVideos, area: PermissionArea::Inspections),

            $g($P, 'Operación diaria', 'work_photos', 'Fotos de antes y después', 'Fotos de cada trabajo y reportes de inspección con fotos.', [
                'En «Mi empresa», habilitá las fotos de mantenimientos, inspecciones u órdenes.',
                'Los técnicos las sacan en el celular durante la visita, por zona del equipo.',
                'Las ves en cada mantenimiento, inspección u orden.',
            ], feature: PlanFeature::WorkPhotos, area: PermissionArea::Visits),

            $g($P, 'Operación diaria', 'tasks', 'Tareas y recordatorios', 'Pendientes de la oficina, con responsable y aviso.', [
                'En «Tareas», creá una tarea con título, prioridad, vencimiento y, si querés, un recordatorio.',
                'En «Avisar a» elegí quién recibe el recordatorio. Si no elegís a nadie, se avisa al responsable.',
                'El recordatorio llega a la campanita y como aviso en el celular.',
                'En «Mis tareas» ves las tuyas y las que te avisan a vos.',
            ], feature: PlanFeature::Tasks, area: PermissionArea::Tasks),

            $g($P, 'Operación diaria', 'parachute', 'Pruebas de paracaídas', 'Registro de pruebas y aviso de las próximas o vencidas.', [
                'En «Pruebas de paracaídas», tocá «Configurar» en cada ascensor para elegir la periodicidad, los días de aviso, el inspector y el responsable.',
                'Si todo un cliente usa la misma periodicidad, cargala en «Periodicidad por cliente».',
                'Cuando se hace una prueba, usá «Registrar prueba» con la fecha, el resultado y los documentos.',
                'Ascento avisa todos los días las pruebas próximas y vencidas.',
            ], ['Un aviso nunca cuenta como prueba hecha: solo un registro.'], PlanFeature::ParachuteTests, PermissionArea::Inspections),

            $g($P, 'Operación diaria', 'repairs', 'Componentes enviados a reparación', 'Qué está en el taller y cuándo vuelve.', [
                'En «Reparaciones», tocá «Registrar componente»: edificio, ascensor, componente, falla y proveedor.',
                'Con «Cambiar estado» lo vas avanzando: enviado, en diagnóstico, en reparación, reparado y recibido.',
                'Cargá el presupuesto y el costo final, y adjuntá documentos o fotos.',
            ], ['No se puede marcar como reparado un componente que solo fue enviado.', 'No se borran: se cancelan. Cada cambio queda en el historial.'], PlanFeature::Repairs, PermissionArea::Repairs),

            $g($P, 'Comercial y administración', 'quotes', 'Presupuestos', 'Presupuestos con tu logo, numerados y con enlace para el cliente.', [
                'En «Presupuestos», creá uno con sus ítems: el total se calcula solo.',
                'Con «Enviar al cliente» lo mandás por correo. En «Compartir» podés copiar el enlace para el cliente o enviarlo por WhatsApp.',
                'Actualizá el estado (aprobado, rechazado) con «Cambiar estado». Si pasa la validez, figura vencido.',
            ], ['El enlace que recibió el cliente vence solo. Con «Compartir» → «Anular enlaces enviados» los dejás sin efecto antes.'], PlanFeature::Quotes, PermissionArea::Quotes),

            $g($P, 'Comercial y administración', 'contacts', 'Historial de contactos', 'Cada llamado, visita o correo con el cliente.', [
                'En «Historial de contactos», registrá el contacto con su tipo y resultado.',
                'Podés asociarlo a un edificio, un presupuesto o una orden.',
                'El inicio te avisa qué clientes llevan tiempo sin contacto.',
            ], feature: PlanFeature::ClientContacts, area: PermissionArea::Contacts),

            $g($P, 'Comercial y administración', 'stock', 'Stock de repuestos', 'Lo que tenés y lo que se usa.', [
                'En «Stock», cargá cada repuesto con su mínimo.',
                'Cargá las entradas cuando comprás.',
                'Las salidas se descuentan solas cuando el técnico declara materiales en el remito de una orden.',
            ], ['Si un repuesto queda por debajo del mínimo, aparece en el centro de atención.'], area: PermissionArea::Stock),

            $g($P, 'Comercial y administración', 'services_receivables', 'Servicios y cobranzas', 'Contratos de mantenimiento y lo que te deben.', [
                'En «Servicios», cargá el contrato de cada edificio con su monto y frecuencia de cobro.',
                'Cada período genera el cobro solo en «Cobranzas».',
                'Registrá los pagos a medida que entran, también parciales.',
            ], area: PermissionArea::Receivables),

            $g($P, 'Comercial y administración', 'portal', 'Portal del cliente', 'Qué ve cada consorcio o administración.', [
                'Abrí el cliente y, en «Acceso al portal», tocá «Invitar al portal».',
                'Elegí los «Edificios que puede ver». La persona recibe un correo para activar su cuenta.',
                'Para que vea un remito, reporte, presupuesto o documento, usá «Compartir con el cliente».',
            ], ['El cliente también ve los técnicos asignados a sus edificios, con foto, y el contacto de tu empresa (nunca datos personales del técnico).'], PlanFeature::ClientPortal, PermissionArea::Clients),

            $g($P, 'Comercial y administración', 'insights', 'Indicadores y centro de atención', 'Cómo viene tu empresa y qué necesita atención.', [
                'En «Centro de atención» ves solo lo que necesita que hagas algo hoy.',
                'En «Indicadores», cada número responde una pregunta concreta (¿cumplimos los mantenimientos?, ¿entran más reclamos?).',
            ], feature: PlanFeature::Indicators, area: PermissionArea::Indicators),

            $g($P, 'Tu cuenta', 'staff', 'Usuarios administrativos y permisos', 'Personal de oficina con acceso a lo que necesita.', [
                'En «Administrativos», creá el usuario con su correo y contraseña.',
                'Elegí, por área, si puede «ver» o «gestionar».',
                'Podés cambiar los permisos o desactivarlo cuando quieras: el cambio rige al instante.',
            ], ['La cantidad de administrativos depende de tu plan.'], owner: true),

            $g($P, 'Tu cuenta', 'subscription', 'Planes, límites y suscripción', 'Qué incluye tu plan y qué pasa al llegar a un límite.', [
                'En «Mi suscripción» ves tu plan, cuánto usaste de cada límite y los planes disponibles.',
                'Al llegar a un límite (edificios, clientes, técnicos, reportes del mes o administrativos), no podés agregar más hasta cambiar de plan. No se borra nada.',
                'Si una función no está en tu plan, Ascento te lo explica y te lleva a «Ver planes».',
                'Te suscribís o cambiás de plan con Mercado Pago desde la misma pantalla.',
            ], ['Si bajás de plan, los datos de las funciones que ya no tenés se conservan y vuelven a verse si recuperás la función.', 'La prueba gratis dura 30 días e incluye todas las funciones del plan Empresa.'], owner: true),

            $g($P, 'Tu cuenta', 'export', 'Exportar tus datos', 'Una copia de los datos de tu empresa.', [
                'En «Exportar datos», pedí la exportación.',
                'En uno o dos minutos queda lista para descargar: un ZIP con un Excel y los archivos.',
            ], ['Queda disponible unos días y se registra quién la descargó.'], owner: true),

            $g($P, 'Tu cuenta', 'notifications', 'Avisos en la computadora y el celular', 'Enterate de lo importante en el momento.', [
                'La campanita muestra los avisos sin recargar la página.',
                'En el inicio, «Activar avisos» los habilita en este dispositivo.',
            ], ['En iPhone, los avisos funcionan si agregás Ascento a la pantalla de inicio desde Safari.']),

            // Problemas frecuentes del panel.
            $g($P, self::TROUBLESHOOTING, 'fix_technician_building', 'Un técnico no ve un edificio', 'Falta la asignación.', [
                'En «Edificios», usá «Asignar empleado» y elegí el tipo de trabajo correcto (mantenimiento o inspección).',
            ], area: PermissionArea::Buildings),
            $g($P, self::TROUBLESHOOTING, 'fix_visit_pending', 'Un mantenimiento sigue pendiente', 'El remito se firmó como «no realizado».', [
                'Abrí el remito: si dice que no se pudo realizar, coordiná una nueva visita. Recién el remito realizado lo da por hecho.',
            ], area: PermissionArea::Visits),
            $g($P, self::TROUBLESHOOTING, 'fix_limit', 'No puedo agregar más edificios, clientes o técnicos', 'Llegaste al límite de tu plan.', [
                'Mirá tu uso en «Mi suscripción». Podés cambiar de plan o desactivar registros que ya no uses (liberan cupo).',
            ], owner: true),
            $g($P, self::TROUBLESHOOTING, 'fix_portal_document', 'El cliente no ve un documento', 'No está compartido o el edificio no está autorizado.', [
                'Usá «Compartir con el cliente» en el documento y revisá en «Acceso al portal» que tenga ese edificio.',
            ], feature: PlanFeature::ClientPortal, area: PermissionArea::Clients),
            $g($P, self::TROUBLESHOOTING, 'fix_push', 'No me llegan los avisos al celular', 'Los avisos se activan en cada dispositivo.', [
                'Tocá «Activar avisos» en ese celular y aceptá el permiso del navegador.',
                'En iPhone, primero agregá Ascento a la pantalla de inicio desde Safari.',
            ]),

            /* ============================= TÉCNICO ============================= */
            $g($T, 'Tu día', 't_day', 'Cómo está organizada la app', 'Todo lo tuyo, desde el celular.', [
                '«Inicio»: lo próximo que tenés que hacer.',
                '«Visitas»: los mantenimientos e inspecciones del mes en tus edificios.',
                '«Trabajos»: las órdenes que te asignó la oficina.',
                '«Reportes»: las fallas que cargaste.',
                'En «Más» están los remitos, la planilla del mes, todos los edificios y tu perfil.',
            ]),
            $g($T, 'Tu día', 't_visit', 'Hacer un mantenimiento o una inspección', 'De la visita al remito firmado.', [
                'En «Visitas», buscá el edificio y tocá «Marcar mantenimiento» (o «Marcar inspección»).',
                'Completá el remito: equipos, si el trabajo se realizó correctamente y observaciones.',
                'Firmá en el recuadro. Si está el encargado, que firme y escriba su aclaración.',
                'Tocá «Generar remito».',
            ], ['Si no se pudo hacer, destildá «Trabajo realizado correctamente»: la oficina lo ve y coordina otra visita.']),
            $g($T, 'Tu día', 't_photos', 'Fotos de antes y después', 'Si tu empresa las habilitó.', [
                'En la tarjeta del edificio, usá «Tomar foto» o «Elegir de la galería» en cada zona.',
                'Después hacé el remito como siempre.',
            ], feature: PlanFeature::WorkPhotos),
            $g($T, 'Tu día', 't_work_order', 'Tomar y cerrar una orden de trabajo', 'Los reclamos y trabajos puntuales.', [
                'En «Trabajos», abrí la orden y tocá «Tomar trabajo».',
                'Al terminar, tocá «Hacer remito y finalizar».',
                'Declará los materiales que usaste con «+ Agregar material», firmá y generá el remito.',
            ]),
            $g($T, 'Tu día', 't_report', 'Cargar un reporte de falla', 'Para que la oficina sepa qué falla y dónde.', [
                'En «Reportes», tocá «Nuevo».',
                'Elegí el edificio y el equipo, contá qué parte falla y la prioridad.',
                'Sacá una foto o elegí una de la galería.',
                'Tocá «Enviar reporte».',
            ]),
            $g($T, 'Tu día', 't_video', 'Agregar un video al reporte', 'Un video corto donde se vea la falla.', [
                'Al crear el reporte, tocá «Agregar video» y grabá uno corto.',
            ], feature: PlanFeature::ReportVideos),
            $g($T, 'Tu cuenta', 't_notifications', 'Recibir avisos de trabajos nuevos', 'Para enterarte en el momento.', [
                'En «Mi perfil», activá los avisos en este celular.',
                'Si tu empresa lo tiene, también podés conectar Telegram en «Avisos por Telegram».',
            ], ['En iPhone, agregá Ascento a la pantalla de inicio desde Safari para recibir avisos.']),
            $g($T, 'Tu cuenta', 't_profile', 'Tu perfil y tu foto', 'Tus datos y la foto que ve tu empresa y sus clientes.', [
                'En «Mi perfil», tocá «Subir foto» (o «Cambiar foto»). JPG, PNG o WebP de hasta 2 MB.',
                'Ahí también cambiás tu contraseña.',
            ]),
            $g($T, self::TROUBLESHOOTING, 't_fix_building', 'No veo un edificio', 'Las asignaciones las hace la oficina.', [
                'Pedile a la oficina que te asigne el edificio para ese trabajo (mantenimiento o inspección). Mientras tanto, podés buscarlo en «Todos los edificios».',
            ]),
            $g($T, self::TROUBLESHOOTING, 't_fix_sign', 'No me deja generar el remito', 'Falta tu firma u otro dato obligatorio.', [
                'Tocá el recuadro de firma y firmá. El mensaje en rojo te dice qué falta.',
            ]),
            $g($T, self::TROUBLESHOOTING, 't_fix_push', 'No me llegan los avisos', 'Los avisos se activan en cada celular.', [
                'En «Mi perfil», activalos de nuevo y aceptá el permiso. En iPhone, desde la app agregada a la pantalla de inicio.',
            ]),

            /* ============================== PORTAL ============================= */
            $g($C, 'Tu portal', 'p_home', 'Qué ves en el portal', 'La información que tu empresa de mantenimiento comparte de tus edificios.', [
                'En «Inicio» ves tus edificios con su último mantenimiento e inspección, quiénes los atienden y lo último compartido.',
                'Solo ves los edificios que la empresa te habilitó.',
            ]),
            $g($C, 'Tu portal', 'p_documents', 'Documentos, remitos y presupuestos', 'Todo lo que te compartieron, en un lugar.', [
                'En «Documentos» filtrá por tipo (remitos, reportes, presupuestos, documentación técnica) y por edificio.',
                'Abrí cada uno para verlo o descargar el PDF.',
            ]),
            $g($C, 'Tu portal', 'p_visits', 'Mantenimientos e inspecciones', 'Las visitas de cada mes.', [
                'En «Mantenimientos» e «Inspecciones» ves las visitas registradas de tus edificios.',
            ]),
            $g($C, 'Tu portal', 'p_notifications', 'Avisos', 'Enterate cuando te compartan algo.', [
                'En «Inicio», tocá «Activar» en «Avisos en este dispositivo».',
                'Todos los avisos quedan en «Avisos».',
            ], ['En iPhone los avisos funcionan solo desde Safari.']),
            $g($C, 'Tu portal', 'p_companies', 'Si trabajás con más de una empresa', 'Una cuenta para todas.', [
                'Arriba, elegí la empresa cuya información querés ver.',
            ]),
            $g($C, self::TROUBLESHOOTING, 'p_fix_document', 'No veo un documento o un edificio', 'Lo decide tu empresa de mantenimiento.', [
                'Pedile a la empresa que lo comparta o que te habilite el edificio.',
            ]),
        ];
    }

    /** Guías que esta persona puede usar, en orden. @return list<array> */
    public static function for(?User $user, string $audience): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(self::all(), fn (array $guide) => $guide['audience'] === $audience && self::allows($user, $guide)));
    }

    public static function find(?User $user, string $audience, string $key): ?array
    {
        return collect(self::for($user, $audience))->firstWhere('key', $key);
    }

    private static function allows(User $user, array $guide): bool
    {
        return match ($guide['audience']) {
            self::PANEL => Access::panelUser($user)
                && (! $guide['owner'] || $user->isSuperAdmin() || Access::isOwner($user))
                && ($guide['area'] === null || Access::can($user, $guide['area']))
                && ($guide['feature'] === null || PlanGuard::currentAllows($guide['feature'])),
            self::TECHNICIAN => $user->role === 'technician' && $user->company_id !== null
                && ($guide['feature'] === null || PlanGuard::companyAllows((int) $user->company_id, $guide['feature'])),
            self::PORTAL => $user->isClientUser() && ($companyId = PortalAccess::currentCompanyId($user)) !== null
                && ($guide['feature'] === null || PlanGuard::companyAllows($companyId, $guide['feature'])),
            default => false,
        };
    }

    /** Agrupadas por módulo (los problemas frecuentes, al final). @return array<string, list<array>> */
    public static function grouped(array $guides): array
    {
        $grouped = [];
        foreach ($guides as $guide) {
            $grouped[$guide['module']][] = $guide;
        }
        if (isset($grouped[self::TROUBLESHOOTING])) {
            $last = $grouped[self::TROUBLESHOOTING];
            unset($grouped[self::TROUBLESHOOTING]);
            $grouped[self::TROUBLESHOOTING] = $last;
        }

        return $grouped;
    }
}
