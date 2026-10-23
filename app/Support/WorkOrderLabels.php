<?php

namespace App\Support;

class WorkOrderLabels
{
    /**
     * Tipos de orden de trabajo: ÚNICA lista (panel, app del técnico y
     * validación del servidor). Coincide con el enum de work_orders.type.
     *
     * - claim (Reclamo): un aviso o pedido que necesita atención.
     * - repair (Reparación): un trabajo hecho, que puede llevar repuestos y
     *   facturarse aparte (fuera de Ascento). No es el módulo «Reparaciones»
     *   (componentes enviados a taller, RepairComponent).
     */
    public const TYPES = [
        'maintenance' => 'Mantenimiento',
        'inspection' => 'Inspección',
        'claim' => 'Reclamo',
        'repair' => 'Reparación',
        'installation' => 'Instalación',
        'modernization' => 'Modernización',
    ];

    /** Color de cada tipo en las listas del panel (Filament). */
    public const TYPE_COLORS = [
        'maintenance' => 'primary',
        'inspection' => 'warning',
        'claim' => 'danger',
        'repair' => 'info',
        'installation' => 'success',
        'modernization' => 'gray',
    ];

    public static function type(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public static function priority(string $priority): string
    {
        return match ($priority) {
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            'urgent' => 'Urgente',
            default => $priority,
        };
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'in_progress' => 'En progreso',
            'completed' => 'Completado',
            'failed' => 'No realizado',
            default => $status,
        };
    }
}
