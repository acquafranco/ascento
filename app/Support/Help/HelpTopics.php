<?php

namespace App\Support\Help;

use App\Filament\Pages\HelpCenter;
use App\Livewire\AdminOnboarding;
use App\Models\User;
use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Ayudas contextuales del panel. Cada una tiene su propio estado por usuario
 * (tabla help_dismissals): se muestra hasta que el usuario la descarta y se
 * puede volver a ver desde "Mi empresa → Ayuda".
 *
 * Los textos de las secciones que ya existían salen de la ayuda del botón
 * "?" (AdminOnboarding::sectionHelp), así no se duplican.
 */
class HelpTopics
{
    /** clave => [título, texto, sección del botón "?" de la que toma el texto (o null)] */
    private const TOPICS = [
        'company_setup' => ['Datos de tu empresa', null, 'company'],
        'technicians_intro' => ['Técnicos', null, 'technicians'],
        'clients_intro' => ['Clientes', null, 'clients'],
        'buildings_intro' => ['Edificios', null, 'buildings'],
        'elevators_intro' => ['Ascensores', 'Cada equipo de tus edificios tiene su legajo: ficha técnica, documentación (planos, manuales, certificados) e historial. Se registran en el edificio, cada uno con su tipo (ascensor o montacargas) y su tracción.', null],
        'technical_file' => ['Legajo técnico', 'Arriba está la ficha técnica del equipo (fabricante, modelo, serie, capacidad…). Completala de a poco: con fabricante, modelo, número de serie, año, capacidad y paradas ya queda completa. Abajo, la documentación y el historial.', null],
        'elevator_history' => ['Historial del ascensor', 'Todo lo que pasó con este equipo, del más nuevo al más viejo: reportes, órdenes, presupuestos y los mantenimientos del edificio. No hay que cargar nada: sale solo de lo que ya registran.', null],
        'failure_analysis' => ['Análisis de fallas', 'Cuenta los avisos de falla de este equipo en los últimos 90 días: reportes de los técnicos y reclamos (y cuántos reclamos ya se resolvieron), agrupados por componente. Para que el análisis sea más preciso, elegí el componente afectado al cargar reportes y órdenes.', null],
        'maintenance_intro' => ['Mantenimientos', null, 'maintenances'],
        'inspections_intro' => ['Inspecciones', null, 'inspections'],
        'agenda_intro' => ['Agenda', 'Cada mes Ascento arma solo la lista de mantenimientos e inspecciones según los técnicos asignados y la frecuencia de visitas de cada edificio (mensual si no le pusiste otra). Arriba ves el progreso: visitas previstas, realizadas y pendientes; un remito "no realizado" no cuenta como realizada. Quincenal = 2 visitas en el mes; cada N meses = 1 visita en el período (los otros meses aparecen como "Hecho en el período"). Primero aparece lo vencido y lo que no tiene técnico.', null],
        'work_orders_intro' => ['Órdenes de trabajo', null, 'work_orders'],
        'delivery_notes_intro' => ['Remitos', null, 'delivery_notes'],
        'budgets_intro' => ['Presupuestos', null, 'quotes'],
        'reports_intro' => ['Reportes de técnicos', null, 'reports'],
        'stock_intro' => ['Stock', 'Tus repuestos y materiales. Cargá las entradas cuando comprás; las salidas se descuentan solas cuando un técnico declara lo que usó al firmar el remito de una orden.', null],
        'emergency_intro' => ['Pedidos de ayuda (historial)', 'Los pedidos por posible atrapamiento que se registraron mientras esa opción existía. Ya no se reciben pedidos nuevos: hoy el QR ofrece «Reportar un problema». Si queda alguno abierto, verificalo y cerralo.', null],
        'on_call_intro' => ['Guardias', 'Quién cubre fuera del horario habitual. Arriba, quién está de guardia ahora y quién sigue. Si tus técnicos se turnan siempre en el mismo orden, armá la rotación una vez y se repite sola; si alguien falta, cargás la ausencia o lo reemplazás.', null],
        'services_intro' => ['Servicios', 'Dos vistas: «Servicios contratados», lo que tiene cada cliente (cada período genera el cobro solo y muestra los mantenimientos e inspecciones hechos), y «Servicios ofrecidos», tu catálogo con precios de referencia para contratar más rápido.', null],
        'receivables_intro' => ['Cobranzas', 'Lo que te deben tus clientes: cobros de servicios, de presupuestos aprobados y manuales. Registrá los pagos (también parciales) a medida que entran.', null],
        'map_intro' => ['Mapa', null, 'map'],
        'attention_center' => ['Centro de atención', 'Solo lo que necesita que hagas algo hoy: mantenimientos vencidos, trabajos trabados, reportes graves, presupuestos por vencer. Si está vacío, está todo en orden.', null],
        'exports_intro' => ['Exportar datos', 'Generá un ZIP con un Excel y los adjuntos de tu empresa para tener tus datos. Se prepara en uno o dos minutos y queda disponible unos días; acá ves quién lo pidió y quién lo descargó.', null],
        // Etapas 6 a 8.
        'tasks_intro' => ['Tareas', 'Pendientes de la oficina con responsable, vencimiento y recordatorio. En «Avisar a» elegís quién recibe el aviso; en «Mis tareas» ves las tuyas.', null],
        'contacts_intro' => ['Historial de contactos', 'Cada llamado, visita o correo con un cliente, con su resultado. El inicio te avisa qué clientes llevan tiempo sin contacto.', null],
        'public_qr_intro' => ['QR públicos', 'Un código por edificio para que residentes y visitantes vean, sin cuenta, solo lo que elegís publicar. No es una certificación de seguridad.', null],
        'parachute_intro' => ['Pruebas de paracaídas', 'Configurá la periodicidad de cada ascensor (o la del cliente) y registrá cada prueba. Ascento avisa las próximas y las vencidas; un aviso nunca cuenta como prueba hecha.', null],
        'repairs_intro' => ['Reparaciones', 'Los componentes que mandás a un taller: estado, fechas, proveedor y costos. El estado avanza con «Cambiar estado» y cada cambio queda en el historial.', null],
        'staff_intro' => ['Administrativos', 'Personal de oficina con acceso solo a lo que necesita: por área elegís si puede ver o gestionar. Los cambios rigen al instante.', null],
        'indicators' => ['Indicadores', 'Cada número responde una pregunta sobre tu empresa (¿cumplimos los mantenimientos?, ¿entran más reclamos?). Sirven para mirar la tendencia, no para controlar el día a día.', null],
    ];

