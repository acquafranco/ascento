<?php

namespace App\Rules;

use App\Support\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Identificación fiscal de la empresa según su país.
 *
 * - Argentina: CUIT con su dígito verificador (Cuit).
 * - Otros países de Latinoamérica (RUT, RFC, NIT, RUC, CNPJ…): un control de
 *   forma (letras, números y separadores, con al menos 5 dígitos). No se
 *   verifica el dígito de cada país: Ascento no consulta registros oficiales.
 */
class TaxId implements ValidationRule
{
    public function __construct(private ?string $country) {}

    /** Cómo se guarda: el CUIT con guiones; el resto, en mayúsculas y sin espacios de más. */
    public static function format(?string $country, ?string $value): ?string
    {
        if (Countries::isArgentina($country)) {
            return Cuit::format($value);
        }

        $value = Str::upper(Str::squish((string) $value));

        return $value === '' ? null : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Countries::isArgentina($this->country)) {
            (new Cuit)->validate($attribute, $value, $fail);

            return;
        }

        $value = (string) $value;
        if (! preg_match('/^[0-9A-Za-z.\-\/ ]{5,25}$/', $value) || preg_match_all('/\d/', $value) < 5) {
            $label = Countries::taxIdLabel($this->country);
            $fail("El {$label} no parece válido. Revisalo (por ejemplo: ".Countries::taxIdExample($this->country).').');
        }
    }
}
