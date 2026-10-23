<?php

namespace App\Services\Marketplace\Billing;

use App\Models\Marketplace\Supplier;
use App\Support\Marketplace\SalesReadiness;

/**
 * Qué pasarela cobra a cada proveedor, según el país REGISTRADO y validado
 * de su cuenta (nunca el idioma, la IP ni un dato del navegador). Hoy solo
 * Argentina con Mercado Pago; los demás países no tienen cobro en esta etapa.
 */
final class SupplierBilling
{
    /** @return list<string> países con pasarela */
    public static function countries(): array
    {
        return array_keys((array) config('marketplace.gateways', []));
    }

    public static function supportsCountry(?string $country): bool
    {
        return in_array(strtoupper((string) $country), self::countries(), true);
    }

    /** La pasarela del proveedor, o null si su país no tiene cobro. */
    public static function for(Supplier $supplier): ?SupplierBillingGateway
    {
        $class = config('marketplace.gateways.'.strtoupper((string) $supplier->country));

        return $class ? app($class) : null;
    }

    /**
     * ¿Se le puede empezar un cobro NUEVO hoy? País con pasarela configurada y,
     * además, todo lo necesario para confirmar el pago (SalesReadiness). Cancelar
     * o consultar un cobro existente usa solo isConfigured() de la pasarela.
     */
    public static function canCharge(Supplier $supplier): bool
    {
        return (bool) self::for($supplier)?->isConfigured() && SalesReadiness::gatewayReady();
    }
}
