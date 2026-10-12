<?php

namespace App\Support;

/**
 * Zonas de las fotos de antes y después, definidas por el sistema (las
 * empresas ya no crean, renombran ni ordenan secciones).
 *
 * - Mantenimiento: las cuatro zonas que se revisan en cada visita.
 * - Orden de trabajo (reparaciones / reclamos, modernizaciones e
 *   instalaciones): el trabajo realizado y, si corresponde, la zona del
 *   equipo donde se hizo.
 * - Inspección: sin fotos de antes y después. El inspector registra sus
 *   observaciones y fotos en el reporte de la inspección.
 *
 * El código se guarda en photo_sections.code (una fila por empresa y zona,
 * creada sola); cada foto conserva además el nombre que tenía al cargarla.
 */
final class PhotoZones
{
    public const LABELS = [
        'pozo' => 'Pozo',
        'sala_maquinas' => 'Sala de máquinas',
        'techo' => 'Techo de cabina',
        'cabina' => 'Cabina y puertas',
        'trabajo' => 'Trabajo realizado',
    ];

    public const BY_TYPE = [
        'maintenance' => ['pozo', 'sala_maquinas', 'techo', 'cabina'],
        'work_order' => ['trabajo', 'pozo', 'sala_maquinas', 'techo', 'cabina'],
        'inspection' => [],
    ];

    /**
     * Nombres con los que las empresas ya tenían estas secciones (antes de
     * que fueran del sistema): sus fotos históricas pasan a esa zona.
     */
    public const LEGACY_NAMES = [
        'pozo' => 'pozo',
        'foso' => 'pozo',
        'sala de máquinas' => 'sala_maquinas',
        'sala de maquinas' => 'sala_maquinas',
        'techo' => 'techo',
        'techo de cabina' => 'techo',
        'cabina' => 'cabina',
        'cabina y puertas' => 'cabina',
        'trabajo realizado' => 'trabajo',
    ];

    /** @return array<string, string> código => nombre, en orden */
    public static function forType(?string $type): array
    {
        return collect(self::BY_TYPE[$type] ?? [])->mapWithKeys(fn (string $code) => [$code => self::LABELS[$code]])->all();
    }

    public static function allows(?string $type, ?string $code): bool
    {
        return $code !== null && in_array($code, self::BY_TYPE[$type] ?? [], true);
    }

    public static function codeForLegacyName(string $name): ?string
    {
        return self::LEGACY_NAMES[mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)))] ?? null;
    }
}
