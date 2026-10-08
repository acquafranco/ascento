<?php

namespace App\Support;

/**
 * Teléfonos celulares argentinos.
 *
 * Se guardan como 549 + 10 dígitos (código de área + número, sin 0 ni 15),
 * que es lo que ya guardaba la app. Acepta lo que escribe la gente:
 * "11 2345-6789", "011 15 2345-6789", "+54 9 351 123-4567", "(0294) 15 412-3456".
 */
class PhoneNumber
{
    /** Número normalizado (549XXXXXXXXXX) o null si no es un celular argentino válido. */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        // Prefijos internacionales: 00 54 / 54 / 549.
        $digits = preg_replace('/^00/', '', $digits);

        if (str_starts_with($digits, '54')) {
            $digits = substr($digits, 2);
            $digits = str_starts_with($digits, '9') && strlen($digits) === 11 ? substr($digits, 1) : $digits;
        }

        // 0 de larga distancia.
        $digits = ltrim($digits, '0');

        // "15" de celular después del código de área (2, 3 o 4 dígitos).
        if (strlen($digits) === 12) {
            foreach ([2, 3, 4] as $areaLength) {
                if (substr($digits, $areaLength, 2) === '15') {
                    $digits = substr($digits, 0, $areaLength).substr($digits, $areaLength + 2);
                    break;
                }
            }
        }

        // Código de área + número = 10 dígitos. Los códigos de área empiezan
        // con 11, 2 o 3 (así "15 2345-6789", sin área, no pasa).
        if (strlen($digits) !== 10 || ! preg_match('/^(11|2|3)/', $digits)) {
            return null;
        }

        return '549'.$digits;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** Para mostrar: "+54 9 11 2345-6789". Lo que no se reconoce se muestra tal cual. */
    public static function format(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        if (! preg_match('/^549(\d{10})$/', $stored, $match)) {
            return $stored;
        }

        $national = $match[1];

        // CABA / GBA (11): 11 XXXX-XXXX. Resto: área de 3 dígitos (la mayoría).
        [$area, $local] = str_starts_with($national, '11')
            ? [substr($national, 0, 2), substr($national, 2)]
            : [substr($national, 0, 3), substr($national, 3)];

        return '+54 9 '.$area.' '.substr($local, 0, -4).'-'.substr($local, -4);
    }
}
