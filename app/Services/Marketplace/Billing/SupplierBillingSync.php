<?php

namespace App\Services\Marketplace\Billing;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\SupplierPayment;
use App\Models\Marketplace\SupplierSubscription;
use App\Services\Marketplace\FeaturedPlacements;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Services\MercadoPagoService;
use App\Support\Marketplace\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ÚNICO punto que traduce lo que informa Mercado Pago sobre los cobros a
 * PROVEEDORES (suscripción y destacados). Aparte de MercadoPagoSubscriptionSync
 * (empresas): no comparten filas ni referencias.
 *
 * Regla de oro (la misma que para las empresas): nada del navegador ni del
 * body de un webhook cambia un estado. Con el id se consulta la API y se
 * valida contra lo que Ascento creó: referencia externa, importe y moneda.
 *
 * Idempotencia: cada cobro es una fila con id único de la pasarela; un aviso
 * repetido o fuera de orden no suma dos veces ni retrocede un cobro aprobado.
 */
class SupplierBillingSync
{
    public const SUBSCRIPTION_PREFIX = 'ascento-supplier-';

    public const FEATURED_PREFIX = 'ascento-featured-';

    public function __construct(private MercadoPagoService $mercadoPago, private MarketplaceNotifier $notify) {}

    public static function subscriptionReference(int $supplierId): string
    {
        return self::SUBSCRIPTION_PREFIX.$supplierId;
    }

    public static function featuredReference(int $placementId): string
    {
        return self::FEATURED_PREFIX.$placementId;
    }

    /*
    |--------------------------------------------------------------------------
    | SUSCRIPCIÓN (preapproval + cuotas)
    |--------------------------------------------------------------------------
    */

    public function syncPreapproval(string $preapprovalId): string
    {
        $subscription = $this->findSubscription($preapprovalId);
        if (! $subscription) {
            return 'subscription_not_found';
        }

        $data = $this->mercadoPago->getPreapproval($preapprovalId);

        if ((string) ($data['external_reference'] ?? '') !== self::subscriptionReference($subscription->supplier_id)) {
            Log::critical('Mercado Pago: preapproval de proveedor con referencia distinta', ['supplier_subscription_id' => $subscription->id]);

            return 'reference_mismatch';
        }
        if (! $this->amountMatches((float) $subscription->amount, $subscription->currency, data_get($data, 'auto_recurring.transaction_amount'), data_get($data, 'auto_recurring.currency_id'))) {
            $this->incident('Premium de proveedor con importe distinto', 'La suscripción Premium #'.$subscription->id.' informa un importe distinto al creado por Ascento.', 'sub-amount:'.$subscription->id);

            return 'amount_mismatch';
        }

        $previous = null;
        $status = DB::transaction(function () use ($subscription, $data, &$previous) {
            $row = SupplierSubscription::lockForUpdate()->find($subscription->id);
            $previous = $row->status;

            $status = match ($data['status'] ?? null) {
                'authorized' => SupplierSubscription::AUTHORIZED,
                'paused' => SupplierSubscription::PAUSED,
                'cancelled', 'canceled' => SupplierSubscription::CANCELED,
                default => SupplierSubscription::PENDING,
            };
            // past_due lo deciden los cobros: la pasarela sigue en «authorized» mientras reintenta.
            if ($status === SupplierSubscription::AUTHORIZED && $row->status === SupplierSubscription::PAST_DUE) {
                $status = SupplierSubscription::PAST_DUE;
            }
            // Una cancelada no vuelve atrás por un aviso viejo.
            if ($row->status === SupplierSubscription::CANCELED) {
                $status = SupplierSubscription::CANCELED;
            }

            $row->forceFill([
                'status' => $status,
                'authorized_at' => $status === SupplierSubscription::AUTHORIZED ? ($row->authorized_at ?? now()) : $row->authorized_at,
                'next_payment_at' => $this->date($data['next_payment_date'] ?? null) ?? $row->next_payment_at,
                'canceled_at' => $status === SupplierSubscription::CANCELED ? ($row->canceled_at ?? now()) : null,
                'checkout_url' => $status === SupplierSubscription::PENDING ? $row->checkout_url : null,
                'last_synced_at' => now(),
            ])->save();

            return $status;
        });

        if ($status !== $previous && $status === SupplierSubscription::CANCELED) {
            $subscription->refresh();
            $until = $subscription->current_period_end?->isFuture() ? ' Premium sigue hasta el '.$subscription->current_period_end->format('d/m/Y').'; después volvés al plan gratuito (podés elegir qué productos conservar publicados).' : '';
            $this->notify->supplier($subscription->supplier_id, 'Premium cancelado', 'Tu suscripción Premium quedó cancelada: no se te va a volver a cobrar.'.$until, '/proveedores/suscripcion', 'sub-canceled:'.$subscription->id.':'.$subscription->canceled_at?->timestamp);
        }

        return 'preapproval_'.($data['status'] ?? 'unknown');
    }

