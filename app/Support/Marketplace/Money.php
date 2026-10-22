<?php

namespace App\Support\Marketplace;

/**
 * Importes del marketplace siempre con su moneda explícita (código ISO): un
 * precio de un proveedor de Chile nunca se lee como pesos argentinos.
 *
 *   ARS 489.000 · CLP 120.000 · USD 15,50
 */
final class Money
{
    /** Monedas que no usan centavos. */
    private const NO_CENTS = ['CLP', 'PYG'];

    public static function format(?float $amount, ?string $currency = 'ARS'): string
    {
        if ($amount === null) {
            return '—';
        }
        $currency = strtoupper($currency ?: 'ARS');
        $decimals = in_array($currency, self::NO_CENTS, true) || fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return $currency.' '.number_format($amount, $decimals, ',', '.');
    }
}
