<?php

namespace App\Support;

/**
 * Países de Latinoamérica para las direcciones (empresa y edificios), con el
 * nombre que se usa para la división principal de cada uno. Código ISO 3166-1
 * alfa-2 (el mismo que usa Geoapify). Argentina es el país por defecto.
 */
final class Countries
{
    public const DEFAULT = 'AR';

    /** código => [nombre, cómo se llama la división principal] */
    public const LATAM = [
        'AR' => ['Argentina', 'Provincia'],
        'BO' => ['Bolivia', 'Departamento'],
        'BR' => ['Brasil', 'Estado'],
        'CL' => ['Chile', 'Región'],
        'CO' => ['Colombia', 'Departamento'],
        'CR' => ['Costa Rica', 'Provincia'],
        'CU' => ['Cuba', 'Provincia'],
        'EC' => ['Ecuador', 'Provincia'],
        'SV' => ['El Salvador', 'Departamento'],
        'GT' => ['Guatemala', 'Departamento'],
        'HN' => ['Honduras', 'Departamento'],
        'MX' => ['México', 'Estado'],
        'NI' => ['Nicaragua', 'Departamento'],
        'PA' => ['Panamá', 'Provincia'],
        'PY' => ['Paraguay', 'Departamento'],
        'PE' => ['Perú', 'Departamento'],
        'PR' => ['Puerto Rico', 'Municipio'],
        'DO' => ['República Dominicana', 'Provincia'],
        'UY' => ['Uruguay', 'Departamento'],
        'VE' => ['Venezuela', 'Estado'],
    ];

    /** @return array<string, string> código => nombre (orden alfabético, Argentina incluida) */
    public static function options(): array
    {
        return array_map(fn (array $c) => $c[0], self::LATAM);
    }

    /** Un código válido en mayúsculas, o el país por defecto. */
    public static function normalize(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        return array_key_exists($code, self::LATAM) ? $code : self::DEFAULT;
    }

    public static function name(?string $code): string
    {
        return self::LATAM[self::normalize($code)][0];
    }

    /** «Provincia», «Estado», «Departamento»… según el país. */
    public static function regionLabel(?string $code): string
    {
        return self::LATAM[self::normalize($code)][1];
    }

    public static function isArgentina(?string $code): bool
    {
        return self::normalize($code) === 'AR';
    }
}
