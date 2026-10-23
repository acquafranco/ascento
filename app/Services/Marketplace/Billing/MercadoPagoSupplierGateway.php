<?php

namespace App\Services\Marketplace\Billing;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mercado Pago (Argentina) para los cobros a proveedores. Reutiliza la misma
 * cuenta, el mismo cliente HTTP (MercadoPagoService) y el mismo webhook que
 * las suscripciones de las empresas, con referencias propias
 * («ascento-supplier-…», «ascento-featured-…») y tablas propias.
 *
 * - Suscripción: preapproval sin plan en estado «pending» (igual que las
 *   empresas): la persona carga su medio de pago en Mercado Pago.
 * - Destacado: pago único con Checkout Pro (preferencia que vence en 24 h).
 * Nunca se guardan tarjetas: todo se carga en Mercado Pago.
 */
class MercadoPagoSupplierGateway implements SupplierBillingGateway
{
    public function key(): string
    {
        return 'mercadopago';
    }

    public function isConfigured(): bool
    {
        return MercadoPagoService::isConfigured();
    }

    private function api(): MercadoPagoService
    {
        return app(MercadoPagoService::class);
    }

    private function payerEmail(Supplier $supplier, User $user): string
    {
        return (string) (config('services.mercadopago.test_payer_email') ?: ($user->email ?: $supplier->contact_email));
    }

    public function startSubscription(Supplier $supplier, User $user, float $amount, string $currency, string $backUrl): string
    {
        $current = SupplierSubscription::where('supplier_id', $supplier->id)->first();

        if ($current && in_array($current->status, [SupplierSubscription::AUTHORIZED, SupplierSubscription::PAST_DUE], true)) {
            throw new RuntimeException('Ya tenés una suscripción activa en Mercado Pago.');
        }

        // Reutiliza un checkout pendiente reciente con el mismo importe (dos clics = una suscripción).
        if ($current?->status === SupplierSubscription::PENDING && $current->checkout_url
            && abs((float) $current->amount - $amount) < 0.01 && $current->currency === $currency
            && $current->updated_at?->gt(now()->subDay())) {
            return $current->checkout_url;
        }

        // Un checkout pendiente viejo se cancela antes de crear otro.
        if ($current?->status === SupplierSubscription::PENDING && $current->provider_subscription_id) {
            try {
                $this->api()->cancelPreapproval($current->provider_subscription_id);
            } catch (\Throwable $e) {
                Log::info('No se pudo cancelar un checkout de proveedor pendiente', ['supplier_id' => $supplier->id, 'exception' => $e::class]);
            }
        }

        $reference = SupplierBillingSync::subscriptionReference($supplier->id);
        $response = $this->api()->createPreapproval([
            'reason' => 'Ascento - Marketplace de repuestos: Premium para proveedores ('.MarketplaceSettings::PREMIUM_PLAN_CODE.')',
            'external_reference' => $reference,
            'payer_email' => $this->payerEmail($supplier, $user),
            'back_url' => $backUrl,
            'status' => 'pending',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => $amount,
                'currency_id' => $currency,
            ],
        ]);

        $id = (string) ($response['id'] ?? '');
        $url = (string) ($response['init_point'] ?? '');
        if ($id === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Mercado Pago no devolvió los datos esperados al crear la suscripción.');
        }

        $row = $current ?? new SupplierSubscription(['supplier_id' => $supplier->id]);
        $row->forceFill([
            'provider' => $this->key(),
            'provider_subscription_id' => $id,
            'external_reference' => $reference,
            'payer_email' => $this->payerEmail($supplier, $user),
            'checkout_url' => $url,
            'status' => SupplierSubscription::PENDING,
            'amount' => $amount,
            'currency' => $currency,
            'authorized_at' => null,
            'next_payment_at' => null,
            'canceled_at' => null,
            // current_period_end se conserva: los días ya pagados no se pierden.
        ])->save();

        return $url;
    }

    public function cancelSubscription(SupplierSubscription $subscription): void
    {
        if (! $subscription->provider_subscription_id) {
            return;
        }
        $this->api()->cancelPreapproval($subscription->provider_subscription_id);
        app(SupplierBillingSync::class)->syncPreapproval($subscription->provider_subscription_id);
    }

    public function startFeaturedCheckout(FeaturedPlacement $placement, User $user, string $backUrl): string
    {
        $placement->loadMissing('supplier');
        $response = $this->api()->createPreference([
            'items' => [[
                'id' => 'featured-'.$placement->days,
                'title' => 'Ascento - Destacado '.$placement->days.' días: '.mb_substr($placement->product_name, 0, 120),
                'quantity' => 1,
                'unit_price' => (float) $placement->amount,
                'currency_id' => $placement->currency,
            ]],
            'external_reference' => $placement->external_reference,
            'payer' => ['email' => $this->payerEmail($placement->supplier, $user)],
            'back_urls' => ['success' => $backUrl, 'pending' => $backUrl, 'failure' => $backUrl],
            'auto_return' => 'approved',
            'expires' => true,
            'expiration_date_from' => now()->toIso8601String(),
            'expiration_date_to' => now()->addHours(FeaturedPlacement::CHECKOUT_HOURS)->toIso8601String(),
            'statement_descriptor' => 'ASCENTO',
        ]);

        $url = (string) ($response['init_point'] ?? '');
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Mercado Pago no devolvió el enlace de pago.');
        }
        $placement->forceFill(['checkout_url' => $url])->save();

        return $url;
    }

    public function syncSubscription(SupplierSubscription $subscription): void
    {
        app(SupplierBillingSync::class)->reconcileSubscription($subscription);
    }

    public function syncFeatured(FeaturedPlacement $placement): void
    {
        app(SupplierBillingSync::class)->reconcileFeatured($placement);
    }
}
