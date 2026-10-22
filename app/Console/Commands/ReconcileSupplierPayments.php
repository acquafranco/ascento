<?php

namespace App\Console\Commands;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\SupplierSubscription;
use App\Services\Marketplace\Billing\SupplierBillingSync;
use App\Services\MercadoPagoService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconciliación de los cobros a proveedores con Mercado Pago, por si se
 * perdió un webhook: suscripciones vigentes y destacados con pago pendiente.
 * Usa la misma sincronización que los webhooks (idempotente).
 */
class ReconcileSupplierPayments extends Command
{
    protected $signature = 'marketplace:reconcile-payments';

    protected $description = 'Reconcilia con Mercado Pago las suscripciones y destacados de los proveedores';

    public function handle(SupplierBillingSync $sync): int
    {
        if (! MercadoPagoService::isConfigured()) {
            $this->warn('MERCADOPAGO_ACCESS_TOKEN no está configurado: nada que reconciliar.');

            return self::SUCCESS;
        }

        $ok = $failed = 0;
        $subscriptions = SupplierSubscription::where('provider', 'mercadopago')->whereNotNull('provider_subscription_id')
            ->where(fn ($q) => $q->where('status', '!=', SupplierSubscription::CANCELED)->orWhere('canceled_at', '>', now()->subDays(40)))->get();
        foreach ($subscriptions as $subscription) {
            try {
                $sync->reconcileSubscription($subscription);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn('Suscripción de proveedor #'.$subscription->id.': '.$e::class);
            }
        }

        $placements = FeaturedPlacement::whereIn('status', [FeaturedPlacement::PENDING_PAYMENT, FeaturedPlacement::CANCELLED])
            ->whereNotNull('external_reference')->where('created_at', '>', now()->subDays(3))->get();
        foreach ($placements as $placement) {
            try {
                $sync->reconcileFeatured($placement);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn('Destacado #'.$placement->id.': '.$e::class);
            }
        }

        $this->info("Reconciliados: {$ok}. Con error: {$failed}.");

        return self::SUCCESS;
    }
}
