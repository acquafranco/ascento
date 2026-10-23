<?php

namespace App\Support\Marketplace;

use App\Models\Marketplace\Supplier;
use App\Services\Marketplace\Billing\SupplierBilling;

/**
 * ¿Se puede vender Premium o destacados a proveedores? Lo que falta, en
 * palabras y SIN valores secretos (solo el nombre de cada variable).
 *
 * - gatewayProblems(): credenciales del servidor para cobrar y confirmar
 *   pagos. Sin la clave del webhook no se podría confirmar ningún pago, así
 *   que tampoco se empieza un cobro nuevo. Cancelar o consultar un cobro que
 *   ya existe solo necesita el token (ver SupplierSubscriptions::cancel).
 * - problems(): además, lo que el SuperAdmin quiere guardar en
 *   «Configuración del marketplace» (precios válidos, países con cobro y
 *   moneda compatible).
 */
final class SalesReadiness
{
    public const MAX_PRICE = 99999999;

    /** @return list<string> */
    public static function gatewayProblems(): array
    {
        $problems = [];
        $token = (string) config('services.mercadopago.access_token');
        if ($token === '') {
            $problems[] = 'Falta MERCADOPAGO_ACCESS_TOKEN (credencial de producción de Mercado Pago).';
        } elseif (app()->isProduction() && str_starts_with($token, 'TEST-')) {
            $problems[] = 'MERCADOPAGO_ACCESS_TOKEN es una credencial de prueba (TEST-) y la aplicación está en producción.';
        }
        if (blank(config('services.mercadopago.webhook_secret'))) {
            $problems[] = 'Falta MERCADOPAGO_WEBHOOK_SECRET: sin la clave secreta del webhook no se pueden confirmar los pagos.';
        }

        return $problems;
    }

    public static function gatewayReady(): bool
    {
        return self::gatewayProblems() === [];
    }

    /**
     * Lo que impide guardar la configuración propuesta con alguna venta encendida.
     *
     * @param  array<string, mixed>  $data  estado del formulario
     * @return list<string>
     */
    public static function problems(array $data, string $currency): array
    {
        $premium = (bool) ($data['premium_enabled'] ?? false);
        $featured = (bool) ($data['featured_enabled'] ?? false);
        $problems = [];

        if ($premium && ! self::validPrice($data['premium_price'] ?? null)) {
            $problems[] = 'Para vender Premium, cargá un precio mensual mayor a cero.';
        }
        if ($featured) {
            $prices = array_filter([7 => $data['featured_price_7'] ?? null, 30 => $data['featured_price_30'] ?? null], 'filled');
            if ($prices === []) {
                $problems[] = 'Para vender destacados, cargá al menos un precio.';
            }
            foreach ($prices as $days => $price) {
                if (! self::validPrice($price)) {
                    $problems[] = 'El precio del destacado por '.$days.' días no es válido.';
                }
            }
        }
        if (! $premium && ! $featured) {
            return $problems;
        }

        $currency = strtoupper($currency);
        if (! in_array($currency, (array) config('marketplace.currencies', []), true)) {
            $problems[] = 'La moneda de los precios ('.$currency.') no es la de ningún país registrado.';
        }
        $countries = array_map('strtoupper', (array) ($data['billing_countries'] ?? []));
        foreach ($countries as $country) {
            if (! SupplierBilling::supportsCountry($country)) {
                $problems[] = $country.' no tiene una pasarela de cobro.';
            } elseif (Supplier::currencyFor($country) !== $currency) {
                $problems[] = $country.' usa '.Supplier::currencyFor($country).', no '.$currency.'.';
            }
        }
        if ($countries === []) {
            $problems[] = 'Elegí al menos un país con cobros habilitados.';
        }

        return array_merge($problems, self::gatewayProblems());
    }

    private static function validPrice(mixed $price): bool
    {
        return is_numeric($price) && (float) $price >= 0.01 && (float) $price <= self::MAX_PRICE
            && round((float) $price, 2) === (float) $price;
    }
}
