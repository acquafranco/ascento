<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ÚNICO punto que traduce el estado de Mercado Pago al de Ascento.
 *
 * Regla de oro: nada de lo que llega del navegador o del body de un webhook
 * cambia el estado. Siempre se consulta la API de Mercado Pago con el id y
 * se valida contra lo que Ascento creó (empresa, importe, moneda).
 */
class MercadoPagoSubscriptionSync
{
    public function __construct(private MercadoPagoService $mercadoPago) {}

    public static function externalReference(Company $company): string
    {
        return 'ascento-company-'.$company->id;
    }

    /*
    |--------------------------------------------------------------------------
    | CHECKOUT / CANCELACIÓN (acciones del admin)
    |--------------------------------------------------------------------------
    */

    /**
     * Crea la suscripción en Mercado Pago y devuelve la URL de pago.
     * Reutiliza un checkout pendiente reciente para no duplicar suscripciones.
     */
    public function startCheckout(Company $company, User $user, SubscriptionPlan $plan, string $backUrl): string
    {
        $current = Subscription::where('company_id', $company->id)->first();

        // Los 30 días gratis no se cobran: la suscripción empieza al terminar.
        if ($company->onTrial() && (! $current || ($current->isMercadoPago() && $current->status === Subscription::PENDING))) {
            throw new RuntimeException('Tu empresa todavía está en la prueba gratis.');
        }

        if ($current?->isMercadoPago() && in_array($current->status, [Subscription::AUTHORIZED, Subscription::PAST_DUE], true)) {
            throw new RuntimeException('Tu empresa ya tiene una suscripción activa en Mercado Pago.');
        }

        if ($current?->isMercadoPago()
            && $current->status === Subscription::PENDING
            && $current->checkout_url
            && $current->plan === $plan->slug
            && $current->updated_at?->gt(now()->subDay())
        ) {
            return $current->checkout_url;
        }

        // Un checkout pendiente viejo se cancela antes de crear otro.
        if ($current?->isMercadoPago() && $current->status === Subscription::PENDING && $current->provider_subscription_id) {
            try {
                $this->mercadoPago->cancelPreapproval($current->provider_subscription_id);
            } catch (\Throwable $e) {
                Log::info('No se pudo cancelar un checkout pendiente viejo', ['error' => $e->getMessage()]);
            }
        }

        $payerEmail = config('services.mercadopago.test_payer_email') ?: ($company->email ?: $user->email);

        $response = $this->mercadoPago->createPreapproval([
            'reason' => 'Ascento - '.$plan->name,
            'external_reference' => static::externalReference($company),
            'payer_email' => $payerEmail,
            'back_url' => $backUrl,
            'status' => 'pending',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $plan->price,
                'currency_id' => $plan->currency,
            ],
        ]);

        $preapprovalId = (string) ($response['id'] ?? '');
        $initPoint = (string) ($response['init_point'] ?? '');

        if ($preapprovalId === '' || ! str_starts_with($initPoint, 'https://')) {
            throw new RuntimeException('Mercado Pago no devolvió los datos esperados al crear la suscripción.');
        }

        Subscription::updateOrCreate(
            ['company_id' => $company->id],
            [
                'provider' => 'mercadopago',
                'provider_subscription_id' => $preapprovalId,
                'provider_plan_id' => null,
                'external_reference' => static::externalReference($company),
                'payer_email' => $payerEmail,
                'checkout_url' => $initPoint,
                'plan' => $plan->slug,
                'status' => Subscription::PENDING,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'trial_ends_at' => null,
                'authorized_at' => null,
                'next_payment_at' => null,
                'canceled_at' => null,
                'cancel_at_period_end' => false,
                // current_period_end se conserva: los días ya pagados (por
                // transferencia o una suscripción anterior) no se pierden.
            ],
        );