    /** @return array<string, array{title: string, body: string}> */
    public static function all(): array
    {
        $sectionHelp = AdminOnboarding::sectionHelp();

        return collect(self::TOPICS)->map(fn ($topic) => [
            'title' => $topic[0],
            'body' => $topic[1] ?? ($sectionHelp[$topic[2]]['body'] ?? ''),
        ])->all();
    }

    /** Guía completa de cada ayuda (HelpGuides) para el enlace «Ver la guía completa». */
    private const GUIDES = [
        'company_setup' => 'company_data', 'technicians_intro' => 'technicians', 'clients_intro' => 'client_create', 'buildings_intro' => 'assignments',
        'elevators_intro' => 'elevators', 'technical_file' => 'elevators', 'elevator_history' => 'elevator_history', 'maintenance_intro' => 'visits',
        'inspections_intro' => 'visits', 'agenda_intro' => 'agenda', 'work_orders_intro' => 'work_orders', 'delivery_notes_intro' => 'delivery_notes',
        'budgets_intro' => 'quotes', 'reports_intro' => 'reports', 'stock_intro' => 'stock', 'services_intro' => 'services_overview',
        'receivables_intro' => 'receivables', 'map_intro' => 'map', 'attention_center' => 'insights', 'exports_intro' => 'export',
        'indicators' => 'insights', 'tasks_intro' => 'tasks', 'contacts_intro' => 'contacts', 'public_qr_intro' => 'public_qr',
        'parachute_intro' => 'parachute', 'repairs_intro' => 'repairs', 'staff_intro' => 'staff', 'on_call_intro' => 'on_call',
        'emergency_intro' => 'emergency',
    ];

    /**
     * Guías de cada pantalla para «?» → «En esta pantalla» (nombre de ruta de
     * Filament → artículos, del más al menos usado). Solo se muestran las que
     * la persona puede abrir.
     */
    private const SCREEN_GUIDES = [
        'pages.company-settings' => ['company_data', 'qr_reports_setup', 'work_photos'],
        'pages.subscription' => ['subscription', 'fix_limit'],
        'pages.agenda' => ['agenda', 'before_ascento', 'assignments'],
        'pages.atencion' => ['insights'],
        'pages.indicadores' => ['insights'],
        'pages.exportaciones' => ['export'],
        'pages.mapa' => ['map', 'technician_location', 'building_create'],
        'pages.qr-publicos' => ['public_qr', 'qr_reports_setup', 'fix_qr_report_button'],
        'pages.paracaidas' => ['parachute'],
        'pages.ayuda' => ['help_button', 'nav'],
        'resources.pruebas-paracaidas.' => ['parachute'],
        'resources.users.' => ['technicians', 'assignments', 'passwords'],
        'resources.clients.' => ['client_create', 'portal', 'portal_access', 'deactivate'],
        'resources.buildings.' => ['building_create', 'assignments', 'deactivate'],
        'resources.elevators.' => ['elevators', 'elevator_history'],
        'resources.maintenances.' => ['visits', 'work_cycle', 'fix_visit_pending'],
        'resources.inspections.' => ['visits', 'parachute'],
        'resources.work-orders.' => ['work_orders', 'work_order_status', 'materials', 'on_call_work'],
        'resources.delivery-notes.' => ['delivery_notes', 'portal_share'],
        'resources.quotes.' => ['quotes', 'quote_charge'],
        'resources.reports.' => ['reports', 'report_videos'],
        'resources.stock-items.' => ['stock', 'stock_adjust', 'materials'],
        'servicios.resources.' => ['services_overview', 'service_contract', 'service_lifecycle', 'rebill'],
        'resources.receivables.' => ['receivables', 'quote_charge', 'rebill'],
        'resources.guardias.' => ['on_call_assign', 'on_call_replace', 'on_call_query', 'on_call', 'on_call_rotation'],
        'resources.pedidos-de-ayuda.' => ['emergency', 'qr_reports_setup'],
        'resources.reclamos.' => ['claims', 'work_orders'],
        'resources.tareas.' => ['tasks'],
        'resources.contactos.' => ['contacts'],
        'resources.reparaciones.' => ['repairs'],
        'resources.administrativos.' => ['staff', 'staff_permissions'],
        'resources.companies.' => ['sa_companies'],
        'resources.subscriptions.' => ['sa_subscriptions'],
        'pages.planes' => ['sa_plans'],
        'pages.backups' => ['sa_backups'],
        'pages.dashboard' => ['nav', 'work_cycle', 'start'],
    ];

