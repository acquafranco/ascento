<?php

namespace App\Support;

/** Provincias argentinas (para el registro y los datos de empresa). */
class Provinces
{
    public const LIST = [
        'Buenos Aires', 'Ciudad Autónoma de Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
        'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
        'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe',
        'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
    ];

    public static function options(): array
    {
        return array_combine(self::LIST, self::LIST);
    }
}
