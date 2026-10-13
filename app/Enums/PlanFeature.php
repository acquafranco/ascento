<?php

namespace App\Enums;

/**
 * Funcionalidades que dependen del plan. Las del núcleo (mapa, órdenes,
 * mantenimientos, reportes…) están en los tres planes, pero también se
 * declaran acá para que todo se consulte de la misma forma:
 * $company->plan()->allows(PlanFeature::Map).
 */
enum PlanFeature: string
{
    case Buildings = 'buildings';
    case Clients = 'clients';
    case Technicians = 'technicians';
    case Maintenances = 'maintenances';
    case Inspections = 'inspections';
    case WorkOrders = 'work_orders';
    case Reports = 'reports';
    case History = 'history';
    case Map = 'map';
    case Quotes = 'quotes';
    case DigitalDeliveryNotes = 'digital_delivery_notes';

    // Inicial ("Operar la empresa"): en los tres planes.
    case Agenda = 'agenda';
    case AttentionCenter = 'attention_center';
    case ElevatorFile = 'elevator_file';
    case Indicators = 'indicators';

    // Profesional ("Controlar y entender la empresa").
    case AttentionAdvanced = 'attention_advanced';
    case CompanyIndicators = 'company_indicators';
    case ElevatorHistoryAdvanced = 'elevator_history_advanced';
    case FailureAnalysis = 'failure_analysis';

    case ClientPortal = 'client_portal';

    case ReportVideos = 'report_videos';

    // Empresa ("Gestionar toda la empresa").
    case AdvancedIndicators = 'advanced_indicators';
    case AdvancedAlerts = 'advanced_alerts';

    // Fotos de antes y después (mantenimientos y órdenes), reporte de
    // inspección con fotos y su consulta desde el panel.
    case WorkPhotos = 'work_photos';
    // Etapas 6 a 8. En todos los planes: tareas, QR público, pruebas de
    // paracaídas y reparaciones. Desde Profesional: historial de contactos.
    case Tasks = 'tasks';
    case ClientContacts = 'client_contacts';
    case PublicQr = 'public_qr';
    case ParachuteTests = 'parachute_tests';
    case Repairs = 'repairs';

    public function label(): string
    {
        return match ($this) {
            self::Buildings => 'Edificios',
            self::Clients => 'Clientes',
            self::Technicians => 'Técnicos',
            self::Maintenances => 'Mantenimientos',
            self::Inspections => 'Inspecciones',
            self::WorkOrders => 'Órdenes de trabajo',
            self::Reports => 'Reportes',
            self::History => 'Historial',
            self::Map => 'Mapa de edificios',
            self::Quotes => 'Presupuestos',
            self::DigitalDeliveryNotes => 'Remitos digitales para el cliente',
            self::Agenda => 'Agenda de mantenimientos',
            self::AttentionCenter => 'Centro de atención',
            self::ElevatorFile => 'Legajo técnico del ascensor',
            self::Indicators => 'Indicadores básicos',
            self::AttentionAdvanced => 'Centro de atención avanzado',
            self::CompanyIndicators => 'Indicadores de empresa',
            self::ElevatorHistoryAdvanced => 'Historial avanzado del ascensor',
            self::FailureAnalysis => 'Análisis de fallas y reincidencias',
            self::ClientPortal => 'Portal para clientes',
            self::ReportVideos => 'Video en los reportes',
            self::AdvancedIndicators => 'Indicadores avanzados y cartera',
            self::AdvancedAlerts => 'Alertas avanzadas',
            self::WorkPhotos => 'Fotos de los trabajos y reportes de inspección',
            self::Tasks => 'Tareas y recordatorios',
            self::ClientContacts => 'Historial de contactos con clientes',
            self::PublicQr => 'QR público de los edificios',
            self::ParachuteTests => 'Pruebas de paracaídas',
            self::Repairs => 'Componentes enviados a reparación',
        };
    }

    /** Explicación corta para la pantalla de upgrade. */
    public function pitch(): string
    {
        return match ($this) {
            self::Quotes => 'presupuestos con link para el cliente y envío por WhatsApp o email',
            self::DigitalDeliveryNotes => 'remitos digitales: PDF y link para compartir con el cliente por WhatsApp o email',
            self::AttentionAdvanced => 'alertas de reincidencias, documentación vencida y trabajos atrasados',
            self::CompanyIndicators => 'indicadores de la empresa: evolución, técnicos, edificios y presupuestos',
            self::ElevatorHistoryAdvanced => 'historial completo de cada ascensor con filtros y materiales usados',
            self::FailureAnalysis => 'análisis de fallas: qué ascensores se rompen seguido y por qué',
            self::AdvancedIndicators => 'comparativas año contra año, análisis de cartera y tendencias',
            self::AdvancedAlerts => 'alertas de tendencias, contratos por vencer y deudas viejas',
            self::ClientPortal => 'portal para que cada consorcio o administración vea lo que le compartís de sus edificios',
            self::ReportVideos => 'un video por reporte para mostrar la falla',
            self::WorkPhotos => 'fotos de antes y después de mantenimientos y órdenes, y reportes de inspección con fotos',
            self::ClientContacts => 'historial de contactos con cada cliente y seguimiento de los que llevan tiempo sin contacto',
            default => mb_strtolower($this->label()),
        };
    }

    /** Las que están en todos los planes (núcleo del producto). */
    public static function core(): array
    {
        return [
            self::Buildings, self::Clients, self::Technicians, self::Maintenances, self::Inspections,
            self::WorkOrders, self::Reports, self::History, self::Map,
            self::Agenda, self::AttentionCenter, self::ElevatorFile, self::Indicators,
            self::Tasks, self::PublicQr, self::ParachuteTests, self::Repairs,
        ];
    }

    /**
     * La base del producto: no se puede sacar de un plan (los otros módulos
     * dependen de ella). El resto lo puede activar o desactivar el SuperAdmin.
     */
    public static function structural(): array
    {
        return [self::Buildings, self::Clients, self::Technicians, self::Maintenances, self::Inspections, self::WorkOrders, self::Reports, self::History];
    }

    /** @return list<self> las que el SuperAdmin puede activar o desactivar por plan */
    public static function toggleable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => ! in_array($f, self::structural(), true)));
    }
}