    /** Guías de la pantalla actual que esta persona puede abrir. @return list<array> */
    public static function screenGuides(string $routeName, ?User $user): array
    {
        foreach (self::SCREEN_GUIDES as $needle => $keys) {
            if (str_contains($routeName, $needle)) {
                return array_values(array_filter(array_map(fn ($key) => HelpGuides::find($user, HelpGuides::PANEL, $key), $keys)));
            }
        }

        return array_values(array_filter([HelpGuides::find($user, HelpGuides::PANEL, 'nav')]));
    }

    /** @return array<string, list<string>> (para las pruebas) */
    public static function screenGuideMap(): array
    {
        return self::SCREEN_GUIDES;
    }

    /** Enlace a la guía completa, solo si esta persona puede ver esa guía (plan y permisos). */
    public static function guideUrl(string $key, ?User $user): ?string
    {
        $guide = self::GUIDES[$key] ?? null;

        return $guide && HelpGuides::find($user, HelpGuides::PANEL, $guide)
            ? HelpCenter::getUrl(['guia' => $guide]).'#guia-'.$guide
            : null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::TOPICS);
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Ruta de la pantalla actual, también durante una acción de Livewire
     * (donde la ruta del request es "livewire.update" y la ayuda desaparecía
     * al tocar un botón o un filtro). La ruta original sale del snapshot del
     * componente, que Livewire firma: solo sirve para elegir el texto.
     */
    public static function currentRouteName(): string
    {
        $name = (string) request()->route()?->getName();

        if (! Livewire::isLivewireRequest()) {
            return $name;
        }

        try {
            return (string) app('router')->getRoutes()
                ->match(Request::create(Livewire::originalUrl()))
                ->getName();
        } catch (\Throwable) {
            return $name;
        }
    }

    /** Ayuda de cada pantalla (nombre de ruta de Filament → clave). */
    public static function forRoute(string $routeName): ?string
    {
        $map = [
            'pages.company-settings' => 'company_setup',
            'pages.agenda' => 'agenda_intro',
            'pages.atencion' => 'attention_center',
            'pages.indicadores' => 'indicators',
            'pages.exportaciones' => 'exports_intro',
            'pages.mapa' => 'map_intro',
            'resources.users.' => 'technicians_intro',
            'resources.clients.' => 'clients_intro',
            'resources.buildings.' => 'buildings_intro',
            'resources.elevators.view' => 'technical_file',
            'resources.elevators.edit' => 'technical_file',
            'resources.elevators.' => 'elevators_intro',
            'resources.maintenances.' => 'maintenance_intro',
            'resources.inspections.' => 'inspections_intro',
            'resources.work-orders.' => 'work_orders_intro',
            'resources.delivery-notes.' => 'delivery_notes_intro',
            'resources.quotes.' => 'budgets_intro',
            'resources.reports.' => 'reports_intro',
            'resources.stock-items.' => 'stock_intro',
            'servicios.resources.' => 'services_intro',
            'resources.guardias.' => 'on_call_intro',
            'resources.pedidos-de-ayuda.' => 'emergency_intro',
            'resources.receivables.' => 'receivables_intro',
            'resources.tareas.' => 'tasks_intro',
            'resources.contactos.' => 'contacts_intro',
            'pages.qr-publicos' => 'public_qr_intro',
            'pages.paracaidas' => 'parachute_intro',
            'resources.pruebas-paracaidas.' => 'parachute_intro',
            'resources.reparaciones.' => 'repairs_intro',
            'resources.administrativos.' => 'staff_intro',
        ];

        foreach ($map as $needle => $key) {
            if (str_contains($routeName, $needle)) {
                return $key;
            }
        }

        return null;
    }
}