        return $initPoint;
    }

    /**
     * Cambio de plan de una suscripción activa: actualiza el importe en
     * Mercado Pago y, solo si Mercado Pago lo acepta, el plan local. El plan
     * nuevo rige desde ya; el importe nuevo, desde el próximo cobro.
     */
    public function changePlan(Subscription $subscription, SubscriptionPlan $plan): Subscription
    {
        if (! $subscription->isMercadoPago()
            || ! $subscription->provider_subscription_id
            || ! in_array($subscription->status, [Subscription::AUTHORIZED, Subscription::PAST_DUE], true)
        ) {
            throw new RuntimeException('La suscripción no está activa en Mercado Pago.');
        }

        if ($subscription->plan === $plan->slug && abs((float) $subscription->amount - (float) $plan->price) < 0.01) {
            return $subscription;
        }

        $this->mercadoPago->updatePreapprovalAmount(
            $subscription->provider_subscription_id,
            'Ascento - '.$plan->name,
            (float) $plan->price,
            $plan->currency,
        );

        $subscription->update([
            'plan' => $plan->slug,
            'previous_amount' => $subscription->amount,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'amount_changed_at' => now(),
        ]);

        $this->syncPreapproval($subscription->provider_subscription_id);

        return $subscription->fresh();
    }

    /** Cancela en Mercado Pago; el acceso sigue hasta el fin del período pago. */
    public function cancel(Subscription $subscription): Subscription
    {
        $this->mercadoPago->cancelPreapproval($subscription->provider_subscription_id);
        $this->syncPreapproval($subscription->provider_subscription_id);

        return $subscription->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | SINCRONIZACIÓN (webhooks, retorno del checkout, reconciliación)
    |--------------------------------------------------------------------------
    */

    /**
     * @return string Resultado (para el registro del webhook).
     */
    public function syncPreapproval(string $preapprovalId): string
    {
        $data = $this->mercadoPago->getPreapproval($preapprovalId);

        $subscription = $this->findSubscription((string) ($data['id'] ?? ''));

        if (! $subscription) {
            return 'subscription_not_found';
        }

        // En el preapproval la referencia es obligatoria (la puso Ascento al crearlo).
        if (! isset($data['external_reference']) || ! $this->referenceMatches($subscription, $data['external_reference'])) {
            return 'reference_mismatch';
        }

        $amount = data_get($data, 'auto_recurring.transaction_amount');
        $currency = data_get($data, 'auto_recurring.currency_id');

        if (! $this->amountMatches($subscription, $amount, $currency)) {
            Log::warning('Mercado Pago: preapproval con importe distinto al creado por Ascento', [
                'subscription_id' => $subscription->id,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return 'amount_mismatch';
        }

        DB::transaction(function () use ($subscription, $data) {
            $subscription = Subscription::lockForUpdate()->find($subscription->id);

            $status = match ($data['status'] ?? null) {
                'authorized' => Subscription::AUTHORIZED,
                'paused' => Subscription::PAUSED,
                'cancelled', 'canceled' => Subscription::CANCELED,
                default => Subscription::PENDING,
            };

            // past_due lo deciden los cobros, no el preapproval: MP lo sigue
            // mostrando "authorized" mientras reintenta cobrar.
            if ($status === Subscription::AUTHORIZED && $subscription->status === Subscription::PAST_DUE) {
                $status = Subscription::PAST_DUE;
            }

            $subscription->fill([
                'status' => $status,
                'authorized_at' => $status === Subscription::AUTHORIZED
                    ? ($subscription->authorized_at ?? now())
                    : $subscription->authorized_at,
                'next_payment_at' => $this->date($data['next_payment_date'] ?? null) ?? $subscription->next_payment_at,
                'canceled_at' => $status === Subscription::CANCELED ? ($subscription->canceled_at ?? now()) : null,
                'cancel_at_period_end' => $status === Subscription::CANCELED,
                'checkout_url' => $status === Subscription::PENDING ? $subscription->checkout_url : null,
                'last_synced_at' => now(),
            ])->save();
        });

        return 'preapproval_'.($data['status'] ?? 'unknown');
    }

    public function syncAuthorizedPayment(string $authorizedPaymentId): string
    {
        $authorizedPayment = $this->mercadoPago->getAuthorizedPayment($authorizedPaymentId);

        $subscription = $this->findSubscription((string) ($authorizedPayment['preapproval_id'] ?? ''));

        if (! $subscription) {
            return 'subscription_not_found';
        }

        return $this->applyAuthorizedPayment($subscription, $authorizedPayment);
    }

    /**
     * Registra una cuota y, si el pago real está aprobado por el importe
     * correcto, extiende el período pago un mes (una sola vez por cuota).
     */
    public function applyAuthorizedPayment(Subscription $subscription, array $authorizedPayment): string
    {
        $authorizedPaymentId = (string) ($authorizedPayment['id'] ?? '');

        if ($authorizedPaymentId === ''
            || (string) ($authorizedPayment['preapproval_id'] ?? '') !== $subscription->provider_subscription_id
        ) {
            return 'ignored';
        }

        if (! $this->referenceMatches($subscription, $authorizedPayment['external_reference'] ?? null)) {
            return 'reference_mismatch';
        }

        // El pago real (si ya existe) es la fuente de verdad del estado e importe.
        $mpPaymentId = data_get($authorizedPayment, 'payment.id');
        $payment = $mpPaymentId ? $this->mercadoPago->getPayment((string) $mpPaymentId) : [];

        $mpStatus = $payment['status'] ?? data_get($authorizedPayment, 'payment.status');
        $amount = $payment['transaction_amount'] ?? $authorizedPayment['transaction_amount'] ?? null;
        $currency = $payment['currency_id'] ?? $authorizedPayment['currency_id'] ?? null;

        $status = match ($mpStatus) {
            'approved' => SubscriptionPayment::APPROVED,
            'rejected', 'cancelled', 'refunded', 'charged_back' => SubscriptionPayment::REJECTED,
            default => SubscriptionPayment::PENDING,
        };

        if ($status === SubscriptionPayment::APPROVED && ! $this->amountMatches($subscription, $amount, $currency)) {
            Log::warning('Mercado Pago: cobro aprobado con importe distinto al esperado', [
                'subscription_id' => $subscription->id,
                'amount' => $amount,
                'currency' => $currency,
            ]);
            $status = SubscriptionPayment::AMOUNT_MISMATCH;
        }

        DB::transaction(function () use ($subscription, $authorizedPayment, $authorizedPaymentId, $mpPaymentId, $payment, $status, $amount, $currency) {
            $subscription = Subscription::lockForUpdate()->find($subscription->id);

            $row = SubscriptionPayment::firstOrNew(['provider_payment_id' => $authorizedPaymentId]);
            $wasApproved = $row->exists && $row->status === SubscriptionPayment::APPROVED;

            $row->fill([
                'subscription_id' => $subscription->id,
                'company_id' => $subscription->company_id,
                'provider' => 'mercadopago',
                'provider_preapproval_id' => $subscription->provider_subscription_id,
                'mp_payment_id' => $mpPaymentId ? (string) $mpPaymentId : $row->mp_payment_id,
                // Un cobro ya aprobado no "vuelve atrás" por un webhook viejo.
                'status' => $wasApproved && $status === SubscriptionPayment::PENDING ? SubscriptionPayment::APPROVED : $status,
                'status_detail' => $payment['status_detail'] ?? data_get($authorizedPayment, 'payment.status_detail') ?? ($authorizedPayment['status'] ?? null),
                'amount' => $amount,
                'currency' => $currency,
            ]);

            if ($status === SubscriptionPayment::APPROVED && ! $wasApproved) {
                $paidAt = $this->date($payment['date_approved'] ?? null)
                    ?? $this->date($authorizedPayment['debit_date'] ?? null)
                    ?? now();

                // Si todavía quedan días pagos, el mes nuevo se suma al final.
                $start = $subscription->hasPaidPeriod() && $subscription->current_period_end->gt($paidAt)
                    ? $subscription->current_period_end->copy()
                    : $paidAt->copy();

                $end = $start->copy()->addMonthNoOverflow();

                $row->fill(['paid_at' => $paidAt, 'period_start' => $start, 'period_end' => $end]);

                $subscription->fill([
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'last_payment_status' => SubscriptionPayment::APPROVED,
                    'last_payment_at' => $paidAt,
                    'status' => in_array($subscription->status, [Subscription::PAST_DUE, Subscription::PENDING], true)
                        ? Subscription::AUTHORIZED
                        : $subscription->status,
                ]);
            } elseif ($status === SubscriptionPayment::REJECTED && ! $wasApproved) {
                $subscription->fill([
                    'last_payment_status' => SubscriptionPayment::REJECTED,
                    'status' => $subscription->status === Subscription::AUTHORIZED
                        ? Subscription::PAST_DUE
                        : $subscription->status,
                ]);
            }

            $row->save();
            $subscription->fill(['last_synced_at' => now()])->save();
        });

        return 'payment_'.$status;
    }

    /**
     * Reconciliación: vuelve a leer la suscripción y sus cuotas por si se
     * perdió algún webhook.
     */
    public function reconcile(Subscription $subscription): void
    {
        if (! $subscription->isMercadoPago() || ! $subscription->provider_subscription_id) {
            return;
        }

        $this->syncPreapproval($subscription->provider_subscription_id);

        $results = $this->mercadoPago->searchAuthorizedPayments($subscription->provider_subscription_id)['results'] ?? [];

        foreach ($results as $authorizedPayment) {
            if (is_array($authorizedPayment)) {
                $this->applyAuthorizedPayment($subscription->fresh(), $authorizedPayment);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDACIONES
    |--------------------------------------------------------------------------
    */

    private function findSubscription(string $preapprovalId): ?Subscription
    {
        if ($preapprovalId === '') {
            return null;
        }

        return Subscription::where('provider', 'mercadopago')
            ->where('provider_subscription_id', $preapprovalId)
            ->first();
    }

    /** La suscripción de MP tiene que ser la que Ascento creó para ESA empresa. */
    private function referenceMatches(Subscription $subscription, mixed $reference): bool
    {
        $expected = 'ascento-company-'.$subscription->company_id;

        if ($reference !== null && (string) $reference !== $expected) {
            Log::critical('Mercado Pago: external_reference no coincide con la empresa', [
                'subscription_id' => $subscription->id,
                'company_id' => $subscription->company_id,
                'received' => $reference,
            ]);

            return false;
        }

        return true;
    }

    private function amountMatches(Subscription $subscription, mixed $amount, mixed $currency): bool
    {
        if ($amount === null || $subscription->amount === null) {
            return false;
        }

        if (strtoupper((string) $currency) !== strtoupper((string) ($subscription->currency ?: 'ARS'))) {
            return false;
        }

        if (abs((float) $amount - (float) $subscription->amount) < 0.01) {
            return true;
        }

        // Recién cambió de plan: el cobro de este ciclo puede venir con el
        // importe anterior (se acepta hasta 35 días después del cambio).
        return $subscription->previous_amount !== null
            && $subscription->amount_changed_at?->gt(now()->subDays(35))
            && abs((float) $amount - (float) $subscription->previous_amount) < 0.01;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
