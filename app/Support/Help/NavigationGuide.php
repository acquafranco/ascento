<?php

namespace App\Support\Help;

use App\Filament\Clusters\ServicesCluster;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\AttentionCenterPage;
use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\CompanyExports;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HelpCenter;
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
use App\Filament\Resources\Inspections\InspectionResource;
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
use App\Models\User;

/**
 * Recorrido del menú por categorías (botón «?» → «Recorrer el menú»).
 *
 * Sale del menú REAL de esta persona (Filament ya lo filtra por permisos y
 * plan): solo se explican las opciones que ve. Cada opción tiene una
 * explicación corta y, si existe y la puede abrir, el enlace a su guía.
 * Es solo una guía visual: no da ni quita acceso a nada.
 */
final class NavigationGuide
{
    /** Categoría → [para qué sirve, orden sugerido]. */
    private const GROUPS = [
        'Gestión' => ['Tu cartera: los clientes, sus edificios y sus equipos, el mapa, el QR público y el seguimiento con cada cliente.', ['Clientes', 'Edificios', 'Ascensores']],
        'Operaciones' => ['El trabajo de todos los días: la agenda del mes, las visitas, las órdenes y sus remitos, los reportes de fallas, las guardias y el stock.', ['Agenda', 'Visitas', 'Remitos']],
        'Comercial' => ['Lo que vendés y cobrás: presupuestos, servicios contratados y la cuenta corriente de tus clientes.', ['Presupuestos', 'Servicios', 'Cobranzas']],
        'Administración' => ['Tu equipo, tu plan y la copia de tus datos.', null],
        'Plataforma' => ['Herramientas del SuperAdmin para todas las empresas.', null],
    ];

    /** Pantalla → [explicación corta, guía]. */
    private static function items(): array
    {
        return [
            Dashboard::class => ['Tu resumen del día: órdenes pendientes y terminadas, remitos de hoy, presupuestos que esperan respuesta y técnicos ocupados o libres.', 'work_cycle'],
            AttentionCenterPage::class => ['Solo lo que necesita que hagas algo hoy. Si está vacío, está todo en orden.', 'insights'],
            IndicatorsPage::class => ['Números que responden preguntas sobre tu empresa, para mirar la tendencia.', 'insights'],
            HelpCenter::class => ['El Centro de ayuda: buscá cualquier tarea con tus palabras.', 'help_button'],
            ClientResource::class => ['Los consorcios, administraciones y empresas que te contratan. Desde cada uno ves sus edificios, servicios, cuenta corriente y acceso al portal.', 'client_create'],
            BuildingResource::class => ['Cada edificio con su dirección y sus equipos. Acá asignás técnicos y la frecuencia de visitas.', 'building_create'],
            ElevatorResource::class => ['El legajo de cada ascensor y montacargas: ficha técnica, documentación e historial.', 'elevators'],
            BuildingsMap::class => ['Toda la cartera en el mapa, con filtros por zona, técnico y estado del mes.', 'map'],
            PublicQrPage::class => ['El QR de cada edificio para que residentes y visitantes vean información sin cuenta. Desde acá lo imprimís.', 'public_qr'],
            ClientInteractionResource::class => ['Cada llamado, visita o correo con un cliente, con su resultado.', 'contacts'],
            TaskResource::class => ['Pendientes de la oficina, con responsable, vencimiento y recordatorio.', 'tasks'],
            Agenda::class => ['Qué visitas tocan este mes, quién las tiene y cuáles faltan. Se arma sola.', 'agenda'],
            MaintenanceResource::class => ['Los mantenimientos hechos, cada uno con su remito.', 'visits'],
            InspectionResource::class => ['Las inspecciones hechas, con su remito y el reporte del inspector.', 'visits'],
            WorkOrderResource::class => ['Reclamos y trabajos puntuales: creás la orden, el técnico la toma y la cierra con el remito.', 'work_orders'],
            DeliveryNoteResource::class => ['Todos los remitos firmados, con su PDF. No se borran: son el historial del trabajo.', 'delivery_notes'],
            ReportResource::class => ['Las fallas que detectan los técnicos, con foto y prioridad. Las vas marcando en revisión o resueltas.', 'reports'],
            ClientClaimResource::class => ['Lo que tus clientes reportan desde el portal. Cambiás el estado, respondés y, si hace falta, creás la orden.', 'claims'],
            EmergencyRequestResource::class => ['Los pedidos por posible atrapamiento hechos desde el QR. Un número rojo indica los abiertos.', 'emergency'],
            OnCallScheduleResource::class => ['Quién cubre fuera de horario: quién está de guardia ahora, asignar guardias y reemplazar técnicos.', 'on_call'],
            ParachuteTestsPage::class => ['La periodicidad y el registro de las pruebas de paracaídas, con aviso de las próximas.', 'parachute'],
            RepairComponentResource::class => ['Los componentes que mandaste a un taller y en qué estado están.', 'repairs'],
            StockItemResource::class => ['Tus repuestos y materiales: entradas, salidas y lo que se usó en cada orden.', 'stock'],
            QuoteResource::class => ['Presupuestos con tu logo, para enviar por correo o WhatsApp.', 'quotes'],
            ServicesCluster::class => ['Lo que ofrecés (catálogo) y lo que tiene contratado cada cliente, que genera los cobros.', 'services_overview'],
            ReceivableResource::class => ['Lo que te deben tus clientes y los pagos que vas registrando.', 'receivables'],
            CompanySettings::class => ['Los datos y el logo de tu empresa, las fotos de los trabajos y los reportes de problemas desde el QR.', 'company_data'],
            UserResource::class => ['Las personas que trabajan en la calle. Cada una entra desde el celular.', 'technicians'],
            StaffUserResource::class => ['El personal de oficina y qué puede ver o gestionar cada uno.', 'staff'],
            Subscription::class => ['Tu plan, cuánto usaste de cada límite y cómo cambiarlo.', 'subscription'],
            CompanyExports::class => ['Una copia de los datos de tu empresa en Excel, con sus archivos.', 'export'],
            CompanyResource::class => ['Las empresas que usan Ascento.', 'sa_companies'],
            SubscriptionResource::class => ['Las suscripciones y pagos de cada empresa.', 'sa_subscriptions'],
            PlanFeatures::class => ['Qué funciones incluye cada plan.', 'sa_plans'],
            SystemBackups::class => ['Las copias de seguridad de toda la plataforma.', 'sa_backups'],
        ];
    }

