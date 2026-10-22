<?php

namespace App\Support\Marketplace;

/**
 * Listas fijas del marketplace de repuestos: rubros, disponibilidad y motivos
 * de reporte. Las claves se guardan en la base; los textos se muestran.
 */
final class Catalog
{
    public const CATEGORIES = [
        'machines' => 'Máquinas y motores',
        'controllers' => 'Controladores y placas',
        'doors' => 'Puertas y operadores',
        'ropes' => 'Cables, poleas y suspensión',
        'safety' => 'Seguridad (paracaídas, limitadores, frenos)',
        'buttons' => 'Botoneras e indicadores',
        'electrical' => 'Componentes eléctricos',
        'guides' => 'Guías, zapatas y rodaderas',
        'hydraulic' => 'Hidráulica',
        'maintenance' => 'Lubricación e insumos',
        'tools' => 'Herramientas',
        'other' => 'Otros',
    ];

    public const AVAILABILITY = [
        'in_stock' => 'En stock',
        'to_order' => 'A pedido',
        'unavailable' => 'Sin disponibilidad',
    ];

    public const PRICE_MODES = [
        'fixed' => 'Precio publicado',
        'quote' => 'Precio a cotizar',
    ];

    public const REPORT_REASONS = [
        'wrong_info' => 'Información incorrecta o engañosa',
        'prohibited' => 'Producto no permitido o inseguro',
        'fraud' => 'Posible fraude',
        'duplicate' => 'Publicación repetida',
        'other' => 'Otro motivo',
    ];

    /** Fotos por producto y tamaño máximo de cada una (KB). */
    public const MAX_PHOTOS = 6;

    public const MAX_PHOTO_KB = 8192;

    /** Ítems distintos y cantidad máxima por ítem en un pedido. */
    public const MAX_ITEMS = 40;

    public const MAX_QUANTITY = 9999;

    /** Productos que se comparan a la vez. */
    public const MAX_COMPARE = 4;

    public static function category(?string $key): string
    {
        return self::CATEGORIES[$key] ?? 'Otros';
    }

    public static function availability(?string $key): string
    {
        return self::AVAILABILITY[$key] ?? '—';
    }
}