    public function syncAuthorizedPayment(string $authorizedPaymentId): string
    {
        $authorized = $this->mercadoPago->getAuthorizedPayment($authorizedPaymentId);
        $subscription = $this->findSubscription((string) ($authorized['preapproval_id'] ?? ''));

        return $subscription ? $this->applyAuthorizedPayment($subscription, $authorized) : 'subscription_not_found';
    }

    /** Registra una cuota; si el pago real está aprobado por el importe correcto, suma un mes (una vez por cuota). */
    public function applyAuthorizedPayment(SupplierSubscription $subscription, array $authorized): string
    {
        $id = (string) ($authorized['id'] ?? '');
        if ($id === '' || (string) ($authorized['preapproval_id'] ?? '') !== $subscription->provider_subscription_id) {
            return 'ignored';
        }
        if (isset($authorized['external_reference']) && (string) $authorized['external_reference'] !== self::subscriptionReference($subscription->supplier_id)) {
            return 'reference_mismatch';
        }

        $mpPaymentId = data_get($authorized, 'payment.id');
        $payment = $mpPaymentId ? $this->mercadoPago->getPayment((string) $mpPaymentId) : [];
        $amount = $payment['transaction_amount'] ?? $authorized['transaction_amount'] ?? null;
        $currency = $payment['currency_id'] ?? $authorized['currency_id'] ?? null;
        $status = $this->paymentStatus($payment['status'] ?? data_get($authorized, 'payment.status'));
        if ($status === SupplierPayment::APPROVED && ! $this->amountMatches((float) $subscription->amount, $subscription->currency, $amount, $currency)) {
            $status = SupplierPayment::AMOUNT_MISMATCH;
        }

        [$result, $row] = DB::transaction(function () use ($subscription, $authorized, $id, $mpPaymentId, $payment, $status, $amount, $currency) {
            $sub = SupplierSubscription::lockForUpdate()->find($subscription->id);
            $row = SupplierPayment::firstOrNew(['provider_payment_id' => 'authorized:'.$id]);
            $wasApproved = $row->exists && $row->status === SupplierPayment::APPROVED;
            $wasSeen = $row->exists ? $row->status : null;

            $row->forceFill([
                'supplier_id' => $sub->supplier_id,
                'kind' => 'subscription',
                'supplier_subscription_id' => $sub->id,
                'provider' => 'mercadopago',
                'mp_payment_id' => $mpPaymentId ? (string) $mpPaymentId : $row->mp_payment_id,
                // Un cobro aprobado no «vuelve atrás» por un aviso viejo.
                'status' => $wasApproved && $status === SupplierPayment::PENDING ? SupplierPayment::APPROVED : $status,
                'status_detail' => $payment['status_detail'] ?? data_get($authorized, 'payment.status_detail') ?? ($authorized['status'] ?? null),
                'amount' => $amount,
                'currency' => $currency,
            ]);

            if ($status === SupplierPayment::APPROVED && ! $wasApproved) {
                $paidAt = $this->date($payment['date_approved'] ?? null) ?? $this->date($authorized['debit_date'] ?? null) ?? now();
                // Si todavía quedan días pagos, el mes nuevo se suma al final (no se pierde ningún día).
                $start = collect([$paidAt, $sub->current_period_end])->filter()->max()->copy();
                $end = $start->copy()->addMonthNoOverflow();
                $row->forceFill(['paid_at' => $paidAt, 'period_start' => $start, 'period_end' => $end]);
                $sub->forceFill([
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'last_payment_status' => SupplierPayment::APPROVED,
                    'last_payment_at' => $paidAt,
                    'status' => in_array($sub->status, [SupplierSubscription::PAST_DUE, SupplierSubscription::PENDING], true) ? SupplierSubscription::AUTHORIZED : $sub->status,
                ]);
            } elseif ($status === SupplierPayment::REJECTED && ! $wasApproved) {
                $sub->forceFill([
                    'last_payment_status' => SupplierPayment::REJECTED,
                    'status' => $sub->status === SupplierSubscription::AUTHORIZED ? SupplierSubscription::PAST_DUE : $sub->status,
                ]);
            }

            $row->save();
            $sub->forceFill(['last_synced_at' => now()])->save();

            return [$wasSeen === $row->status ? null : $row->status, $row];
        });

        $this->afterSubscriptionPayment($subscription->fresh(), $row, $result);

        return 'payment_'.$status;
    }

