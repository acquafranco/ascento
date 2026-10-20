<?php

namespace App\Support\Help\Content;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature as Feature;
use App\Filament\Clusters\ServicesCluster;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\CompanyExports;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\IndicatorsPage;
use App\Filament\Pages\ParachuteTestsPage;
use App\Filament\Pages\PlanFeatures;
use App\Filament\Pages\PublicQrPage;
use App\Filament\Pages\Subscription;
use App\Filament\Pages\SystemBackups;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Claims\ClientClaimResource;
use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Elevators\ElevatorResource;
use App\Filament\Resources\Emergency\EmergencyRequestResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\RepairComponents\RepairComponentResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\StaffUsers\StaffUserResource;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\SubscriptionPlan;
use App\Support\Help\HelpGuides as H;

/**
 * Artículos del Centro de ayuda del panel de la empresa (dueño,
 * administrativos y SuperAdmin). Cada texto describe lo que la pantalla hace
 * hoy, con las etiquetas tal como se ven. Para cambiar una instrucción, se
 * edita acá: la búsqueda, las categorías y los enlaces de cada pantalla la
 * toman de esta misma definición.
 *
 * Campos: ver HelpGuides::make().
 */
final class PanelGuides
{
    /** @return list<array> */
    public static function all(): array
    {
        $a = fn (string $category, string $key, string $title, string $summary, array $steps, array $o = []) => H::make(H::PANEL, $category, $key, $title, $summary, $steps, $o);

        return [
            /* ========================= PRIMEROS PASOS ========================= */
            $a('primeros-pasos', 'start', 'Poner en marcha tu empresa', 'El orden recomendado para empezar a usar Ascento, de cero a la primera visita.', [
                'En «Mi empresa» (arriba del menú), completá los datos y subí el logo: salen en los remitos y presupuestos.',
                'En «Administración → Técnicos», creá a cada técnico con su correo y una contraseña. Con eso entran desde el celular.',
                'En «Gestión → Clientes», cargá cada consorcio, administración o empresa.',
                'En «Gestión → Edificios», cargá cada edificio con su cliente, su dirección y cada ascensor y montacargas con su tracción.',
                'En la lista de edificios, usá «Asignar empleado» para elegir quién hace el mantenimiento y quién la inspección.',
                'Si un edificio no se visita una vez por mes, cambiá su «Frecuencia de visitas».',
            ], [
                'after' => 'Desde ese momento cada técnico ve en el celular las visitas del mes de sus edificios, y la «Agenda» muestra qué falta.',
                'flow' => ['Mi empresa', 'Técnicos', 'Clientes', 'Edificios', 'Asignar empleado'],
                'related' => ['concepts', 'building_create', 'assignments', 'nav'],
                'keywords' => ['empezar', 'comenzar', 'configuracion inicial', 'primer uso', 'arrancar', 'alta'],
                'owner' => true,
            ]),

            $a('primeros-pasos', 'setup', 'La configuración inicial guiada', 'Para empresas nuevas: de los datos de tu empresa a tu primera orden de trabajo, paso a paso.', [
                'Al registrarte se abre sola, en una pantalla propia. Tocá «Empezar».',
                'Completás cada paso ahí mismo, con pocos datos por pantalla: tu empresa (datos, logo y color), tu primer técnico, tu primer cliente, un edificio con sus ascensores y tu primera orden, asignada a ese técnico.',
                'Cada paso se guarda al tocar «Continuar». Con «Volver» revisás el anterior sin perder nada.',
                'Al final ves el resumen y vas al inicio.',
            ], [
                'after' => 'Después sigue la ayuda de inicio con los próximos pasos (agenda, servicios, portal, QR y guardias, según tu plan).',
                'notes' => ['Con «Seguir después» salís cuando quieras: en el inicio queda el aviso «Terminá de configurar tu empresa» para seguir donde la dejaste. Si la omitiste, la retomás desde «Ayuda» o el botón «?».', 'Si ya cargaste un técnico, un cliente o un edificio desde el menú, el paso queda hecho y no se repite.', 'Se guarda por empresa y solo la usa el dueño de la cuenta.'],
                'related' => ['start', 'nav'],
                'keywords' => ['configuracion inicial', 'onboarding', 'primeros pasos', 'empezar', 'guia', 'tutorial', 'asistente'],
                'owner' => true,
            ]),

            $a('primeros-pasos', 'nav', 'Cómo moverte por el menú', 'El menú agrupa las secciones en categorías que se despliegan, así ves primero lo importante.', [
                'Arriba del menú están los accesos de todos los días: «Mi empresa» (solo el dueño), «Inicio», «Centro de atención», «Indicadores» y «Ayuda», según tu plan y tus permisos.',
                'Debajo están las categorías: «Gestión» (clientes, edificios y equipos), «Operaciones» (visitas, órdenes, remitos, guardias), «Comercial» (presupuestos, servicios y cobranzas) y «Administración» (tu equipo, tu plan y la exportación).',
                'Tocá el nombre de una categoría para desplegarla o cerrarla. La flecha indica si está abierta.',
                'Si una categoría cerrada tiene algo pendiente adentro, muestra un número al lado del nombre.',
                'En el celular, el menú se abre con el botón de las tres rayas, arriba a la izquierda.',
            ], [
                'after' => 'Ascento recuerda en este navegador las categorías que dejaste abiertas. La categoría de la pantalla en la que estás siempre se ve abierta, y también «Operaciones» si hay un pedido de ayuda abierto.',
                'problems' => [
                    ['No encuentro una sección', 'Abrí las categorías o buscala en el Centro de ayuda. Si no aparece, puede que no esté en tu plan o que no tengas permiso para esa área: consultalo con el dueño de la cuenta.'],
                ],
                'related' => ['help_button', 'fix_menu'],
                'keywords' => ['menu', 'barra lateral', 'categorias', 'secciones', 'desplegar', 'plegar', 'navegar', 'donde esta', 'encontrar'],
                'image' => ['src' => 'images/help/menu-categorias.webp', 'alt' => 'Menú de Ascento con las categorías Gestión y Comercial cerradas y Operaciones abierta', 'caption' => 'El menú con sus categorías. Captura con datos de ejemplo.'],
            ]),

            $a('primeros-pasos', 'help_button', 'Cómo usar la ayuda dentro de Ascento', 'Dónde encontrar ayuda sin salir de lo que estás haciendo.', [
                'Tocá el botón «?» de arriba a la derecha.',
                'En «En esta pantalla» ves para qué sirve la sección en la que estás y las guías que le corresponden.',
                'Con «Recorrer el menú» elegís una categoría y la guía te muestra cada opción en el menú, una por una. Podés avanzar, volver, saltar o cerrar cuando quieras.',
                'Con «Ver la guía desde el principio» volvés a ver la introducción.',
                'Con «Abrir el Centro de ayuda» buscás cualquier tema con tus palabras.',
            ], [
                'before' => ['El botón «?» lo tienen el dueño y los administrativos. Si no lo ves, usá «Ayuda» en el menú.'],
                'after' => 'Ver la introducción o recorrer el menú desde el botón no hace que vuelvan a abrirse solos la próxima vez que entres.',
                'related' => ['nav'],
                'keywords' => ['ayuda', 'boton', 'signo de pregunta', 'guia', 'tutorial', 'recorrido', 'introduccion'],
            ]),

            $a('primeros-pasos', 'concepts', 'Cómo se relacionan clientes, edificios y ascensores', 'La estructura de la cartera en Ascento.', [
                'Un cliente es quien te contrata: un consorcio, una administración, una empresa o un particular.',
                'Cada edificio pertenece a un cliente. Un cliente puede tener varios edificios.',
                'Cada edificio tiene sus equipos: ascensores y montacargas, cada uno con su tracción y su legajo técnico.',
                'Los técnicos se asignan por edificio: hasta dos para el mantenimiento y uno para la inspección.',
            ], [
                'flow' => ['Cliente', 'Edificios', 'Ascensores y montacargas'],
                'after' => 'Todo lo demás se cuelga de esta estructura: las visitas, las órdenes, los reportes, los remitos, los servicios contratados y el portal del cliente.',
                'related' => ['client_create', 'building_create', 'elevators', 'assignments'],
                'keywords' => ['estructura', 'relacion', 'consorcio', 'administracion', 'equipo', 'montacargas'],
            ]),

            $a('primeros-pasos', 'work_cycle', 'El circuito de un mantenimiento, de la asignación al remito', 'Qué pasa con cada visita, paso a paso, y dónde se ve.', [
                'Asignás técnicos al edificio y, si hace falta, su «Frecuencia de visitas».',
                'Cada mes, la «Agenda» calcula qué visitas tocan según esas asignaciones y la frecuencia.',
                'El técnico ve el edificio en su celular, hace la visita y la cierra con el remito firmado.',
                'Recién con el remito firmado como realizado la visita cuenta como hecha. La ves en «Mantenimientos» o «Inspecciones» y el remito en «Remitos».',
            ], [
                'flow' => ['Asignación', 'Agenda del mes', 'Visita del técnico', 'Remito firmado'],
                'after' => 'Si el técnico firma el remito como «no realizado», la visita no cuenta como hecha y la agenda la sigue mostrando para que coordines otra.',
                'related' => ['assignments', 'agenda', 'visits', 'delivery_notes'],
                'keywords' => ['flujo', 'circuito', 'proceso', 'como funciona', 'visita', 'remito'],
                'area' => Area::Visits,
            ]),

            /* ============================ EMPRESA ============================ */
            $a('empresa', 'company_data', 'Cómo completar los datos y el logo de tu empresa', 'Los datos que aparecen en remitos, presupuestos, el portal y el QR público.', [
                'Abrí «Mi empresa» (arriba del menú).',
                'En «Empresa», completá el nombre, el correo, el teléfono y el domicilio.',
                'Si querés, completá «Datos fiscales (opcional)» (razón social, CUIT, condición frente al IVA) y «Datos bancarios (opcional)».',
                'En «Apariencia», subí el «Logo» y elegí el «Color principal».',
                'Tocá «Guardar cambios».',
            ], [
                'before' => ['Solo el dueño de la cuenta entra a «Mi empresa».'],
                'after' => 'El logo y los datos se usan en los documentos nuevos, en el portal de tus clientes y en la página del QR público.',
                'related' => ['work_photos', 'emergency_setup', 'notifications'],
                'keywords' => ['logo', 'datos', 'cuit', 'razon social', 'color', 'empresa', 'configuracion', 'membrete'],
                'owner' => true,
                'open' => CompanySettings::class,
            ]),

            $a('empresa', 'work_photos', 'Fotos de antes y después', 'Fotos de cada trabajo y reportes de inspección con fotos.', [
                'En «Mi empresa», en «Fotos de los trabajos», habilitá las fotos de mantenimientos, inspecciones u órdenes.',
                'Los técnicos las sacan en el celular durante la visita, por zona del equipo (pozo, sala de máquinas, techo de cabina, cabina y puertas).',
                'Las ves al abrir cada mantenimiento, inspección u orden. Las fotos no van en el remito.',
            ], [
                'related' => ['visits', 'tech_app'],
                'keywords' => ['fotos', 'imagenes', 'antes y despues', 'evidencia'],
                'feature' => Feature::WorkPhotos,
                'area' => Area::Visits,
            ]),

            $a('empresa', 'notifications', 'Avisos en la computadora y el celular', 'Enterate de lo importante en el momento.', [
                'La campanita, arriba a la derecha, muestra los avisos sin recargar la página.',
                'En el inicio, «Activar avisos» los habilita en este dispositivo.',
            ], [
                'notes' => ['En iPhone, los avisos funcionan si agregás Ascento a la pantalla de inicio desde Safari.'],
                'problems' => [['No me llegan los avisos al celular', 'Se activan en cada dispositivo: tocá «Activar avisos» en ese celular y aceptá el permiso del navegador.']],
                'related' => ['fix_push'],
                'keywords' => ['notificaciones', 'campanita', 'push', 'alertas', 'avisos'],
            ]),

            /* ====================== CLIENTES Y EDIFICIOS ====================== */
            $a('clientes-edificios', 'client_create', 'Cómo registrar un cliente', 'Dar de alta un consorcio, una administración, una empresa o un particular.', [
                'Abrí «Gestión → Clientes» y tocá el botón para crear uno nuevo.',
                'Completá el «Nombre» y el «Tipo de cliente».',
                'Agregá la «Persona de contacto», el «Teléfono» y el «Correo electrónico». Las «Notas» son internas.',
                'Guardá.',
            ], [
                'after' => 'Ya podés cargar sus edificios. Desde la ficha del cliente ves sus edificios, sus servicios contratados, su cuenta corriente y, si tu plan lo incluye, el acceso al portal.',
                'problems' => [['No puedo crear más clientes', 'Llegaste al límite de tu plan. Lo ves en «Mi suscripción».']],
                'related' => ['building_create', 'concepts', 'portal'],
                'keywords' => ['alta cliente', 'nuevo cliente', 'consorcio', 'administracion', 'cargar cliente'],
                'area' => Area::Clients,
                'open' => ClientResource::class,
            ]),

            $a('clientes-edificios', 'building_create', 'Cómo registrar un edificio', 'Cargar un edificio con su dirección y sus equipos para que reciba visitas.', [
                'Abrí «Gestión → Edificios» y creá uno nuevo.',
                'Elegí el «Cliente» al que pertenece.',
                'En «Buscar dirección», escribí calle y altura y elegí una opción: se completan los datos y el edificio queda ubicado en el mapa.',
                'Agregá el «Contacto» y el «Teléfono» del edificio. Si querés, una «Zona o sector» y un «Color en el mapa».',
                'En «Ascensores y montacargas», agregá cada equipo con su «Tipo de equipo» y su «Tracción».',
                'Guardá.',
            ], [
                'before' => ['Primero tiene que existir el cliente.'],
                'after' => 'El edificio aparece en la lista y en el mapa. Para que tenga visitas, asignale técnicos con «Asignar empleado».',
                'problems' => [
                    ['El edificio no aparece en el mapa', 'Revisá la dirección: si no se pudo ubicar, aparece en «Edificios sin ubicar» del mapa para corregirla o marcarla a mano.'],
                    ['La agenda dice «Sin equipos»', 'Un edificio sin ascensores ni montacargas activos no recibe visitas. Agregá sus equipos.'],
                ],
                'related' => ['assignments', 'elevators', 'map'],
                'keywords' => ['alta edificio', 'nuevo edificio', 'cargar edificio', 'direccion', 'equipos'],
                'area' => Area::Buildings,
                'open' => BuildingResource::class,
            ]),

            $a('clientes-edificios', 'assignments', 'Cómo asignar técnicos a un edificio', 'Quién hace el mantenimiento y la inspección de cada edificio, y cada cuánto.', [
                'En «Gestión → Edificios», tocá «Asignar empleado» en la fila del edificio.',
                'Elegí el empleado y el trabajo: mantenimiento (hasta dos técnicos) o inspección (uno por edificio).',
                'Para varios edificios a la vez, usá «Asignar técnicos» arriba de la lista, o tildalos y elegilo en el menú de seleccionados. Elegís si agregar, reemplazar o conservar a los actuales y ves un resumen antes de confirmar.',
                'Con «Frecuencia de visitas» definís si va mensual, quincenal o cada varios meses.',
            ], [
                'after' => 'El técnico ve el edificio en su celular para ese tipo de trabajo, y la agenda del mes lo cuenta.',
                'notes' => ['La frecuencia de visitas no cambia la frecuencia de cobro del servicio contratado.'],
                'problems' => [
                    ['«Este edificio ya tiene el máximo de técnicos de mantenimiento»', 'Solo puede haber dos. Quitá uno con «Quitar asignación» o usá «Reemplazar» en la asignación masiva.'],
                    ['«Este edificio ya tiene un inspector asignado»', 'Solo puede haber un inspector por edificio.'],
                ],
                'related' => ['building_create', 'agenda', 'fix_technician_building'],
                'keywords' => ['asignar', 'tecnico', 'empleado', 'inspector', 'frecuencia', 'quincenal', 'mensual', 'reasignar'],
                'area' => Area::Buildings,
                'open' => BuildingResource::class,
            ]),

            $a('clientes-edificios', 'elevators', 'Ascensores y legajo técnico', 'La ficha, la documentación y el historial de cada equipo.', [
                'Entrá a «Gestión → Ascensores» y abrí el equipo.',
                'Con «Editar ficha» completá de a poco: fabricante, modelo, número de serie, año, capacidad, paradas, y la marca y el modelo del control.',
                'Subí la documentación (planos, manuales, certificados). A los certificados ponéles vencimiento para que avise.',
                'Para que el cliente vea un documento en su portal, usá «Compartir con el cliente».',
                'Abajo está el historial del equipo: reportes, órdenes, presupuestos y mantenimientos, sin cargar nada aparte.',
            ], [
                'related' => ['elevator_history', 'portal_share', 'building_create'],
                'keywords' => ['legajo', 'ficha tecnica', 'documentos', 'certificados', 'equipo', 'montacargas', 'manual', 'plano'],
                'feature' => Feature::ElevatorFile,
                'area' => Area::Buildings,
                'open' => ElevatorResource::class,
            ]),

            $a('clientes-edificios', 'deactivate', 'Cómo dar de baja un cliente o un edificio', 'Dejar de atender a un cliente o un edificio sin perder su historial.', [
                'Abrí el cliente o el edificio y tocá «Desactivar», o tildalos en la lista y usá «Desactivar seleccionados».',
                'Para verlos de nuevo, usá el filtro «Desactivados» de la lista.',
                'Si vuelve a ser tu cliente, usá «Reactivar».',
            ], [
                'after' => 'Deja de aparecer en las listas y en la agenda, pero sus remitos, visitas e historial se conservan. Un registro desactivado libera cupo de tu plan.',
                'related' => ['client_create', 'building_create', 'fix_limit'],
                'keywords' => ['baja', 'eliminar', 'borrar', 'desactivar', 'reactivar', 'dar de baja'],
                'area' => Area::Buildings,
            ]),

            /* ===================== MANTENIMIENTOS E INSPECCIONES ===================== */
            $a('mantenimientos', 'agenda', 'Agenda del mes', 'Qué visitas tocan, quién las tiene y qué falta.', [
                'Entrá a «Operaciones → Agenda» y elegí el mes.',
                'Arriba ves el progreso: visitas previstas, realizadas y pendientes.',
                'Primero aparecen las vencidas y las que no tienen técnico asignado.',
            ], [
                'after' => 'La agenda se arma sola: no hay que cargar nada. Quincenal son 2 visitas en el mes; cada N meses es 1 visita en el período (los otros meses figuran como «Hecho en el período»).',
                'problems' => [['Una visita figura «Sin técnico»', 'El edificio tiene un servicio de mantenimiento y nadie asignado. Usá «Asignar empleado» en Edificios.']],
                'related' => ['work_cycle', 'before_ascento', 'assignments', 'fix_visit_pending'],
                'keywords' => ['calendario', 'mes', 'pendientes', 'vencidas', 'programacion', 'planificacion'],
                'feature' => Feature::Agenda,
                'area' => Area::Visits,
                'open' => Agenda::class,
            ]),

            $a('mantenimientos', 'before_ascento', 'Cómo consultar un mes anterior a empezar con Ascento', 'Qué muestra la agenda en los meses en que tu empresa todavía no usaba Ascento.', [
                'En «Operaciones → Agenda», elegí el mes que querés consultar.',
                'Los edificios sin registros de ese mes figuran «Antes de usar Ascento»: no es un vencimiento ni algo pendiente.',
                'Si ese mes tiene visitas registradas, se cuentan y se ven como cualquier otra.',
            ], [
                'after' => 'Los indicadores y la agenda no te marcan como incumplidos los meses anteriores a tu alta.',
                'notes' => ['Ascento no importa automáticamente los mantenimientos hechos antes de usarlo: lo que no se registró en Ascento no aparece.'],
                'related' => ['agenda', 'visits'],
                'keywords' => ['mes anterior', 'historico', 'antes de usar', 'meses viejos', 'pasado', 'incorporacion'],
                'feature' => Feature::Agenda,
                'area' => Area::Visits,
            ]),

            $a('mantenimientos', 'visits', 'Mantenimientos e inspecciones', 'Cómo se registra cada visita y dónde la ves.', [
                'El técnico hace la visita desde el celular y la cierra con el remito firmado.',
                'La ves en «Operaciones → Mantenimientos» o «Inspecciones», con su remito.',
                'Al abrir una inspección, «Ver reporte» muestra las observaciones del inspector y, si las habilitaste, sus fotos.',
            ], [
                'after' => 'Si el edificio es quincenal hay dos visitas por mes, cada una con su remito.',
                'notes' => ['Un remito marcado como «no realizado» no cuenta como visita hecha: la agenda la sigue mostrando pendiente.'],
                'related' => ['work_cycle', 'agenda', 'delivery_notes'],
                'keywords' => ['mantenimiento', 'inspeccion', 'visita', 'registrar mantenimiento', 'mantenimiento realizado'],
                'area' => Area::Visits,
                'open' => MaintenanceResource::class,
            ]),

            $a('mantenimientos', 'parachute', 'Pruebas de paracaídas', 'Registro de pruebas y aviso de las próximas o vencidas.', [
                'En «Operaciones → Pruebas de paracaídas», tocá «Configurar» en cada ascensor para elegir la «Periodicidad (meses)», los días de aviso, el «Inspector asignado» y el «Responsable técnico».',
                'Si todo un cliente usa la misma periodicidad, cargala en «Periodicidad por cliente».',
                'Cuando se hace una prueba, usá «Registrar prueba» con la fecha, el resultado y los documentos.',
                'Con «Historial» ves las pruebas anteriores de ese ascensor.',
            ], [
                'after' => 'Ascento avisa todos los días las pruebas próximas y vencidas.',
                'notes' => ['Un aviso nunca cuenta como prueba hecha: solo un registro.'],
                'related' => ['public_qr', 'elevators'],
                'keywords' => ['paracaidas', 'prueba', 'ensayo', 'vencimiento', 'periodicidad'],
                'feature' => Feature::ParachuteTests,
                'area' => Area::Inspections,
                'open' => ParachuteTestsPage::class,
            ]),

            /* ======================= CÓMO TRABAJA EL TÉCNICO ======================= */
            $a('tecnico', 'tech_app', 'Qué ve y qué hace un técnico desde su celular', 'La app del técnico, vista desde la oficina.', [
                'El técnico entra con su correo y su contraseña desde el navegador del celular. No usa el panel.',
                '«Visitas»: los mantenimientos e inspecciones del mes de los edificios que tiene asignados.',
                '«Trabajos»: las órdenes que le asignaste. Las toma con «Tomar trabajo» y las cierra con «Hacer remito y finalizar».',
                '«Reportes»: las fallas que detecta, con foto y prioridad.',
                '«Guardias»: cuándo le toca cubrir fuera de horario (si tu plan incluye guardias).',
            ], [
                'after' => 'Cada remito que firma queda en «Remitos» y actualiza la agenda. Los reportes te llegan como aviso.',
                'notes' => ['Un técnico solo ve los edificios asignados a él para cada tipo de trabajo, y nunca entra al panel de la empresa.'],
                'related' => ['technicians', 'work_cycle', 'work_orders'],
                'keywords' => ['tecnico', 'celular', 'app', 'movil', 'campo', 'operario'],
            ]),

            /* ====================== ÓRDENES DE TRABAJO Y REPORTES ====================== */
            $a('ordenes', 'work_orders', 'Cómo crear una orden de trabajo', 'Para reclamos y trabajos puntuales fuera del mantenimiento mensual.', [
                'En «Operaciones → Órdenes de trabajo», creá una nueva.',
                'Elegí el «Edificio» y, si corresponde, la «Unidad» (el equipo).',
                'Elegí los «Técnicos asignados», el «Tipo», la «Prioridad» y escribí el «Detalle».',
                'Para reclamos, elegí el «Componente afectado (opcional)»: sirve para detectar fallas que se repiten.',
                'Si surgió durante una guardia, elegila en «Guardia (si se originó durante una)».',
                'Guardá.',
            ], [
                'after' => 'A los técnicos les llega el aviso. La orden pasa de «Pendiente» a «En progreso» cuando la toman, y a «Completado» cuando firman el remito.',
                'related' => ['work_order_status', 'materials', 'on_call_work', 'delivery_notes'],
                'keywords' => ['orden', 'reclamo', 'ot', 'trabajo', 'pedido', 'nueva orden', 'asignar trabajo'],
                'area' => Area::WorkOrders,
                'open' => WorkOrderResource::class,
            ]),

            $a('ordenes', 'claims', 'Cómo gestionar los reclamos de tus clientes', 'Lo que tus clientes reportan desde el portal: un problema con un ascensor, con su edificio y su categoría.', [
                'Cuando un cliente presenta un reclamo, te llega un aviso y aparece en «Operaciones → Reclamos de clientes» como «Nuevo».',
                'Abrilo: ves el edificio, el ascensor (si lo indicó), la categoría, la descripción, las fotos y quién lo presentó.',
                'Con «Cambiar estado» lo pasás a «En revisión», «En gestión», «Resuelto» o «Cerrado». El cliente ve el estado en su portal.',
                'Con «Responder al cliente» le escribís: lo ve en su portal y le llega un aviso. En «Más», «Nota interna» guarda algo que solo ve tu empresa.',
                'Si hace falta un trabajo, en «Más» usá «Crear orden de trabajo»: queda vinculada al reclamo y asignás los técnicos en la orden.',
            ], [
                'after' => 'Todo queda en el historial del reclamo, marcado como «Visible para el cliente» o «Interno».',
                'notes' => ['Un reclamo no es un canal de emergencias: el portal le indica al cliente usar la alarma del ascensor y el 911.', 'Crear la orden es siempre una acción tuya: un reclamo nunca genera órdenes solo, ni dos órdenes.'],
                'related' => ['work_orders', 'portal'],
                'keywords' => ['reclamo', 'queja', 'portal', 'cliente', 'consorcio', 'problema', 'pedido'],
                'feature' => Feature::ClientPortal,
                'area' => Area::WorkOrders,
                'open' => ClientClaimResource::class,
            ]),

            $a('ordenes', 'work_order_status', 'Cómo seguir el estado de una orden', 'Qué significa cada estado y cómo se cierra una orden.', [
                '«Pendiente»: está creada y todavía nadie la tomó.',
                '«En progreso»: un técnico tocó «Tomar trabajo».',
                '«Completado»: el técnico la cerró con «Hacer remito y finalizar» (o la completaste desde el panel).',
                '«No realizado»: el trabajo no se pudo hacer.',
            ], [
                'after' => 'Una orden tiene un solo remito. Los materiales que se declaren se descuentan del stock al completarse.',
                'related' => ['work_orders', 'materials', 'delivery_notes'],
                'keywords' => ['estado', 'pendiente', 'en progreso', 'completado', 'no realizado', 'cerrar orden'],
                'area' => Area::WorkOrders,
            ]),

            $a('ordenes', 'reports', 'Reportes de fallas', 'Lo que detectan los técnicos en los equipos.', [
                'El técnico carga el reporte desde el celular: edificio, equipo, qué parte falla, prioridad y fotos.',
                'Te llega un aviso. En «Operaciones → Reportes», abrilo.',
                'Cambiá el estado a «En revisión» o «Resuelto» a medida que lo atendés.',
                'Los reportes de inspección se ven eligiendo el tipo «Inspección» en el filtro.',
            ], [
                'related' => ['report_videos', 'work_orders', 'portal_share'],
                'keywords' => ['falla', 'reporte', 'problema', 'averia', 'desperfecto'],
                'area' => Area::Inspections,
                'open' => ReportResource::class,
            ]),

            $a('ordenes', 'report_videos', 'Video en los reportes', 'Un video corto para mostrar la falla.', [
                'El técnico puede agregar un video al crear el reporte.',
                'En el reporte, «Ver video» lo reproduce. Cada reporte admite un solo video.',
            ], [
                'related' => ['reports'],
                'keywords' => ['video', 'filmacion', 'grabar'],
                'feature' => Feature::ReportVideos,
                'area' => Area::Inspections,
            ]),

            $a('ordenes', 'materials', 'Cómo se descuentan los materiales de una orden', 'La relación entre las órdenes de trabajo y el stock.', [
                'Al cerrar la orden, el técnico declara los materiales que usó con «+ Agregar material».',
                'Desde el panel también podés sumarlos en la orden, en «Agregar material».',
                'Cuando la orden queda «Completado», cada material se descuenta del stock una sola vez.',
            ], [
                'notes' => ['Si no alcanzaba el stock, el material queda en negativo y en rojo para que cargues la entrada o un ajuste: no se frena el cierre del trabajo.'],
                'related' => ['stock', 'work_orders'],
                'keywords' => ['materiales', 'repuestos', 'descontar', 'stock', 'consumo'],
                'area' => Area::WorkOrders,
            ]),

            /* ============================ REMITOS ============================ */
            $a('remitos', 'delivery_notes', 'Cómo se genera y se firma un remito digital', 'El cierre de cada trabajo, firmado en el celular.', [
                'El técnico completa el remito al terminar: equipos, si el trabajo se realizó correctamente y observaciones.',
                'Firma en el recuadro. Si está el encargado, también firma y escribe su aclaración.',
                'Toca «Generar remito». Desde su celular puede compartirlo por WhatsApp, por correo o como PDF.',
                'En «Operaciones → Remitos» ves todos, con las firmas, y descargás el PDF de cada uno.',
            ], [
                'after' => 'El remito actualiza la agenda (si es de una visita) o completa la orden. Para que el cliente lo vea en su portal, usá «Compartir con el cliente».',
                'notes' => ['Los remitos no se borran: son el historial del trabajo.'],
                'related' => ['visits', 'portal_share', 'elevator_history'],
                'keywords' => ['remito', 'firma', 'comprobante', 'constancia', 'pdf', 'firmar'],
                'area' => Area::Documents,
                'open' => DeliveryNoteResource::class,
            ]),

            $a('remitos', 'elevator_history', 'Cómo consultar el historial de un ascensor', 'Todo lo que pasó con un equipo, en una sola pantalla.', [
                'Abrí «Gestión → Ascensores» y entrá al equipo.',
                'Bajá hasta el historial: reportes, órdenes, presupuestos y mantenimientos del edificio, del más nuevo al más viejo.',
                'Para ver los remitos de un edificio o un mes puntual, usá los filtros de «Operaciones → Remitos».',
            ], [
                'after' => 'No hay que cargar nada: el historial sale de lo que ya registran.',
                'related' => ['elevators', 'delivery_notes'],
                'keywords' => ['historial', 'historia', 'antecedentes', 'equipo', 'ascensor'],
                'feature' => Feature::ElevatorFile,
                'area' => Area::Buildings,
            ]),

            /* ================ PRESUPUESTOS, REPARACIONES Y REPUESTOS ================ */
            $a('presupuestos', 'quotes', 'Presupuestos', 'Presupuestos con tu logo, numerados y con enlace para el cliente.', [
                'En «Comercial → Presupuestos», creá uno con su «Título», cliente, edificio y los ítems: el total se calcula solo.',
                'Con «Agregar un servicio del catálogo» sumás un servicio ofrecido con su precio de referencia.',
                'Con «Enviar al cliente» lo mandás por correo. En «Compartir» podés copiar el enlace o enviarlo por WhatsApp.',
                'Actualizá el estado con «Cambiar estado». Si pasa la validez, figura vencido.',
            ], [
                'notes' => ['El enlace que recibió el cliente vence solo. Con «Compartir» → «Anular enlaces enviados» los dejás sin efecto antes.', 'Las «Observaciones internas» no las ve el cliente.'],
                'related' => ['quote_charge', 'services_overview', 'portal_share'],
                'keywords' => ['presupuesto', 'cotizacion', 'precio', 'enviar presupuesto', 'whatsapp'],
                'feature' => Feature::Quotes,
                'area' => Area::Quotes,
                'open' => QuoteResource::class,
            ]),

            $a('presupuestos', 'quote_charge', 'Cómo cobrar un presupuesto aprobado', 'Pasar un presupuesto aprobado a la cuenta corriente del cliente.', [
                'Abrí el presupuesto aprobado y tocá «Generar cobro».',
                'Revisá el «Concepto», el «Importe» y el «Vencimiento» y confirmá.',
                'Con «Ver cobro» vas al cobro en «Cobranzas».',
            ], [
                'after' => 'El cobro queda en «Cobranzas» y en la cuenta corriente del cliente. Nunca se genera solo al aprobar.',
                'notes' => ['Solo puede haber un cobro vigente por presupuesto. Si lo anulás, podés volver a generarlo.'],
                'related' => ['receivables', 'quotes'],
                'keywords' => ['cobrar presupuesto', 'facturar', 'generar cobro', 'aprobado'],
                'feature' => Feature::Quotes,
                'area' => Area::Quotes,
            ]),

            $a('presupuestos', 'repairs', 'Componentes enviados a reparación', 'Qué está en el taller y cuándo vuelve.', [
                'En «Operaciones → Reparaciones», registrá el componente: edificio, ascensor, componente, falla y proveedor.',
                'Con «Cambiar estado» lo vas avanzando: enviado, en diagnóstico, en reparación, reparado y recibido.',
                'Cargá el presupuesto y el costo final, y adjuntá documentos o fotos.',
            ], [
                'notes' => ['No se puede marcar como reparado un componente que solo fue enviado.', 'No se borran: se cancelan. Cada cambio queda en el historial.'],
                'related' => ['stock'],
                'keywords' => ['reparacion', 'taller', 'proveedor', 'componente', 'placa', 'motor'],
                'feature' => Feature::Repairs,
                'area' => Area::Repairs,
                'open' => RepairComponentResource::class,
            ]),

            $a('presupuestos', 'stock', 'Stock de repuestos', 'Lo que tenés, lo que entra y lo que se usa.', [
                'En «Operaciones → Stock», tocá «Nuevo material» con su «Unidad de medida», «Stock inicial» y «Stock mínimo».',
                'Cuando comprás, usá «Movimiento» → «Entrada».',
                'Las salidas por órdenes se descuentan solas. Para un retiro manual, usá «Movimiento» → «Salida».',
                'En «Historial de movimientos» ves cada entrada, salida y ajuste, con quién lo hizo.',
            ], [
                'notes' => ['Con el stock mínimo o menos, el material aparece como «Stock bajo» y en el centro de atención.'],
                'related' => ['stock_adjust', 'materials'],
                'keywords' => ['stock', 'inventario', 'repuestos', 'materiales', 'deposito', 'entrada', 'compra'],
                'area' => Area::Stock,
                'open' => StockItemResource::class,
            ]),

            $a('presupuestos', 'stock_adjust', 'Cómo corregir el stock con un ajuste', 'Cuando el stock del sistema no coincide con lo que contaste.', [
                'En «Operaciones → Stock», en el material, tocá «Movimiento» → «Ajuste».',
                'Escribí la «Cantidad real contada» y el «Motivo del ajuste».',
            ], [
                'after' => 'El stock queda en la cantidad contada y el ajuste queda en el historial. Los movimientos anteriores no se modifican.',
                'related' => ['stock'],
                'keywords' => ['ajuste', 'corregir stock', 'conteo', 'inventario', 'diferencia'],
                'area' => Area::Stock,
            ]),

            /* ================== SERVICIOS CONTRATADOS Y COBRANZAS ================== */
            $a('servicios', 'services_overview', 'Servicios ofrecidos y servicios contratados: la diferencia', 'Cómo se organiza «Comercial → Servicios».', [
                '«Servicios ofrecidos» es tu catálogo: lo que ofrecés y su precio de referencia. No genera cobros ni visitas.',
                '«Servicios contratados» es lo que tiene cada cliente, con su alcance, su importe y su frecuencia de cobro. Es lo que genera los cobros.',
                'Al contratar desde el catálogo, el nombre, el precio y la modalidad se copian: cambiar el catálogo después no modifica lo ya contratado.',
            ], [
                'flow' => ['Servicio ofrecido', 'Servicio contratado', 'Cobros en Cobranzas'],
                'notes' => ['Solo los servicios de mantenimiento e inspección cubren las visitas periódicas. Contratar no crea visitas: las visitas salen de las asignaciones de técnicos.'],
                'related' => ['service_contract', 'receivables', 'service_lifecycle'],
                'keywords' => ['servicios', 'catalogo', 'contrato', 'abono', 'ofrecidos', 'contratados'],
                'area' => Area::Receivables,
                'open' => ServicesCluster::class,
            ]),

            $a('servicios', 'service_contract', 'Cómo registrar un servicio contratado', 'El contrato de un cliente: qué incluye, cuánto cuesta y cada cuánto se cobra.', [
                'En «Comercial → Servicios», abrí «Servicios contratados» y tocá «Contratar servicio». También podés hacerlo desde la ficha del cliente o del edificio.',
                'Elegí un servicio del catálogo (se precargan sus datos) o cargalo sin catálogo.',
                'Elegí el alcance: todo el cliente, uno o varios edificios (se crea una contratación por edificio) o algunos equipos de un edificio.',
                'Revisá la modalidad y el precio: el resumen muestra cómo se calcula el importe.',
                'Elegí la «Frecuencia de cobro» (incluye «Pago único»), el día de vencimiento y la fecha de «Inicio».',
            ], [
                'after' => 'Cada período genera su cobro en «Cobranzas», sin duplicar. Un servicio con fecha de inicio vieja no inventa meses de deuda hacia atrás.',
                'problems' => [
                    ['Me pide «Confirmo el nuevo importe» al editar', 'El importe cambió (precio, equipos o paradas). Revisá el importe anterior y el nuevo y confirmá. Los cobros ya generados no cambian.'],
                    ['Me avisa que ya hay otro servicio parecido', 'Es un aviso: el alcance ya tiene otra contratación que cubre visitas del mismo tipo. Podés continuar si corresponde.'],
                ],
                'related' => ['services_overview', 'service_lifecycle', 'receivables'],
                'keywords' => ['contratar', 'contrato', 'abono', 'mensual', 'servicio', 'pago unico', 'frecuencia de cobro'],
                'area' => Area::Receivables,
            ]),

            $a('servicios', 'service_lifecycle', 'Cómo pausar, reactivar o finalizar un servicio', 'Qué pasa con los cobros en cada caso.', [
                'En la contratación, usá «Pausar» para dejar de generar cobros por un tiempo.',
                '«Reactivar» lo vuelve a activar: no se cobran los meses en que estuvo pausado. El próximo cobro es el del primer período que empieza en el mes de la reactivación o después.',
                '«Finalizar» lo da por terminado: deja de generar cobros y conserva el historial, las visitas y los cobros.',
                'Una contratación finalizada se vuelve a activar desde «Editar» (estado y fecha de fin).',
            ], [
                'notes' => ['Nada se borra. Un período ya cobrado nunca se cobra de nuevo.'],
                'related' => ['service_contract', 'rebill'],
                'keywords' => ['pausar', 'suspender', 'reactivar', 'finalizar', 'cancelar contrato', 'baja servicio'],
                'area' => Area::Receivables,
            ]),

            $a('servicios', 'rebill', 'Cómo volver a cobrar un cargo de pago único anulado', 'Cuando anulaste el cobro de un servicio de pago único y hay que emitirlo de nuevo.', [
                'Abrí la contratación de pago único cuyo cobro está anulado.',
                'Tocá «Volver a cobrar» y confirmá.',
            ], [
                'before' => ['Aparece solo si el cobro del pago único está «Anulada», no hay otro vigente y la contratación no está pausada.'],
                'after' => 'Se crea un cobro nuevo por el importe acordado, con vencimiento el día pactado de este mes (o del siguiente, si ya pasó). El cobro anulado queda como estaba.',
                'notes' => ['Un doble clic no crea dos cobros.'],
                'related' => ['service_lifecycle', 'receivables'],
                'keywords' => ['volver a cobrar', 'recobrar', 'cobro anulado', 'pago unico', 'reemitir'],
                'area' => Area::Receivables,
            ]),

            $a('servicios', 'receivables', 'Cómo registrar un pago en Cobranzas', 'Lo que te deben tus clientes y lo que ya cobraste.', [
                'Entrá a «Comercial → Cobranzas». Arriba ves el total pendiente, el vencido y lo cobrado este mes.',
                'Abrí el cobro y tocá «Registrar pago»: fecha, importe y medio de pago. Podés registrar un pago parcial.',
                'Para un cargo que no sale de un servicio ni de un presupuesto, usá «Nuevo cobro».',
                'Si un cobro no corresponde, usá «Anular» con un motivo (solo si no tiene pagos).',
            ], [
                'after' => 'El estado pasa a «Parcialmente pagada» o «Pagada». «Vencida» se calcula sola cuando pasa el vencimiento con saldo.',
                'related' => ['service_contract', 'quote_charge', 'rebill'],
                'keywords' => ['cobranza', 'pago', 'cobrar', 'deuda', 'cuenta corriente', 'saldo', 'vencido', 'factura'],
                'area' => Area::Receivables,
                'open' => ReceivableResource::class,
            ]),

            /* ======================== MAPA, QR Y PEDIDOS DE AYUDA ======================== */
            $a('mapa-qr', 'map', 'Mapa de edificios', 'Toda la cartera en el mapa.', [
                'Entrá a «Gestión → Mapa». Los edificios se ubican solos con la dirección cargada.',
                'Si alguno no se pudo ubicar, aparece en «Edificios sin ubicar»: corregí la dirección o marcalo a mano.',
                'Podés filtrar por zona, técnico y estado del mes. Tocá un punto para ver el cliente y abrir el edificio.',
                'En la pestaña «Técnicos» ves dónde están los técnicos que activaron su ubicación (si tenés permiso de «Técnicos»).',
            ], [
                'related' => ['building_create', 'technician_location'],
                'keywords' => ['mapa', 'ubicacion', 'zona', 'recorrido', 'geolocalizacion'],
                'feature' => Feature::Map,
                'area' => Area::Buildings,
                'open' => BuildingsMap::class,
            ]),

            $a('mapa-qr', 'technician_location', 'Cómo ver la ubicación de los técnicos', 'La pestaña «Técnicos» del mapa: dónde está cada técnico que activó su ubicación.', [
                'Entrá a «Gestión → Mapa» y tocá la pestaña «Técnicos».',
                'Cada técnico tiene una tarjeta con su foto, su estado y la hora de su última posición. Tocala para ubicarlo en el mapa.',
                '«En línea»: posición de los últimos minutos. «Ubicación desactualizada»: es la última que llegó, en gris y con su hora.',
                'La vista se actualiza sola cada pocos segundos mientras la tenés abierta.',
            ], [
                'before' => ['Cada técnico activa su ubicación desde su perfil, en «Ubicación», y da permiso en su celular. Nadie puede activarla por él.', 'La ven el dueño y los administrativos con permiso de «Técnicos».'],
                'notes' => ['El celular solo manda la posición mientras el técnico tiene Ascento abierto en pantalla: con el teléfono bloqueado no llegan posiciones nuevas.', 'Se guarda solo la última posición (no un recorrido) y se borra si el técnico la desactiva, cierra sesión o pasan unas horas sin novedades.'],
                'problems' => [['Un técnico figura «Permiso denegado»', 'Activó la ubicación en Ascento pero su celular no da permiso. En su perfil tiene las instrucciones para habilitarlo.'], ['Un técnico figura «Ubicación desactualizada»', 'Su celular no mandó posiciones recientes: puede tener Ascento cerrado, la pantalla bloqueada o estar sin señal.']],
                'related' => ['map', 'on_call_query'],
                'keywords' => ['ubicacion', 'gps', 'donde esta', 'tecnicos en el mapa', 'seguimiento', 'localizacion'],
                'feature' => Feature::Map,
                'area' => Area::Technicians,
                'open' => BuildingsMap::class,
            ]),

            $a('mapa-qr', 'public_qr', 'Cómo obtener e imprimir el QR de un edificio', 'Un código para que residentes y visitantes vean información del ascensor sin cuenta.', [
                'Entrá a «Gestión → QR públicos» y abrí el edificio.',
                'Elegí qué se publica: ascensores del edificio, estado informado de cada uno, último mantenimiento, última inspección, pruebas de paracaídas, trabajos pendientes y contacto de la empresa.',
                'Tocá «Generar QR».',
                'Usá «Hoja para imprimir (A4)» o «Versión compacta» para imprimirlo con tu logo, o descargá la imagen.',
                'Pegalo en el edificio, en un lugar visible.',
            ], [
                'after' => 'Quien escanea ve solo lo que elegiste publicar. Si cambiás el estado informado de un ascensor, la página lo muestra al instante.',
                'notes' => ['«Regenerar QR» o «Desactivar» dejan sin efecto el código impreso.', 'Nunca se publican datos del cliente, montos ni notas internas. No es una certificación de seguridad.'],
                'related' => ['emergency_setup', 'parachute'],
                'keywords' => ['qr', 'codigo', 'imprimir', 'cartel', 'hoja', 'residentes', 'vecinos'],
                'feature' => Feature::PublicQr,
                'area' => Area::PublicPages,
                'image' => ['src' => 'images/help/qr-hoja.webp', 'alt' => 'Hoja A4 del QR con el nombre de la empresa, el código y el pie «Funciona con Ascento»', 'caption' => 'Hoja para imprimir del QR. Captura con datos de ejemplo; el código está difuminado.'],
                'open' => PublicQrPage::class,
            ]),

            $a('mapa-qr', 'emergency_setup', 'Cómo habilitar o deshabilitar los pedidos de ayuda por QR', 'El botón «Estoy atrapado en el ascensor» en la página del QR.', [
                'Abrí «Mi empresa» (arriba del menú).',
                'En «Pedidos de ayuda desde el QR», activá o desactivá «Permitir solicitudes de emergencia desde el QR público». Se guarda al tocarlo.',
            ], [
                'before' => ['Lo cambia solo el dueño de la cuenta.', 'Viene desactivado. Solo está en los planes que incluyen el pedido de ayuda.'],
                'after' => 'Activado, el botón aparece en la página del QR de los edificios publicados. Desactivarlo no borra los pedidos ya registrados.',
                'notes' => ['Es un aviso sin confirmar, no un servicio de emergencias: no garantiza que alguien lo vea ni una atención inmediata, y no reemplaza la alarma del ascensor ni el 911.'],
                'related' => ['emergency', 'public_qr'],
                'keywords' => ['emergencia', 'atrapado', 'atrapamiento', 'boton', 'activar', 'desactivar', 'pedido de ayuda'],
                'feature' => Feature::EmergencyRequests,
                'owner' => true,
                'open' => CompanySettings::class,
            ]),

            $a('mapa-qr', 'emergency', 'Cómo consultar y cerrar un pedido de ayuda', 'Qué pasa cuando alguien toca «Estoy atrapado en el ascensor».', [
                'Al confirmarse un pedido, Ascento avisa a tu empresa (dueño, administrativos con «Órdenes de trabajo» y técnicos de guardia en ese momento) y a la administración del edificio en el portal.',
                'En «Operaciones → Pedidos de ayuda» ves cada pedido. Un número rojo en el menú indica los abiertos.',
                'Abrilo: en «Avisos» ves a quién se avisó y por qué canal (aviso en Ascento, push, correo).',
                'Verificá la situación y cerralo como atendido o falsa alarma, con una nota.',
            ], [
                'notes' => ['Los pedidos repetidos del mismo edificio se suman al abierto: no generan otro aviso cada vez.', 'El sistema no puede confirmar que alguien haya visto el aviso.'],
                'related' => ['emergency_setup', 'on_call_query'],
                'keywords' => ['emergencia', 'atrapado', 'pedido de ayuda', 'alerta', 'cerrar pedido', 'falsa alarma'],
                'feature' => Feature::EmergencyRequests,
                'area' => Area::WorkOrders,
                'open' => EmergencyRequestResource::class,
            ]),

            /* ============================ GUARDIAS ============================ */
            $a('guardias', 'on_call', 'Cómo configurar el horario de guardia', 'Decir cuándo hace falta alguien de guardia (una sola vez).', [
                'En «Operaciones → Guardias», tocá «Configurar el horario de guardia» (si ya tenés uno: «Horarios de guardia» → «Agregar otro horario de guardia»).',
                'Poné un «Nombre», elegí «¿Qué días empieza la guardia?» y el horario «¿Desde qué hora?» y «¿Hasta qué hora?». Abajo ves cómo queda, por ejemplo: «De lunes a viernes, de 18:00 a 08:00 del día siguiente».',
                'Dejá «¿Cuántos técnicos cubren a la vez?» en 1, salvo que siempre cubran de a dos.',
                'Si querés, escribí «Indicaciones para los técnicos».',
                'En «¿Quiénes se turnan?», dejá «Se turnan siempre en el mismo orden» y agregá los técnicos en orden (ver «Cómo armar la rotación»). Si no tienen un orden fijo, elegí «Las asigno a mano cada vez».',
            ], [
                'after' => 'Volvés a «Guardias»: con rotación, los turnos ya están armados; si asignás a mano, usá «Asignar guardia».',
                'notes' => ['Un técnico asignado solo está de guardia dentro de este horario.', 'Una vez que la guardia tuvo turnos, el horario no se cambia (el historial depende de él). Para otro horario, dejá de usarla y creá una nueva.', 'Las guardias organizan la cobertura: no despachan trabajos solas ni liquidan horas.'],
                'related' => ['on_call_assign', 'on_call_replace', 'on_call_query'],
                'keywords' => ['guardia', 'horario de guardia', 'fuera de horario', 'noche', 'fin de semana', 'cobertura', 'configurar guardia'],
                'feature' => Feature::OnCall,
                'area' => Area::OnCall,
                'open' => OnCallScheduleResource::class,
            ]),

            $a('guardias', 'on_call_assign', 'Cómo asignar una guardia', 'Quién cubre, desde cuándo y hasta cuándo.', [
                'En «Operaciones → Guardias», tocá «Asignar guardia» (o «Asignar» al lado de un turno sin técnico: viene con ese turno ya elegido).',
                'Elegí «¿A quién asignamos?», el día y la hora en que comienza, y hasta qué día y hora cubre. Si es de noche (18:00 a 08:00), el fin es el día siguiente.',
                'Si queda asignado hasta que lo cambies, marcá «Sin fecha de fin».',
                'Tocá «Siguiente»: vas a ver un resumen («Juan Pérez cubrirá la guardia desde el lunes 18:00 hasta el martes 08:00»). Si está bien, «Confirmar asignación».',
            ], [
                'after' => 'Al técnico le llega un aviso y la ve en su pestaña «Guardias». Un recordatorio le avisa antes de que empiece.',
                'notes' => ['Si algo no cierra (fin antes del inicio, fuera del horario de la guardia, otro técnico ya asignado), el resumen no avanza y te dice cómo corregirlo.', 'Ascento no sabe si el técnico está disponible: confirmalo con él.', 'Si los técnicos se turnan siempre en el mismo orden, armá la rotación: no hace falta asignar cada turno.'],
                'related' => ['on_call', 'on_call_replace', 'on_call_rotation'],
                'keywords' => ['asignar guardia', 'turnos', 'permanente', 'periodo', 'guardia nocturna'],
                'feature' => Feature::OnCall,
                'area' => Area::OnCall,
            ]),

            $a('guardias', 'on_call_replace', 'Cómo reemplazar a un técnico de guardia', 'Cuando alguien no puede cubrir todo o parte de su turno.', [
                'En «Operaciones → Guardias», tocá «Reemplazar» al lado del turno (en «De guardia ahora», «Próxima guardia» o «Próximos turnos»), o «Reemplazar técnico» arriba y elegí el turno.',
                'Elegí «¿Quién lo reemplaza?» y «¿Por cuánto tiempo?»: solo esa franja, todo lo que queda del turno, o las fechas que elijas.',
                'Tocá «Siguiente»: el resumen dice quién cubría, quién lo reemplaza y durante qué período, y qué pasa antes y después. Si está bien, «Confirmar reemplazo».',
            ], [
                'after' => 'El turno original queda en el historial y se crean el reemplazo y, si corresponde, los tramos de antes y después. Los dos técnicos reciben el aviso. Los trabajos vinculados siguen vinculados al turno original.',
                'notes' => ['Lo que ya pasó no se modifica: un reemplazo empieza ahora o más adelante.', 'Los demás turnos no cambian.'],
                'related' => ['on_call_assign', 'on_call_query'],
                'keywords' => ['reemplazo', 'cambio de guardia', 'cubrir', 'suplente', 'cambiar tecnico', 'reemplazar'],
                'feature' => Feature::OnCall,
                'area' => Area::OnCall,
            ]),

            $a('guardias', 'on_call_query', 'Cómo saber quién está de guardia', 'Ahora, después, en una fecha puntual y lo que falta cubrir.', [
                'Entrá a «Operaciones → Guardias». Arriba ves quién está de guardia ahora y hasta cuándo, y la próxima guardia.',
                'En «Para revisar» aparecen los turnos sin técnico de los próximos 7 días (con «Asignar») y si un técnico quedó en dos guardias a la vez.',
                'Para otra fecha, abrí «Calendario completo»: ahí está «¿Quién está de guardia el…?».',
            ], [
                'notes' => ['Un técnico está de guardia cuando tiene un turno vigente y el horario de la guardia cubre ese momento. Las horas son las de Argentina.', 'El historial de cambios y los turnos anteriores están plegados al final de la pantalla.'],
                'related' => ['on_call', 'on_call_work', 'fix_on_call_none'],
                'keywords' => ['quien esta de guardia', 'de guardia ahora', 'huecos', 'sin tecnico', 'cobertura', 'consultar guardia'],
                'feature' => Feature::OnCall,
                'area' => Area::OnCall,
                'image' => ['src' => 'images/help/guardias.webp', 'alt' => 'Pantalla de Guardias con el técnico de guardia ahora y la consulta por fecha', 'caption' => 'Guardias: quién está ahora y consulta por fecha. Captura con datos de ejemplo.'],
            ]),

            $a('guardias', 'on_call_rotation', 'Cómo armar la rotación', 'Juan esta semana, Ana la próxima, Luis la siguiente… y vuelve a empezar, para siempre.', [
                'Al configurar el horario de guardia (o en «Guardias» → «Armar la rotación» / «Cambiar técnicos u orden»), en «¿Quiénes se turnan?» elegí «Se turnan siempre en el mismo orden».',
                'Agregá los técnicos en el orden en que se turnan (arrastralos para cambiarlo), cada cuántos días cambia el técnico (7 = una semana) y desde qué día.',
                'Dejá vacío «¿Termina en alguna fecha?»: así se repite siempre.',
                'Mirá la vista previa (por ejemplo: «Juan del lun 3/11 al lun 10/11; después, Ana…») y guardá.',
            ], [
                'after' => 'En «Guardias», la tarjeta «Así se turnan» muestra el orden. Ascento deja anotadas las próximas semanas, suma un día más cada noche y avisa a cada técnico cuándo le toca.',
                'notes' => ['Si alguien falta unos días (vacaciones, licencia), tocá «Cargar una ausencia»: se saltea y cubre el siguiente, sin cambiar el orden.', 'Si alguien no puede un turno puntual, usá «Reemplazar técnico».', 'No se crean turnos en el pasado, ni se tocan el turno en curso ni los cambios hechos a mano.'],
                'related' => ['on_call', 'on_call_assign'],
                'keywords' => ['rotacion', 'rotativa', 'turnarse', 'alternar', 'automatico', 'ausencia', 'vacaciones', 'licencia', 'semana', 'ciclo'],
                'feature' => Feature::OnCall,
                'area' => Area::OnCall,
            ]),

            $a('guardias', 'on_call_work', 'Cómo vincular una orden de trabajo a una guardia', 'Para saber qué trabajos surgieron durante una guardia.', [
                'Al crear o editar la orden, elegí la guardia en «Guardia (si se originó durante una)». El formulario muestra quién está de guardia ahora, para asignarle el trabajo.',
                'Asigná a los técnicos en «Técnicos asignados», como en cualquier orden.',
            ], [
                'after' => 'La orden aparece en la pestaña «Trabajos durante la guardia» y en el filtro «Guardia» de la lista de órdenes.',
                'notes' => ['La guardia nunca se deduce por la hora: un trabajo nocturno sin ese dato no figura como de guardia.'],
                'related' => ['work_orders', 'on_call_query'],
                'keywords' => ['orden de guardia', 'trabajo de guardia', 'vincular', 'reclamo nocturno'],
                'feature' => Feature::OnCall,
                'area' => Area::WorkOrders,
            ]),

            /* ============================ PORTAL ============================ */
            $a('portal', 'portal', 'Cómo permitir que un cliente acceda al portal', 'Dar acceso a una persona del consorcio o de la administración.', [
                'Abrí el cliente y entrá a la pestaña «Acceso al portal».',
                'Tocá «Invitar al portal» y cargá el «Nombre de la persona» y su «Email».',
                'Elegí los «Edificios que puede ver».',
                'Confirmá: la persona recibe un correo para elegir su contraseña y activar la cuenta.',
            ], [
                'after' => 'La persona entra al portal y ve los edificios autorizados: sus visitas, sus técnicos y lo que compartas. Nunca recibe una contraseña por correo.',
                'problems' => [
                    ['La invitación venció', 'Usá «Reenviar invitación»: el enlace anterior deja de servir.'],
                    ['La persona olvidó su contraseña', 'Usá «Enviar cambio de contraseña» o pedile que use «¿Olvidaste tu contraseña?» en el ingreso.'],
                ],
                'related' => ['portal_access', 'portal_share'],
                'keywords' => ['portal', 'invitar', 'acceso cliente', 'consorcio', 'administrador', 'usuario cliente'],
                'feature' => Feature::ClientPortal,
                'area' => Area::Clients,
                'open' => ClientResource::class,
            ]),

            $a('portal', 'portal_access', 'Cómo cambiar los edificios que ve una persona del portal', 'Los permisos del portal son por edificio.', [
                'Abrí el cliente → «Acceso al portal».',
                'En la fila de la persona, usá la acción para cambiar sus «Edificios».',
                'Para cortar el acceso, usá «Desactivar». Para devolverlo, «Reactivar».',
            ], [
                'after' => 'El cambio rige al instante, incluso con la sesión abierta.',
                'notes' => ['Solo se pueden elegir edificios de ese cliente. Una persona puede ser cliente de varias empresas con la misma cuenta, y cada empresa ve solo sus propios accesos.'],
                'related' => ['portal', 'portal_share'],
                'keywords' => ['permisos portal', 'edificios autorizados', 'desactivar acceso', 'quitar acceso'],
                'feature' => Feature::ClientPortal,
                'area' => Area::Clients,
            ]),

            $a('portal', 'portal_share', 'Cómo compartir un remito, reporte o documento con el cliente', 'Qué ve el cliente en su portal y cómo decidirlo.', [
                'Abrí el remito, el reporte, el presupuesto o el documento del legajo.',
                'Usá «Compartir con el cliente». También podés tildar varios en la lista y compartirlos juntos.',
                'Para quitarlo, usá «Dejar de compartir».',
            ], [
                'after' => 'Lo ven las personas del portal que tienen autorizado ese edificio. Todo es privado hasta que lo compartís.',
                'related' => ['portal', 'fix_portal_document'],
                'keywords' => ['compartir', 'portal', 'mostrar al cliente', 'publicar', 'documento cliente'],
                'feature' => Feature::ClientPortal,
                'area' => Area::Documents,
            ]),

            /* ===================== INDICADORES, TAREAS Y SEGUIMIENTO ===================== */
            $a('seguimiento', 'insights', 'Indicadores y centro de atención', 'Cómo viene tu empresa y qué necesita atención hoy.', [
                'En «Centro de atención» ves solo lo que necesita que hagas algo hoy: mantenimientos vencidos, trabajos trabados, reportes graves, presupuestos por vencer, stock bajo. Si está vacío, está todo en orden.',
                'En «Indicadores», cada número responde una pregunta concreta (¿cumplimos los mantenimientos?, ¿entran más reclamos?). Sirven para mirar la tendencia.',
            ], [
                'related' => ['tasks', 'agenda'],
                'keywords' => ['indicadores', 'estadisticas', 'metricas', 'tablero', 'kpi', 'atencion', 'pendientes'],
                'feature' => Feature::Indicators,
                'area' => Area::Indicators,
                'open' => IndicatorsPage::class,
            ]),

            $a('seguimiento', 'tasks', 'Tareas y recordatorios', 'Pendientes de la oficina, con responsable y aviso.', [
                'En «Gestión → Tareas», tocá «Nueva tarea» con título, prioridad, «Vence» y, si querés, un «Recordatorio».',
                'En «Avisar a» elegí quién recibe el recordatorio. Si no elegís a nadie, se avisa al «Responsable».',
                'Podés relacionarla con un cliente, edificio, ascensor, orden o presupuesto.',
                'En «Mis tareas» ves las tuyas. Al terminar, tocá «Completar».',
            ], [
                'after' => 'El recordatorio llega a la campanita y como aviso en el celular.',
                'related' => ['contacts'],
                'keywords' => ['tarea', 'pendiente', 'recordatorio', 'seguimiento', 'to do', 'agenda oficina'],
                'feature' => Feature::Tasks,
                'area' => Area::Tasks,
                'open' => TaskResource::class,
            ]),

            $a('seguimiento', 'contacts', 'Historial de contactos', 'Cada llamado, visita o correo con el cliente.', [
                'En «Gestión → Historial de contactos», registrá el contacto con su tipo y resultado.',
                'Podés asociarlo a un edificio, un presupuesto o una orden.',
            ], [
                'after' => 'El inicio te avisa qué clientes llevan tiempo sin contacto.',
                'related' => ['tasks', 'quotes'],
                'keywords' => ['contactos', 'llamado', 'seguimiento comercial', 'crm', 'cliente'],
                'feature' => Feature::ClientContacts,
                'area' => Area::Contacts,
                'open' => ClientInteractionResource::class,
            ]),

            /* ===================== USUARIOS, PERMISOS Y SEGURIDAD ===================== */
            $a('usuarios', 'technicians', 'Cómo crear o dar de baja un técnico', 'Las personas que trabajan en la calle.', [
                'En «Administración → Técnicos», creá uno nuevo con su «Nombre», «Email», «Número de teléfono» y una contraseña.',
                'Pasale el correo y la contraseña: con eso entra desde el celular.',
                'Para asignarle trabajo, usá «Asignar empleado» en Edificios.',
                'Si deja la empresa, tocá «Desactivar»: su historial se conserva.',
            ], [
                'problems' => [['No puedo crear más técnicos', 'Llegaste al límite de tu plan. Desactivar a quien ya no trabaja libera cupo.']],
                'related' => ['assignments', 'tech_app', 'passwords'],
                'keywords' => ['tecnico', 'empleado', 'alta tecnico', 'usuario tecnico', 'operario', 'baja tecnico'],
                'area' => Area::Technicians,
                'open' => UserResource::class,
            ]),

            $a('usuarios', 'staff', 'Cómo crear un administrativo', 'Personal de oficina con acceso solo a lo que necesita.', [
                'En «Administración → Administrativos», tocá «Nuevo administrativo».',
                'Completá «Nombre», «Correo», «Teléfono» y la «Contraseña inicial» (se la pasás a la persona; después la cambia desde su perfil).',
                'En «Permisos», elegí por área: «Sin acceso», «Ver» o «Gestionar».',
            ], [
                'after' => 'La persona entra al panel y ve solo las secciones de sus áreas.',
                'notes' => ['La cantidad de administrativos depende de tu plan.', '«Mi empresa», la suscripción, los administrativos y la exportación son siempre del dueño.'],
                'related' => ['staff_permissions'],
                'keywords' => ['administrativo', 'secretaria', 'oficina', 'usuario', 'empleado administrativo'],
                'owner' => true,
                'open' => StaffUserResource::class,
            ]),

            $a('usuarios', 'staff_permissions', 'Cómo modificar los permisos de un usuario', 'Cambiar qué puede ver o hacer un administrativo.', [
                'En «Administración → Administrativos», abrí a la persona y editala.',
                'En «Permisos», cambiá el nivel de cada área: «Sin acceso», «Ver» (consulta) o «Gestionar» (crea y modifica).',
                'Guardá. Para cortar el acceso, usá «Desactivar».',
            ], [
                'after' => 'El cambio rige al instante. Los permisos se controlan en el servidor: ocultar una opción del menú no es lo que la protege.',
                'notes' => ['Los técnicos no tienen permisos por área: ven lo que tienen asignado. El acceso al portal de un cliente se maneja desde ese cliente.'],
                'related' => ['staff', 'portal_access'],
                'keywords' => ['permisos', 'roles', 'acceso', 'restringir', 'ver', 'gestionar'],
                'owner' => true,
                'open' => StaffUserResource::class,
            ]),

            $a('usuarios', 'passwords', 'Contraseñas y acceso de tu equipo', 'Qué hacer si alguien no puede entrar.', [
                'Cualquier persona puede usar «¿Olvidaste tu contraseña?» en la pantalla de ingreso: recibe un enlace por correo.',
                'Cada uno cambia su correo o su contraseña desde su perfil. El correo nuevo se confirma con un enlace.',
                'A una persona del portal también le podés mandar «Enviar cambio de contraseña» desde «Acceso al portal».',
            ], [
                'notes' => ['Nadie ve las contraseñas de otros, ni el dueño ni Ascento.'],
                'related' => ['technicians', 'staff', 'portal'],
                'keywords' => ['contrasena', 'clave', 'olvide', 'no puedo entrar', 'acceso', 'login', 'password'],
            ]),

            /* ===================== PLAN, SUSCRIPCIÓN Y EXPORTACIÓN ===================== */
            $a('cuenta', 'subscription', 'Planes, límites y suscripción', 'Qué incluye tu plan y qué pasa al llegar a un límite.', [
                'En «Administración → Mi suscripción» ves tu plan, cuánto usaste de cada límite y los planes disponibles.',
                'Al llegar a un límite (edificios, clientes, técnicos, reportes del mes o administrativos), no podés agregar más hasta cambiar de plan. No se borra nada.',
                'Si una función no está en tu plan, Ascento te lo explica y te lleva a «Ver planes».',
                'Te suscribís o cambiás de plan con Mercado Pago desde la misma pantalla.',
            ], [
                'notes' => ['Si bajás de plan, los datos de las funciones que ya no tenés se conservan y vuelven a verse si recuperás la función.', 'La prueba gratis dura '.SubscriptionPlan::TRIAL_DAYS.' días.'],
                'related' => ['export', 'fix_limit'],
                'keywords' => ['plan', 'suscripcion', 'pago', 'mercado pago', 'limite', 'precio', 'prueba gratis', 'cambiar plan'],
                'owner' => true,
                'open' => Subscription::class,
            ]),

            $a('cuenta', 'export', 'Cómo exportar los datos de tu empresa', 'Una copia de tus datos para tenerlos vos.', [
                'En «Administración → Exportar datos», tocá «Generar exportación».',
                'En uno o dos minutos queda lista para descargar: un ZIP con un Excel y los archivos adjuntos.',
            ], [
                'before' => ['La exportación la pide y la descarga solo el dueño de la cuenta.'],
                'after' => 'Queda disponible unos días. En la misma pantalla ves quién la pidió y quién la descargó.',
                'notes' => ['Incluye solo los datos de tu empresa. No incluye contraseñas, firmas dibujadas ni enlaces públicos.'],
                'related' => ['subscription'],
                'keywords' => ['exportar', 'descargar datos', 'backup', 'respaldo', 'excel', 'copia'],
                'owner' => true,
                'open' => CompanyExports::class,
            ]),

            /* ======================= PROBLEMAS FRECUENTES ======================= */
            $a('problemas', 'fix_technician_building', 'Un técnico no ve un edificio', 'Falta la asignación para ese tipo de trabajo.', [
                'En «Gestión → Edificios», usá «Asignar empleado» y elegí el tipo de trabajo correcto (mantenimiento o inspección).',
            ], ['related' => ['assignments'], 'keywords' => ['no ve', 'no aparece', 'edificio', 'tecnico'], 'area' => Area::Buildings]),

            $a('problemas', 'fix_visit_pending', 'Un mantenimiento sigue pendiente', 'El remito se firmó como «no realizado» o todavía no se firmó.', [
                'Abrí el remito: si dice que no se pudo realizar, coordiná una nueva visita. Recién el remito realizado lo da por hecho.',
            ], ['related' => ['work_cycle', 'agenda'], 'keywords' => ['pendiente', 'vencido', 'no realizado', 'agenda'], 'area' => Area::Visits]),

            $a('problemas', 'fix_limit', 'No puedo agregar más edificios, clientes o técnicos', 'Llegaste al límite de tu plan.', [
                'Mirá tu uso en «Mi suscripción». Podés cambiar de plan o desactivar registros que ya no uses (liberan cupo).',
            ], ['related' => ['subscription', 'deactivate'], 'keywords' => ['limite', 'no me deja', 'cupo', 'maximo'], 'owner' => true]),

            $a('problemas', 'fix_portal_document', 'El cliente no ve un documento', 'No está compartido o el edificio no está autorizado.', [
                'Usá «Compartir con el cliente» en el documento y revisá en «Acceso al portal» que tenga ese edificio.',
            ], ['related' => ['portal_share', 'portal_access'], 'keywords' => ['portal', 'no ve', 'documento', 'remito'], 'feature' => Feature::ClientPortal, 'area' => Area::Clients]),

            $a('problemas', 'fix_push', 'No me llegan los avisos al celular', 'Los avisos se activan en cada dispositivo.', [
                'Tocá «Activar avisos» en ese celular y aceptá el permiso del navegador.',
                'En iPhone, primero agregá Ascento a la pantalla de inicio desde Safari.',
            ], ['related' => ['notifications'], 'keywords' => ['avisos', 'notificaciones', 'push', 'celular', 'no llegan']]),

            $a('problemas', 'fix_menu', 'No encuentro una sección del menú', 'Puede estar dentro de una categoría cerrada, fuera de tu plan o de tus permisos.', [
                'Abrí las categorías del menú: la sección puede estar adentro de «Gestión», «Operaciones», «Comercial» o «Administración».',
                'Usá «?» → «Recorrer el menú» para ver qué hay en cada categoría.',
                'Si no aparece, puede no estar incluida en tu plan o en tus permisos. El dueño de la cuenta lo ve en «Mi suscripción» y en «Administrativos».',
            ], ['related' => ['nav', 'help_button'], 'keywords' => ['no encuentro', 'menu', 'desaparecio', 'donde esta', 'seccion']]),

            $a('problemas', 'fix_emergency_button', 'El botón de pedido de ayuda no aparece en el QR', 'Depende del plan, de la activación y del QR del edificio.', [
                'Revisá que «Permitir solicitudes de emergencia desde el QR público» esté activado en «Mi empresa».',
                'Revisá que el QR del edificio esté generado y activo en «QR públicos».',
                'Si tu plan no incluye el pedido de ayuda, el interruptor aparece deshabilitado.',
            ], ['related' => ['emergency_setup', 'public_qr'], 'keywords' => ['boton', 'emergencia', 'no aparece', 'qr', 'atrapado'], 'feature' => Feature::PublicQr]),

            $a('problemas', 'fix_on_call_none', 'Nadie figura de guardia', 'Hay un hueco en la cobertura.', [
                'En «Guardias», mirá «Para revisar»: ahí aparece el turno sin técnico y, si es una rotación, si le faltan participantes o dejó de generar turnos.',
                'Tocá «Asignar» al lado de ese turno: el asistente viene con ese período ya elegido.',
            ], ['related' => ['on_call_query', 'on_call_assign'], 'keywords' => ['guardia', 'nadie', 'hueco', 'sin cobertura'], 'feature' => Feature::OnCall, 'area' => Area::OnCall]),

            /* ======================= SUPERADMIN ======================= */
            $a('plataforma', 'sa_companies', 'Empresas: consultar y entrar a una empresa', 'La lista de empresas que usan Ascento.', [
                'En «Plataforma → Empresas» ves cada empresa con su plan, su prueba gratis, su suscripción y sus usuarios.',
                'Abrí una empresa para ver su ficha. Con «Entrar a la empresa» operás dentro de ella para dar soporte.',
                '«Reenviar confirmación de correo» le manda de nuevo el enlace al responsable.',
                'Con «Desactivar» la empresa deja de operar sin borrar sus datos.',
            ], [
                'notes' => ['«Eliminar definitivamente» borra todos sus datos, usuarios y archivos y no se puede deshacer: pide una confirmación explícita.'],
                'related' => ['sa_subscriptions'],
                'keywords' => ['empresas', 'clientes de ascento', 'soporte', 'entrar', 'superadmin'],
                'super' => true,
                'open' => CompanyResource::class,
            ]),

            $a('plataforma', 'sa_subscriptions', 'Suscripciones y pagos de las empresas', 'El estado de cobro de cada empresa con Mercado Pago.', [
                'En «Plataforma → Suscripciones» ves cada suscripción con su plan, importe, próximo cobro y último resultado.',
                'Abrila para ver los pagos registrados y su período.',
                'Si el estado no coincide con Mercado Pago, usá «Sincronizar con Mercado Pago».',
            ], ['related' => ['sa_companies', 'sa_plans'], 'keywords' => ['suscripciones', 'mercado pago', 'cobros', 'pagos', 'superadmin'], 'super' => true, 'open' => SubscriptionResource::class]),

            $a('plataforma', 'sa_plans', 'Funciones de cada plan', 'Qué función está en qué plan.', [
                'En «Plataforma → Planes» ves las funciones de cada plan.',
                'Si cambiás alguna, tocá «Guardar cambios» y escribí el «Motivo (queda en el historial)».',
            ], [
                'notes' => ['El cambio afecta a todas las empresas de ese plan en el momento. Los datos de una función que se quita se conservan.'],
                'related' => ['sa_subscriptions'],
                'keywords' => ['planes', 'funciones', 'features', 'superadmin'],
                'super' => true,
                'open' => PlanFeatures::class,
            ]),

            $a('plataforma', 'sa_backups', 'Backups de la plataforma', 'Las copias de seguridad de todo Ascento.', [
                'En «Plataforma → Backups» ves las copias de seguridad generadas y su estado, y las descargás.',
            ], [
                'notes' => ['Es distinto de «Exportar datos», que es la copia de una sola empresa y la pide su dueño.', 'Tu cuenta usa segundo factor: se gestiona desde el menú de usuario, en «Segundo factor».'],
                'related' => ['sa_companies'],
                'keywords' => ['backup', 'copia de seguridad', 'respaldo', 'superadmin', 'segundo factor'],
                'super' => true,
                'open' => SystemBackups::class,
            ]),
        ];
    }

    /** Clases con «Abrir la sección» que se verifican en las pruebas. @return list<class-string> */
    public static function openTargets(): array
    {
        return array_values(array_unique(array_filter(array_column(self::all(), 'open'))));
    }
}
