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

    private function getPlan(): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()
            ->where('is_active', true)
            ->whereNotNull('mercadopago_plan_id')
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
     * Crea una suscripción individual en Mercado Pago.
     *
     * IMPORTANTE:
     * No usamos el init_point del preapproval_plan.
     * Creamos un /preapproval específico para esta empresa.
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

        $plan = $this->getPlan();

        /*
         * Si ya existe una suscripción que todavía puede
         * dar acceso o está esperando completar el checkout,
         * no creamos otra.
         */
        $existing = Subscription::query()
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

        if ($existing) {
            /*
             * Si existe una pending con ID real de MP,
             * podemos intentar continuar el checkout.
             */
            if (
                $existing->status === 'pending'
                && $existing->provider_subscription_id
            ) {
                try {
                    $mpSubscription = $this->mercadoPago
                        ->getSubscription(
                            $existing->provider_subscription_id
                        );

                    $initPoint =
                        $mpSubscription['init_point']
                        ?? null;

                    if ($initPoint) {
                        return redirect()->away($initPoint);
                    }
                } catch (\Throwable $e) {
                    Log::warning(
                        'MP ERROR RECUPERANDO CHECKOUT PENDIENTE',
                        [
                            'company_id' => $company->id,
                            'subscription_id' => $existing->id,
                            'provider_subscription_id' =>
                                $existing->provider_subscription_id,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }

            throw new RuntimeException(
                'Tu empresa ya tiene una suscripción en proceso o activa.'
            );
        }

        /*
         * Eliminamos únicamente pendientes viejos que no llegaron
         * a crear una suscripción real en Mercado Pago.
         */
        Subscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->whereNull('provider_subscription_id')
            ->delete();

        $externalReference = 'company_' . $company->id;

        /*
         * Creamos primero el registro local.
         */
        $subscription = DB::transaction(
            function () use (
                $company,
                $plan,
                $externalReference
            ) {
                return Subscription::create([
                    'company_id' => $company->id,

                    'provider' => 'mercadopago',

                    'provider_subscription_id' => null,

                    'provider_plan_id' =>
                        $plan->mercadopago_plan_id,

                    'external_reference' =>
                        $externalReference,

                    'plan' =>
                        $plan->slug,

                    'status' => 'pending',

                    'amount' =>
                        $plan->price,

                    'currency' =>
                        $plan->currency,

                    'trial_ends_at' => null,

                    'current_period_start' => null,

                    'current_period_end' => null,

                    'canceled_at' => null,

                    'cancel_at_period_end' => false,
                ]);
            }
        );

        /*
         * Creamos la suscripción REAL de Mercado Pago.
         *
         * El plan_id queda asociado a esta suscripción,
         * pero el ID de la suscripción es único.
         */
        try {
            $mpSubscription = $this->mercadoPago
                ->createSubscription([
                    'preapproval_plan_id' =>
                        $plan->mercadopago_plan_id,

                    'reason' =>
                        'Suscripción Ascento - ' .
                        $company->name,

                    'external_reference' =>
                        $externalReference,

                    'back_url' =>
                        route('subscriptions.show'),

                    'status' => 'pending',
                ]);

            $providerSubscriptionId =
                $mpSubscription['id'] ?? null;

            if (!$providerSubscriptionId) {
                throw new RuntimeException(
                    'Mercado Pago no devolvió el ID de la suscripción.'
                );
            }

            $initPoint =
                $mpSubscription['init_point']
                ?? null;

            if (!$initPoint) {
                throw new RuntimeException(
                    'Mercado Pago no devolvió el init_point de la suscripción.'
                );
            }

            /*
             * Guardamos inmediatamente el ID real de Mercado Pago.
             */
            $subscription->update([
                'provider_subscription_id' =>
                    (string) $providerSubscriptionId,
            ]);

            Log::info(
                'MP SUSCRIPCION CREADA',
                [
                    'company_id' => $company->id,
                    'local_subscription_id' =>
                        $subscription->id,
                    'provider_subscription_id' =>
                        $providerSubscriptionId,
                    'provider_plan_id' =>
                        $plan->mercadopago_plan_id,
                    'external_reference' =>
                        $externalReference,
                ]
            );

            return redirect()->away($initPoint);

        } catch (\Throwable $e) {

            Log::error(
                'MP ERROR CREANDO SUSCRIPCION',
                [
                    'company_id' => $company->id,
                    'local_subscription_id' =>
                        $subscription->id,
                    'error' => $e->getMessage(),
                ]
            );

            /*
             * No dejamos una suscripción pendiente falsa.
             */
            $subscription->delete();

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

        try {
            $response = $this->mercadoPago
                ->getSubscription((string) $dataId);

        } catch (\Throwable $e) {

            Log::error(
                'MP ERROR OBTENIENDO SUSCRIPCION',
                [
                    'subscription_id' => $dataId,
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => 'error',
            ], 500);
        }

        /*
         * Datos importantes de MP.
         */
        $mercadoPagoSubscriptionId =
            (string) $dataId;

        $mercadoPagoPlanId =
            $response['preapproval_plan_id']
            ?? null;

        $externalReference =
            $response['external_reference']
            ?? null;

        $status =
            $response['status']
            ?? null;

        Log::info('MP SUSCRIPCION OBTENIDA', [
            'subscription_id' =>
                $mercadoPagoSubscriptionId,

            'status' =>
                $status,

            'payer_id' =>
                $response['payer_id'] ?? null,

            'plan_id' =>
                $mercadoPagoPlanId,

            'external_reference' =>
                $externalReference,

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
         * ==========================================================
         * BUSCAR SUSCRIPCIÓN LOCAL
         * ==========================================================
         *
         * PRIMERO Y PRINCIPALMENTE:
         * ID REAL DE MERCADO PAGO.
         */
        $subscription = Subscription::query()
            ->where(
                'provider',
                'mercadopago'
            )
            ->where(
                'provider_subscription_id',
                $mercadoPagoSubscriptionId
            )
            ->first();

        /*
         * Si por alguna razón todavía no tenemos el ID local,
         * utilizamos external_reference como respaldo.
         */
        if (
            !$subscription
            && $externalReference
        ) {
            $subscription = Subscription::query()
                ->where(
                    'provider',
                    'mercadopago'
                )
                ->where(
                    'external_reference',
                    $externalReference
                )
                ->whereIn('status', [
                    'pending',
                    'trialing',
                    'authorized',
                    'active',
                    'past_due',
                ])
                ->latest('id')
                ->first();
        }

        if (!$subscription) {

            Log::warning(
                'MP SUSCRIPCION LOCAL NO ENCONTRADA',
                [
                    'subscription_id' =>
                        $mercadoPagoSubscriptionId,

                    'mercadopago_plan_id' =>
                        $mercadoPagoPlanId,

                    'external_reference' =>
                        $externalReference,

                    'payer_id' =>
                        $response['payer_id'] ?? null,
                ]
            );

            return response()->json([
                'status' =>
                    'subscription_not_found',
            ], 200);
        }

        /*
         * Si la encontramos por external_reference pero
         * todavía no tenía el ID, lo asociamos.
         */
        if (
            !$subscription->provider_subscription_id
        ) {
            $subscription->provider_subscription_id =
                $mercadoPagoSubscriptionId;
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
                $mercadoPagoSubscriptionId,

            'provider_plan_id' =>
                $mercadoPagoPlanId
                ?? $subscription->provider_plan_id,

            'external_reference' =>
                $externalReference
                ?? $subscription->external_reference,

            'plan' =>
                $plan?->slug
                ?? $subscription->plan,

            'status' =>
                $status
                ?? $subscription->status,

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

        Log::info(
            'MP SUSCRIPCION SINCRONIZADA',
            [
                'subscription_id' =>
                    $mercadoPagoSubscriptionId,

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
            ]
        );

        return response()->json([
            'status' => 'ok',
        ], 200);
    }

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
                'pending',
                'trialing',
                'authorized',
                'active',
                'past_due',
            ])
            ->whereNotNull(
                'provider_subscription_id'
            )
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

        try {
            $this->mercadoPago
                ->cancelSubscription(
                    $subscription
                        ->provider_subscription_id
                );

        } catch (\Throwable $e) {

            Log::error(
                'MP ERROR CANCELANDO SUSCRIPCION',
                [
                    'subscription_id' =>
                        $subscription
                            ->provider_subscription_id,

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
         * No cambiamos el status.
         * El webhook de MP es quien determina el estado real.
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