    private function afterSubscriptionPayment(SupplierSubscription $subscription, SupplierPayment $payment, ?string $changedTo): void
    {
        $path = '/proveedores/suscripcion';
        match ($changedTo) {
            SupplierPayment::APPROVED => $this->notify->supplier($subscription->supplier_id, 'Cobro de Premium aprobado', 'Recibimos el pago de '.Money::format((float) $payment->amount, $payment->currency).'. Premium está al día hasta el '.$subscription->current_period_end?->format('d/m/Y').'. Si tenías productos fuera del límite, podés volver a publicarlos desde «Plan».', $path, 'pay:'.$payment->id.':approved'),
            SupplierPayment::REJECTED => $this->notify->supplier($subscription->supplier_id, 'Cobro de Premium rechazado', 'Mercado Pago no pudo cobrar Premium y va a reintentar. Si no se cobra, volvés al plan gratuito: no se borra nada, pero solo quedan publicados los productos que entran en su límite.', $path, 'pay:'.$payment->id.':rejected'),
            SupplierPayment::PENDING => $this->notify->supplier($subscription->supplier_id, 'Cobro de Premium pendiente', 'Mercado Pago está procesando el cobro de Premium. Te avisamos cuando se acredite.', $path, 'pay:'.$payment->id.':pending', mail: false),
            SupplierPayment::AMOUNT_MISMATCH => $this->incident('Cobro de proveedor con importe distinto', 'El cobro #'.$payment->id.' de la suscripción #'.$subscription->id.' no coincide con el importe esperado. No se aplicó.', 'pay-amount:'.$payment->id),
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | DESTACADOS (pago único)
    |--------------------------------------------------------------------------
    */

    /** Aviso «payment» de Mercado Pago: solo interesan los pagos de destacados. */
    public function syncPayment(string $paymentId): string
    {
        $payment = $this->mercadoPago->getPayment($paymentId);
        $reference = (string) ($payment['external_reference'] ?? '');

        if (! str_starts_with($reference, self::FEATURED_PREFIX)) {
            return 'ignored'; // cuotas de suscripciones, pagos de otra integración
        }
        $placement = FeaturedPlacement::where('external_reference', $reference)->first();

        return $placement ? $this->applyFeaturedPayment($placement, $payment) : 'placement_not_found';
    }

    public function applyFeaturedPayment(FeaturedPlacement $placement, array $payment): string
    {
        $id = (string) ($payment['id'] ?? '');
        if ($id === '' || (string) ($payment['external_reference'] ?? '') !== self::featuredReference($placement->id)) {
            return 'ignored';
        }
        $status = $this->paymentStatus($payment['status'] ?? null, refunds: true);
        if ($status === SupplierPayment::APPROVED && ! $this->amountMatches((float) $placement->amount, (string) $placement->currency, $payment['transaction_amount'] ?? null, $payment['currency_id'] ?? null)) {
            $status = SupplierPayment::AMOUNT_MISMATCH;
        }

        [$row, $changedTo] = DB::transaction(function () use ($placement, $payment, $id, $status) {
            FeaturedPlacement::whereKey($placement->id)->lockForUpdate()->first();
            $row = SupplierPayment::firstOrNew(['provider_payment_id' => 'payment:'.$id]);
            $before = $row->exists ? $row->status : null;
            $final = $before === SupplierPayment::APPROVED && $status === SupplierPayment::PENDING ? SupplierPayment::APPROVED : $status;
            $row->forceFill([
                'supplier_id' => $placement->supplier_id,
                'kind' => 'featured',
                'featured_placement_id' => $placement->id,
                'provider' => 'mercadopago',
                'mp_payment_id' => $id,
                'status' => $final,
                'status_detail' => $payment['status_detail'] ?? null,
                'amount' => $payment['transaction_amount'] ?? null,
                'currency' => $payment['currency_id'] ?? null,
                'paid_at' => $final === SupplierPayment::APPROVED ? ($row->paid_at ?? $this->date($payment['date_approved'] ?? null) ?? now()) : $row->paid_at,
            ])->save();

            return [$row, $before === $final ? null : $final];
        });

        $features = app(FeaturedPlacements::class);
        match ($changedTo) {
            SupplierPayment::APPROVED => $features->activatePaid($placement->fresh(), $row),
            SupplierPayment::REFUNDED => $features->paymentReversed($placement->fresh(), $row),
            SupplierPayment::REJECTED => $this->notify->supplier($placement->supplier_id, 'Pago del destacado rechazado', 'Mercado Pago rechazó el pago del destacado de «'.$placement->product_name.'». Podés intentarlo de nuevo desde «Destacados».', '/proveedores/destacados', 'feat-pay:'.$row->id.':rejected'),
            SupplierPayment::AMOUNT_MISMATCH => $this->incident('Pago de destacado con importe distinto', 'El pago #'.$row->id.' del destacado #'.$placement->id.' no coincide con el precio. No se activó.', 'feat-amount:'.$row->id),
            default => null,
        };

        return 'featured_'.$status;
    }

    /*
    |--------------------------------------------------------------------------
    | RECONCILIACIÓN (por si se perdió un aviso)
    |--------------------------------------------------------------------------
    */

    public function reconcileSubscription(SupplierSubscription $subscription): void
    {
        if (! $subscription->provider_subscription_id) {
            return;
        }
        $this->syncPreapproval($subscription->provider_subscription_id);
        foreach ($this->mercadoPago->searchAuthorizedPayments($subscription->provider_subscription_id)['results'] ?? [] as $authorized) {
            if (is_array($authorized)) {
                $this->applyAuthorizedPayment($subscription->fresh(), $authorized);
            }
        }
    }

    public function reconcileFeatured(FeaturedPlacement $placement): void
    {
        if (! $placement->external_reference) {
            return;
        }
        foreach ($this->mercadoPago->searchPayments($placement->external_reference)['results'] ?? [] as $payment) {
            if (is_array($payment)) {
                $this->applyFeaturedPayment($placement->fresh(), $payment);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDACIONES
    |--------------------------------------------------------------------------
    */

    private function findSubscription(string $preapprovalId): ?SupplierSubscription
    {
        return $preapprovalId === '' ? null : SupplierSubscription::where('provider', 'mercadopago')->where('provider_subscription_id', $preapprovalId)->first();
    }

    private function paymentStatus(mixed $status, bool $refunds = false): string
    {
        return match ($status) {
            'approved' => SupplierPayment::APPROVED,
            'refunded', 'charged_back' => $refunds ? SupplierPayment::REFUNDED : SupplierPayment::REJECTED,
            'rejected', 'cancelled' => SupplierPayment::REJECTED,
            default => SupplierPayment::PENDING,
        };
    }

    private function amountMatches(float $expected, string $expectedCurrency, mixed $amount, mixed $currency): bool
    {
        return $amount !== null
            && strtoupper((string) $currency) === strtoupper($expectedCurrency ?: 'ARS')
            && abs((float) $amount - $expected) < 0.01;
    }

    private function incident(string $title, string $body, string $key): void
    {
        Log::warning('Marketplace: '.$title, ['key' => $key]);
        $this->notify->team($title, $body, '/admin/marketplace/cobros', $key, mail: true);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
