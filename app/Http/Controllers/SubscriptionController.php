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

        if (!$plan->mercadopago_plan_id) {
            throw new RuntimeException(
                'El plan de Ascento no tiene configurado el ID de Mercado Pago.'
            );
        }

        return $plan;
    }

    /**
     * Inicia el checkout del plan de Mercado Pago.
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

        /*
         * Buscamos una suscripción que todavía pueda dar acceso.
         *
         * IMPORTANTE:
         *
         * authorized NO significa que hubo un cobro.
         * Significa que Mercado Pago autorizó la suscripción.
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
         * Eliminamos solamente pendientes anteriores
         * de ESTA empresa.
         *
         * No tocamos suscripciones históricas.
         */
        Subscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->delete();

        $externalReference = 'company_' . $company->id;

        /*
         * Creamos el registro local ANTES del checkout.
         *
         * Esto no genera ningún cobro.
         */
        DB::transaction(function () use (
            $company,
            $subscriptionPlan,
            $externalReference
        ) {
            Subscription::create([
                'company_id' => $company->id,

                'provider' => 'mercadopago',

                'provider_subscription_id' => null,

                'provider_plan_id' =>
                    $subscriptionPlan->mercadopago_plan_id,

                'external_reference' =>
                    $externalReference,

                'plan' =>
                    $subscriptionPlan->slug,

                'status' => 'pending',

                'amount' =>
                    $subscriptionPlan->price,

                'currency' =>
                    $subscriptionPlan->currency,

                'trial_ends_at' => null,

                'current_period_start' => null,

                'current_period_end' => null,

                'canceled_at' => null,

                'cancel_at_period_end' => false,
            ]);
        });

        /*
         * IMPORTANTE:
         *
         * Usamos el init_point real del plan cuando es posible.
         *
         * De esta forma no reconstruimos manualmente
         * la URL de Mercado Pago.
         */
        try {
            $mercadoPagoPlan = $this->mercadoPago
                ->getSubscriptionPlan(
                    $subscriptionPlan->mercadopago_plan_id
                );

            $initPoint = $mercadoPagoPlan['init_point'] ?? null;

            if (!$initPoint) {
                throw new RuntimeException(
                    'Mercado Pago no devolvió el init_point del plan.'
                );
            }

            return redirect()->away($initPoint);

        } catch (\Throwable $e) {

            Log::error('MP ERROR OBTENIENDO INIT POINT', [
                'company_id' => $company->id,
                'plan_id' =>
                    $subscriptionPlan->mercadopago_plan_id,
                'error' => $e->getMessage(),
            ]);

            /*
             * Si ni siquiera pudimos obtener el checkout,
             * dejamos la suscripción pendiente para poder
             * inspeccionarla, pero informamos el error.
             */
            throw $e;
        }
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

        /*
         * Solo procesamos cambios de suscripciones.
         */
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
         * Consultamos la suscripción directamente en Mercado Pago.
         */
        try {
            $response = $this->mercadoPago->getSubscription(
                (string) $dataId
            );

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
         * Log especialmente importante para diagnosticar
         * el problema del trial.
         */
        Log::info('MP SUSCRIPCION OBTENIDA', [
            'subscription_id' => $dataId,

            'status' =>
                $response['status'] ?? null,

            'payer_id' =>
                $response['payer_id'] ?? null,

            'plan_id' =>
                $response['preapproval_plan_id'] ?? null,

            'start_date' =>
                data_get(
                    $response,
                    'auto_recurring.start_date'
                ),

            'free_trial' =>
                data_get(
                    $response,
                    'auto_recurring.free_trial'
                ),

            'first_invoice_offset' =>
                $response['first_invoice_offset'] ?? null,

            'next_payment_date' =>
                $response['next_payment_date'] ?? null,

            'charged_quantity' =>
                data_get(
                    $response,
                    'summarized.charged_quantity'
                ),

            'charged_amount' =>
                data_get(
                    $response,
                    'summarized.charged_amount'
                ),

            'last_charged_date' =>
                data_get(
                    $response,
                    'summarized.last_charged_date'
                ),

            'last_charged_amount' =>
                data_get(
                    $response,
                    'summarized.last_charged_amount'
                ),

            'payment_method_id' =>
                $response['payment_method_id'] ?? null,
        ]);

        /*
         * ID del plan.
         */
        $mercadoPagoPlanId =
            $response['preapproval_plan_id'] ?? null;

        if (!$mercadoPagoPlanId) {
            Log::warning('MP SIN PREAPPROVAL PLAN ID', [
                'subscription_id' => $dataId,
            ]);

            return response()->json([
                'status' => 'missing_plan_id',
            ], 200);
        }

        /*
         * Primero intentamos encontrar la suscripción
         * por su ID real de Mercado Pago.
         */
        $subscription = Subscription::query()
            ->where(
                'provider_subscription_id',
                (string) $dataId
            )
            ->first();

        /*
         * Si todavía no existe, buscamos una pending
         * del mismo plan.
         *
         * Como estamos usando el checkout asociado al plan,
         * Mercado Pago no nos devuelve aquí el company_id.
         *
         * Por eso tomamos la pending más reciente.
         */
        if (!$subscription) {
            $subscription = Subscription::query()
                ->where('provider', 'mercadopago')
                ->where(
                    'provider_plan_id',
                    $mercadoPagoPlanId
                )
                ->where('status', 'pending')
                ->latest('id')
                ->first();
        }

        if (!$subscription) {

            Log::warning(
                'MP SUSCRIPCION LOCAL NO ENCONTRADA',
                [
                    'subscription_id' => $dataId,
                    'mercadopago_plan_id' =>
                        $mercadoPagoPlanId,
                    'payer_id' =>
                        $response['payer_id'] ?? null,
                ]
            );

            return response()->json([
                'status' => 'subscription_not_found',
            ], 200);
        }

        /*
         * Plan local.
         */
        $plan = SubscriptionPlan::query()
            ->where(
                'mercadopago_plan_id',
                $mercadoPagoPlanId
            )
            ->first();

        /*
         * Estado real informado por Mercado Pago.
         */
        $status =
            $response['status']
            ?? $subscription->status;

        /*
         * ==========================================================
         * TRIAL
         * ==========================================================
         */

        $trialEndsAt =
            $subscription->trial_ends_at;

        $trialDays =
            data_get(
                $response,
                'auto_recurring.free_trial.frequency'
            );

        $trialType =
            data_get(
                $response,
                'auto_recurring.free_trial.frequency_type'
            );

        $startDate =
            data_get(
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
         * PERÍODO
         * ==========================================================
         */

        $currentPeriodStart =
            $startDate
            ? Carbon::parse($startDate)
            : $subscription->current_period_start;

        $currentPeriodEnd =
            data_get(
                $response,
                'next_payment_date'
            );

        if ($currentPeriodEnd) {
            $currentPeriodEnd =
                Carbon::parse($currentPeriodEnd);
        } else {
            $currentPeriodEnd =
                $subscription->current_period_end;
        }

        /*
         * ==========================================================
         * CANCELACIÓN
         * ==========================================================
         */

        $isCancelled = in_array(
            $status,
            [
                'cancelled',
                'canceled',
            ],
            true
        );

        $canceledAt =
            $isCancelled
                ? (
                    $subscription->canceled_at
                    ?? now()
                )
                : $subscription->canceled_at;

        /*
         * Si Mercado Pago canceló realmente la suscripción,
         * la cancelación deja de ser solamente programada.
         */
        $cancelAtPeriodEnd =
            $isCancelled
                ? true
                : $subscription->cancel_at_period_end;

        /*
         * ==========================================================
         * ACTUALIZACIÓN
         * ==========================================================
         */

        $subscription->update([
            'provider' =>
                'mercadopago',

            'provider_subscription_id' =>
                (string) $dataId,

            'provider_plan_id' =>
                $mercadoPagoPlanId,

            'plan' =>
                $plan?->slug
                ?? $subscription->plan,

            'status' =>
                $status,

            'amount' =>
                data_get(
                    $response,
                    'auto_recurring.transaction_amount',
                    $subscription->amount
                ),

            'currency' =>
                data_get(
                    $response,
                    'auto_recurring.currency_id',
                    $subscription->currency
                ),

            'trial_ends_at' =>
                $trialEndsAt,

            'current_period_start' =>
                $currentPeriodStart,

            'current_period_end' =>
                $currentPeriodEnd,

            'canceled_at' =>
                $canceledAt,

            'cancel_at_period_end' =>
                $cancelAtPeriodEnd,
        ]);

        /*
         * Log final de sincronización.
         */
        Log::info('MP SUSCRIPCION SINCRONIZADA', [
            'subscription_id' =>
                $dataId,

            'local_subscription_id' =>
                $subscription->id,

            'company_id' =>
                $subscription->company_id,

            'status' =>
                $status,

            'trial_ends_at' =>
                $trialEndsAt,

            'current_period_start' =>
                $currentPeriodStart,

            'current_period_end' =>
                $currentPeriodEnd,

            'charged_quantity' =>
                data_get(
                    $response,
                    'summarized.charged_quantity'
                ),

            'charged_amount' =>
                data_get(
                    $response,
                    'summarized.charged_amount'
                ),

            'next_payment_date' =>
                $response['next_payment_date']
                ?? null,
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
            compact(
                'company',
                'subscription'
            )
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
                'past_due',
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

        /*
         * Cancelamos la renovación en Mercado Pago.
         */
        try {
            $this->mercadoPago->cancelSubscription(
                $subscription->provider_subscription_id
            );
        } catch (\Throwable $e) {

            Log::error(
                'MP ERROR CANCELANDO SUSCRIPCION',
                [
                    'subscription_id' =>
                        $subscription->provider_subscription_id,

                    'company_id' =>
                        $company->id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            return back()->withErrors([
                'subscription' =>
                    'No se pudo cancelar la suscripción en Mercado Pago.',
            ]);
        }

        /*
         * NO cambiamos status a cancelled manualmente.
         *
         * Esperamos el webhook de Mercado Pago.
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
