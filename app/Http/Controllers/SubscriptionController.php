<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function __construct(
        private MercadoPagoService $mercadoPago
    ) {
    }

    /**
     * Obtiene el único plan activo de Ascento.
     */
    private function getPlan(): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$plan) {
            throw new RuntimeException(
                'No hay ningún plan activo configurado para Ascento.'
            );
        }

        return $plan;
    }

    /**
     * Inicia el checkout del único plan.
     */
    public function checkout(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);
        abort_unless(
            $user->isAdmin() || $user->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        $subscriptionPlan = $this->getPlan();

        if (!$subscriptionPlan->mercadopago_plan_id) {
            throw new RuntimeException(
                'El plan de Ascento no tiene configurado el ID de Mercado Pago.'
            );
        }

        /*
         * Buscamos cualquier suscripción vigente o en proceso.
         */
        $existingSubscription = Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                'pending',
                'trialing',
                'authorized',
                'active',
                'past_due',
            ])
            ->latest('id')
            ->first();

        if ($existingSubscription) {
            throw new RuntimeException(
                'Tu empresa ya tiene una suscripción en proceso o activa.'
            );
        }

        /*
         * Eliminamos pendientes anteriores.
         */
        Subscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->delete();

        $externalReference = 'company_' . $company->id;

        DB::transaction(function () use (
            $company,
            $subscriptionPlan,
            $externalReference
        ) {
            Subscription::create([
                'company_id' => $company->id,
                'provider' => 'mercadopago',
                'provider_subscription_id' => null,
                'provider_plan_id' => $subscriptionPlan->mercadopago_plan_id,
                'external_reference' => $externalReference,
                'plan' => $subscriptionPlan->slug,
                'status' => 'pending',
                'amount' => $subscriptionPlan->price,
                'currency' => $subscriptionPlan->currency,
                'trial_ends_at' => null,
                'current_period_start' => null,
                'current_period_end' => null,
                'canceled_at' => null,
                'cancel_at_period_end' => false,
            ]);
        });

        return 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_plan_id='
            . urlencode($subscriptionPlan->mercadopago_plan_id);
    }

    /**
     * Webhook de Mercado Pago.
     */
    public function webhook(Request $request)
    {
        Log::info('MP WEBHOOK RECIBIDO', [
            'payload' => $request->all(),
            'raw' => $request->getContent(),
        ]);

        $type = $request->input('type');
        $dataId = $request->input('data.id');

        if (
            $type !== 'subscription_preapproval'
            || !$dataId
        ) {
            Log::info('MP WEBHOOK IGNORADO', [
                'type' => $type,
                'data_id' => $dataId,
            ]);

            return response()->json([
                'status' => 'ignored',
            ], 200);
        }

        /*
         * Consultamos directamente Mercado Pago.
         */
        try {
            $response = $this->mercadoPago->getSubscription(
                (string) $dataId
            );

            Log::info('MP SUSCRIPCION OBTENIDA', [
                'subscription_id' => $dataId,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            Log::error('MP ERROR OBTENIENDO SUSCRIPCION', [
                'subscription_id' => $dataId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
            ], 500);
        }

        /*
         * Obtenemos el ID del plan de Mercado Pago.
         */
        $mercadoPagoPlanId = $response['preapproval_plan_id'] ?? null;

        if (!$mercadoPagoPlanId) {
            Log::warning('MP SIN PREAPPROVAL PLAN ID', [
                'subscription_id' => $dataId,
            ]);

            return response()->json([
                'status' => 'missing_plan_id',
            ], 200);
        }

        /*
         * Buscamos la suscripción local pendiente.
         */
        $subscription = Subscription::query()
            ->where('provider', 'mercadopago')
            ->where('provider_plan_id', $mercadoPagoPlanId)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        /*
         * Si no encontramos una pending, comprobamos si ya existe
         * una suscripción con este ID de Mercado Pago.
         *
         * Esto hace que el webhook sea más seguro frente
         * a notificaciones repetidas.
         */
        if (!$subscription) {
            $subscription = Subscription::query()
                ->where(
                    'provider_subscription_id',
                    (string) $dataId
                )
                ->first();
        }

        if (!$subscription) {
            Log::warning('MP SUSCRIPCION LOCAL NO ENCONTRADA', [
                'subscription_id' => $dataId,
                'mercadopago_plan_id' => $mercadoPagoPlanId,
            ]);

            return response()->json([
                'status' => 'subscription_not_found',
            ], 200);
        }

        /*
         * Buscamos el plan local.
         */
        $plan = SubscriptionPlan::query()
            ->where(
                'mercadopago_plan_id',
                $mercadoPagoPlanId
            )
            ->first();

        /*
         * Estado de Mercado Pago.
         */
        $status = $response['status']
            ?? $subscription->status;

        /*
         * ==========================================================
         * TRIAL
         * ==========================================================
         *
         * Mercado Pago devuelve:
         *
         * auto_recurring.free_trial.frequency = 15
         * auto_recurring.free_trial.frequency_type = days
         *
         * Calculamos la fecha real de finalización del trial.
         */
        $trialEndsAt = null;

        $trialDays = data_get(
            $response,
            'auto_recurring.free_trial.frequency'
        );

        $trialType = data_get(
            $response,
            'auto_recurring.free_trial.frequency_type'
        );

        $startDate = data_get(
            $response,
            'auto_recurring.start_date'
        );

        if (
            $trialDays
            && $trialType === 'days'
            && $startDate
        ) {
            $trialEndsAt = Carbon::parse($startDate)
                ->addDays((int) $trialDays);
        }

        /*
         * ==========================================================
         * PERÍODO ACTUAL
         * ==========================================================
         */
        $currentPeriodStart = data_get(
            $response,
            'auto_recurring.start_date'
        );

        $currentPeriodEnd = data_get(
            $response,
            'next_payment_date'
        );

        /*
         * ==========================================================
         * CANCELACIÓN
         * ==========================================================
         *
         * Si Mercado Pago informa cancelado,
         * guardamos la fecha.
         */
        $canceledAt = in_array($status, [
            'cancelled',
            'canceled',
        ], true)
            ? now()
            : $subscription->canceled_at;

        /*
         * Actualizamos la suscripción.
         */
        $subscription->update([
            'provider' => 'mercadopago',

            'provider_subscription_id' => (string) $dataId,

            'provider_plan_id' => $mercadoPagoPlanId,

            'external_reference' =>
                $subscription->external_reference,

            'plan' =>
                $plan?->slug
                ?? $subscription->plan,

            'status' => $status,

            'amount' => data_get(
                $response,
                'auto_recurring.transaction_amount',
                $subscription->amount
            ),

            'currency' => data_get(
                $response,
                'auto_recurring.currency_id',
                $subscription->currency
            ),

            'trial_ends_at' => $trialEndsAt,

            'current_period_start' =>
                $currentPeriodStart
                ?? $subscription->current_period_start,

            'current_period_end' =>
                $currentPeriodEnd
                ?? $subscription->current_period_end,

            'canceled_at' => $canceledAt,
        ]);

        Log::info('MP SUSCRIPCION SINCRONIZADA', [
            'subscription_id' => $dataId,
            'company_id' => $subscription->company_id,
            'status' => $status,
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $currentPeriodStart,
            'current_period_end' => $currentPeriodEnd,
        ]);

        return response()->json([
            'status' => 'ok',
        ], 200);
    }

    /**
     * Muestra la suscripción.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $company = $user->company;

        abort_unless($company, 403);

        $subscription = Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                'pending',
                'trialing',
                'authorized',
                'active',
                'past_due',
            ])
            ->latest('id')
            ->first();

        return view(
            'subscriptions.show',
            compact('company', 'subscription')
        );
    }

    /**
     * Cancela la suscripción.
     */
    public function cancel(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        abort_unless(
            $user->isAdmin() || $user->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        $subscription = Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                'trialing',
                'authorized',
                'active',
            ])
            ->whereNotNull('provider_subscription_id')
            ->latest('id')
            ->first();

        if (!$subscription) {
            return back()->withErrors([
                'subscription' =>
                    'No hay una suscripción activa.',
            ]);
        }

        if ($subscription->cancel_at_period_end) {
            return back()->withErrors([
                'subscription' =>
                    'La cancelación ya está programada.',
            ]);
        }

        if (!$subscription->provider_subscription_id) {
            return back()->withErrors([
                'subscription' =>
                    'La suscripción todavía no tiene un ID de Mercado Pago.',
            ]);
        }

        /*
         * Cancelamos la renovación en Mercado Pago.
         */
        $this->mercadoPago->cancelSubscription(
            $subscription->provider_subscription_id
        );

        /*
         * IMPORTANTE:
         *
         * No ponemos status = cancelled inmediatamente.
         *
         * El acceso se determinará por las fechas:
         *
         * - durante el trial:
         *   trial_ends_at
         *
         * - después del trial:
         *   current_period_end
         */
        $subscription->update([
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        return back()->with(
            'success',
            'La cancelación fue programada.'
        );
    }
}
