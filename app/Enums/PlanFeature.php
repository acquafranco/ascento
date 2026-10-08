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

    // Empresa ("Gestionar toda la empresa").
    case AdvancedIndicators = 'advanced_indicators';
    case AdvancedAlerts = 'advanced_alerts';

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
            self::AdvancedIndicators => 'Indicadores avanzados y cartera',
            self::AdvancedAlerts => 'Alertas avanzadas',
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
        ];
    }
}
