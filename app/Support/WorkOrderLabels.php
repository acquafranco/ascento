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

    /**
     * Estados de una orden (los del enum de work_orders.status; las transiciones
     * no cambian acá: esto es solo cómo se ven). El texto se muestra siempre:
     * el color y el icono acompañan, no reemplazan.
     */
    public const STATUSES = [
        'pending' => 'Pendiente',
        'in_progress' => 'En progreso',
        'completed' => 'Completado',
        'failed' => 'No realizado',
    ];

    /** Color en el panel (Filament): pendiente ámbar, en progreso azul, completado verde, no realizado rojo. */
    public const STATUS_COLORS = [
        'pending' => 'warning',
        'in_progress' => 'info',
        'completed' => 'success',
        'failed' => 'danger',
    ];

    /** Icono discreto del estado en el panel. */
    public const STATUS_ICONS = [
        'pending' => 'heroicon-m-clock',
        'in_progress' => 'heroicon-m-arrow-path',
        'completed' => 'heroicon-m-check-circle',
        'failed' => 'heroicon-m-x-circle',
    ];

    /** Etiqueta del estado en la app del técnico (clases t-chip-* de app.css). */
    public const STATUS_CHIPS = [
        'pending' => 't-chip-pending',
        'in_progress' => 't-chip-info',
        'completed' => 't-chip-done',
        'failed' => 't-chip-failed',
    ];

    public static function statusColor(?string $status): string
    {
        return self::STATUS_COLORS[$status] ?? 'gray';
    }

    public static function statusIcon(?string $status): ?string
    {
        return self::STATUS_ICONS[$status] ?? null;
    }

    public static function statusChip(?string $status): string
    {
        return self::STATUS_CHIPS[$status] ?? 't-chip-neutral';
    }

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
        return self::STATUSES[$status] ?? $status;
    }
}
