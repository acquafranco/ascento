<?php

namespace App\Support\Help;

use App\Livewire\AdminOnboarding;

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
        'elevators_intro' => ['Ascensores', 'Cada equipo de tus edificios tiene su legajo: ficha técnica, documentación (planos, manuales, certificados) e historial. Se crean solos con la cantidad de ascensores y montacargas que cargás en el edificio.', null],
        'technical_file' => ['Legajo técnico', 'Arriba está la ficha técnica del equipo (fabricante, modelo, serie, capacidad…). Completala de a poco: con fabricante, modelo, número de serie, año, capacidad y paradas ya queda completa. Abajo, la documentación y el historial.', null],
        'elevator_history' => ['Historial del ascensor', 'Todo lo que pasó con este equipo, del más nuevo al más viejo: reportes, órdenes, presupuestos y los mantenimientos del edificio. No hay que cargar nada: sale solo de lo que ya registran.', null],
        'failure_analysis' => ['Análisis de fallas', 'Cuenta los avisos de falla de este equipo en los últimos 90 días: reportes de los técnicos y reclamos (y cuántos reclamos ya se resolvieron), agrupados por componente. Para que el análisis sea más preciso, elegí el componente afectado al cargar reportes y órdenes.', null],
        'maintenance_intro' => ['Mantenimientos', null, 'maintenances'],
        'inspections_intro' => ['Inspecciones', null, 'inspections'],
        'agenda_intro' => ['Agenda', 'Cada mes Ascento arma solo la lista de mantenimientos e inspecciones según los técnicos asignados a cada edificio. Arriba de todo aparece lo vencido y lo que no tiene técnico.', null],
        'work_orders_intro' => ['Órdenes de trabajo', null, 'work_orders'],
        'delivery_notes_intro' => ['Remitos', null, 'delivery_notes'],
        'budgets_intro' => ['Presupuestos', null, 'quotes'],
        'reports_intro' => ['Reportes', null, 'reports'],
        'stock_intro' => ['Stock', 'Tus repuestos y materiales. Cargá las entradas cuando comprás; las salidas se descuentan solas cuando un técnico declara lo que usó al firmar el remito de una orden.', null],
        'services_intro' => ['Servicios', 'Los contratos de mantenimiento con tus clientes. Cada período genera el cobro solo, y el servicio muestra los mantenimientos e inspecciones que se hicieron.', null],
        'receivables_intro' => ['Cobranzas', 'Lo que te deben tus clientes: cobros de servicios, de presupuestos aprobados y manuales. Registrá los pagos (también parciales) a medida que entran.', null],
        'map_intro' => ['Mapa', null, 'map'],
        'attention_center' => ['Centro de atención', 'Solo lo que necesita que hagas algo hoy: mantenimientos vencidos, trabajos trabados, reportes graves, presupuestos por vencer. Si está vacío, está todo en orden.', null],
        'exports_intro' => ['Exportar datos', 'Generá un ZIP con un Excel y los adjuntos de tu empresa para tener tus datos. Se prepara en uno o dos minutos y queda disponible unos días; acá ves quién lo pidió y quién lo descargó.', null],
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

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::TOPICS);
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
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
            'resources.maintenance-services.' => 'services_intro',
            'resources.receivables.' => 'receivables_intro',
        ];

        foreach ($map as $needle => $key) {
            if (str_contains($routeName, $needle)) {
                return $key;
            }
        }

        return null;
    }
}
