<?php

namespace App\Support;

/**
 * Componente afectado de un reporte u orden (opcional). Lista corta y fija
 * para que el análisis de fallas agrupe bien; "Otro" para lo demás.
 */
class ElevatorComponents
{
    public const LIST = [
        'doors' => 'Puertas y operador',
        'controller' => 'Maniobra / controlador',
        'motor' => 'Motor y máquina',
        'brakes' => 'Frenos',
        'ropes' => 'Cables y poleas',
        'buttons' => 'Botonera y señalización',
        'cabin' => 'Cabina e iluminación',
        'hydraulic' => 'Sistema hidráulico',
        'safety' => 'Seguridad (paracaídas, limitador)',
        'other' => 'Otro',
    ];

    public static function label(?string $key): string
    {
        return $key ? (self::LIST[$key] ?? $key) : 'Sin clasificar';
    }
}
