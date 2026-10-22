<?php

namespace App\Services\Marketplace\Billing;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\User;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Validation\ValidationException;

/**
 * Reglas para empezar o cancelar la suscripción de un proveedor. El importe
 * sale SIEMPRE de la configuración del SuperAdmin; el navegador solo pide
 * «suscribirme». Pagar no aprueba una cuenta ni levanta una suspensión.
 */
class SupplierSubscriptions
{
    /** ¿Puede iniciar el pago? Devuelve el motivo si no (o null si puede). */
    public static function blockReason(Supplier $supplier, ?MarketplaceSettings $settings = null): ?string
    {
        $settings ??= MarketplaceSettings::current();
        $standing = SupplierStanding::of($supplier, $settings);

        return match (true) {
            $supplier->closed_at !== null => 'La cuenta está cerrada.',
            $supplier->status === Supplier::SUSPENDED => 'Tu cuenta está suspendida por moderación: pagar no la reactiva. Escribinos para revisarla.',
            $supplier->status === Supplier::REJECTED => 'Tu cuenta no fue aprobada.',
            $supplier->status !== Supplier::APPROVED => 'Podés suscribirte cuando aprobemos tu cuenta.',
            ! SupplierBilling::supportsCountry($supplier->country) => 'Los cobros todavía no están disponibles en tu país.',
            ! $settings->chargesSubscription() => 'La suscripción todavía no está disponible.',
            ! SupplierBilling::canCharge($supplier) => 'Los cobros no están disponibles en este momento.',
            $standing === SupplierStanding::ACTIVE && in_array($supplier->subscription?->status, [SupplierSubscription::AUTHORIZED, SupplierSubscription::PAST_DUE], true) => 'Ya tenés una suscripción activa.',
            default => null,
        };
    }

    /** URL de pago en la pasarela. */
    public function start(Supplier $supplier, User $user, string $backUrl): string
    {
        abort_unless($user->isSupplierUser() && (int) $user->supplier_id === (int) $supplier->id, 403);
        $settings = MarketplaceSettings::current();
        if ($reason = self::blockReason($supplier, $settings)) {
            throw ValidationException::withMessages(['suscripcion' => $reason]);
        }

        return SupplierBilling::for($supplier)->startSubscription($supplier, $user, (float) $settings->plan_price, $settings->plan_currency ?: 'ARS', $backUrl);
    }

    public function cancel(Supplier $supplier, User $user): void
    {
        abort_unless(($user->isSupplierUser() && (int) $user->supplier_id === (int) $supplier->id) || $user->isSuperAdmin(), 403);
        $subscription = $supplier->subscription;
        if (! $subscription?->isBillable()) {
            throw ValidationException::withMessages(['suscripcion' => 'No hay una suscripción para cancelar.']);
        }
        $gateway = SupplierBilling::for($supplier);
        if (! $gateway?->isConfigured()) {
            throw ValidationException::withMessages(['suscripcion' => 'Los cobros no están disponibles en este momento: escribinos para cancelarla.']);
        }
        $gateway->cancelSubscription($subscription);
    }
}
