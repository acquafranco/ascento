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
        };
    }

    /** Explicación corta para la pantalla de upgrade. */
    public function pitch(): string
    {
        return match ($this) {
            self::Quotes => 'presupuestos con link para el cliente y envío por WhatsApp o email',
            self::DigitalDeliveryNotes => 'remitos digitales: PDF y link para compartir con el cliente por WhatsApp o email',
            default => mb_strtolower($this->label()),
        };
    }

    /** Las que están en todos los planes (núcleo del producto). */
    public static function core(): array
    {
        return [
            self::Buildings, self::Clients, self::Technicians, self::Maintenances, self::Inspections,
            self::WorkOrders, self::Reports, self::History, self::Map,
        ];
    }
}