    /**
     * Categorías del menú de esta persona, con sus opciones explicadas.
     *
     * @return list<array{label: ?string, title: string, intro: string, flow: ?list<string>, items: list<array{label: string, url: string, body: string, guide: ?string}>}>
     */
    public static function for(?User $user): array
    {
        try {
            $navigation = filament()->getNavigation();
        } catch (\Throwable) {
            return [];
        }

        $known = [];
        foreach (self::items() as $class => [$body, $guide]) {
            try {
                $known[$class::getUrl()] = [$body, $guide];
            } catch (\Throwable) {
                // Pantalla sin ruta en este panel: no se explica.
            }
        }

        $out = [];
        foreach ($navigation as $group) {
            $label = $group->getLabel();
            $items = [];
            foreach ($group->getItems() as $item) {
                $url = (string) $item->getUrl();
                [$body, $guide] = $known[$url] ?? [null, null];
                if ($body === null) {
                    continue;
                }
                $items[] = [
                    'label' => $item->getLabel(),
                    'url' => $url,
                    'body' => $body,
                    'guide' => $guide && HelpGuides::find($user, HelpGuides::PANEL, $guide) ? HelpGuides::url(HelpGuides::PANEL, $guide) : null,
                ];
            }
            if ($items === []) {
                continue;
            }
            [$intro, $flow] = self::GROUPS[$label] ?? ['Los accesos de todos los días.', null];
            $out[] = [
                'label' => $label,
                'title' => $label ?? 'Accesos principales',
                'intro' => $intro,
                'flow' => $flow,
                'items' => $items,
            ];
        }

        return $out;
    }

    /** @return list<class-string> Pantallas explicadas (para las pruebas). */
    public static function explained(): array
    {
        return array_keys(self::items());
    }
}
