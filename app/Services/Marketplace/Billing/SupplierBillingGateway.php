<?php

namespace App\Services\Marketplace\Billing;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\User;

/**
 * Pasarela de cobro de Ascento a los proveedores (suscripción y destacados).
 * Hoy hay una sola (Mercado Pago, Argentina). Otra pasarela para otros
 * países se agrega implementando esta interfaz y registrándola en
 * config('marketplace.gateways'), sin tocar el modelo de proveedores.
 *
 * Ninguna implementación activa nada desde el navegador: lo que devuelve es
 * la URL de pago; el estado lo confirma después la pasarela al servidor.
 */
interface SupplierBillingGateway
{
    /** Clave guardada en supplier_subscriptions.provider / supplier_payments.provider. */
    public function key(): string;

    public function isConfigured(): bool;

    /** URL de pago de la suscripción mensual (crea o reutiliza el checkout pendiente). */
    public function startSubscription(Supplier $supplier, User $user, float $amount, string $currency, string $backUrl): string;

    public function cancelSubscription(SupplierSubscription $subscription): void;

    /** URL de pago único de un destacado (ya creado como pending_payment). */
    public function startFeaturedCheckout(FeaturedPlacement $placement, User $user, string $backUrl): string;

    /** Vuelve a consultar a la pasarela (retorno del checkout, reconciliación). */
    public function syncSubscription(SupplierSubscription $subscription): void;

    public function syncFeatured(FeaturedPlacement $placement): void;
}
