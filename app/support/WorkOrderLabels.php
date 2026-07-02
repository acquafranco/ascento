<?php

namespace App\Support;

class WorkOrderLabels
{
    public static function type(string $type): string
    {
        return match ($type) {
            'maintenance' => 'Mantenimiento',
            'inspection' => 'Inspección',
            'claim' => 'Reclamo',
            'installation' => 'Instalación',
            'modernization' => 'Modernización',
            default => $type,
        };
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
