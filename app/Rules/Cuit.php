<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CUIT/CUIL argentino: 11 dígitos con dígito verificador válido (módulo 11).
 * Acepta guiones o espacios ("30-71234567-1").
 */
class Cuit implements ValidationRule
{
    public static function normalize(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    public static function isValid(?string $value): bool
    {
        $digits = self::normalize($value);

        if (! preg_match('/^(20|23|24|27|30|33|34)\d{9}$/', $digits)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        $check = 11 - ($sum % 11);
        $check = match ($check) {
            11 => 0,
            10 => 9,
            default => $check,
        };

        return $check === (int) $digits[10];
    }

    public static function format(?string $value): ?string
    {
        $digits = self::normalize($value);

        return strlen($digits) === 11 ? substr($digits, 0, 2).'-'.substr($digits, 2, 8).'-'.substr($digits, 10) : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('El CUIT no es válido. Revisá los 11 números (por ejemplo 30-71234567-1).');
        }
    }
}
