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

    /** código => [nombre, cómo se llama la división principal, identificación fiscal de empresas, ejemplo] */
    public const LATAM = [
        'AR' => ['Argentina', 'Provincia', 'CUIT', '30-71234567-1'],
        'BO' => ['Bolivia', 'Departamento', 'NIT', '1234567012'],
        'BR' => ['Brasil', 'Estado', 'CNPJ', '12.345.678/0001-90'],
        'CL' => ['Chile', 'Región', 'RUT', '76.123.456-7'],
        'CO' => ['Colombia', 'Departamento', 'NIT', '900.123.456-7'],
        'CR' => ['Costa Rica', 'Provincia', 'Cédula jurídica', '3-101-123456'],
        'CU' => ['Cuba', 'Provincia', 'NIT', '01234567890'],
        'EC' => ['Ecuador', 'Provincia', 'RUC', '1790012345001'],
        'SV' => ['El Salvador', 'Departamento', 'NIT', '0614-123456-101-2'],
        'GT' => ['Guatemala', 'Departamento', 'NIT', '1234567-8'],
        'HN' => ['Honduras', 'Departamento', 'RTN', '08011999123456'],
        'MX' => ['México', 'Estado', 'RFC', 'ABC123456T12'],
        'NI' => ['Nicaragua', 'Departamento', 'RUC', 'J0310000012345'],
        'PA' => ['Panamá', 'Provincia', 'RUC', '155612345-2-2019'],
        'PY' => ['Paraguay', 'Departamento', 'RUC', '80012345-6'],
        'PE' => ['Perú', 'Departamento', 'RUC', '20123456789'],
        'PR' => ['Puerto Rico', 'Municipio', 'EIN', '66-1234567'],
        'DO' => ['República Dominicana', 'Provincia', 'RNC', '1-01-12345-6'],
        'UY' => ['Uruguay', 'Departamento', 'RUT', '211234560018'],
        'VE' => ['Venezuela', 'Estado', 'RIF', 'J-12345678-9'],
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

    /** «CUIT», «RUT», «RFC»… según el país. */
    public static function taxIdLabel(?string $code): string
    {
        return self::LATAM[self::normalize($code)][2];
    }

    public static function taxIdExample(?string $code): string
    {
        return self::LATAM[self::normalize($code)][3];
    }

    /**
     * A quién llamar ante una emergencia, para los textos de seguridad: en
     * Argentina, el 911 (como siempre); en otro país, «el número de emergencias
     * de tu país» (no se publica un número que no esté verificado).
     */
    public static function emergencyNumber(?string $code): string
    {
        return self::isArgentina($code) ? 'al 911' : 'al número de emergencias de tu país';
    }

    public static function isArgentina(?string $code): bool
    {
        return self::normalize($code) === 'AR';
    }
}
