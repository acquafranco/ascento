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

    private function activeStatuses(): array
    {
        return [
            'pending',
            'trialing',
            'authorized',
            'active',
            'past_due',
            'paused',
        ];
    }

    private function syncLocalSubscription(
        Subscription $subscription,
        array $response
    ): Subscription {
        $mercadoPagoSubscriptionId =
            (string) (
                $response['id']
                ?? $subscription->provider_subscription_id
            );

        $mercadoPagoPlanId =
            $response['preapproval_plan_id']
            ?? $subscription->provider_plan_id;

        $externalReference =
            $response['external_reference']
            ?? $subscription->external_reference;

        $status =
            $response['status']
            ?? $subscription->status;

        /*
         * Normalizamos la cancelación.
         *
         * Mercado Pago utiliza "canceled".
         */
        if (
            in_array(
                $status,
                ['cancelled', 'canceled'],
                true
            )
        ) {
            $status = 'canceled';
        }

        /*
         * Plan local.
         */
        $plan = null;

        if ($mercadoPagoPlanId) {
            $plan = SubscriptionPlan::query()
                ->where(
                    'mercadopago_plan_id',
                    $mercadoPagoPlanId
                )
                ->first();
        }

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
            $trialEndsAt = Carbon::parse(
                $startDate
            )->addDays(
                (int) $trialDays
            );
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

        $isCancelled = $status === 'canceled';

        $canceledAt =
            $isCancelled
                ? (
                    $subscription->canceled_at
                    ?? now()
                )
                : $subscription->canceled_at;

        /*
         * Si Mercado Pago informa canceled,
         * la suscripción YA está cancelada.
         *
         * No la marcamos como "cancelación al final
         * del período", porque no es lo mismo.
         */
        $cancelAtPeriodEnd =
            $isCancelled
                ? false
                : $subscription->cancel_at_period_end;

        /*
         * Si la suscripción vuelve a un estado activo,
         * limpiamos una cancelación anterior.
         */
        if (!$isCancelled) {
            $canceledAt =
                $subscription->canceled_at;
        }

        /*
         * ==========================================================
         * ACTUALIZACIÓN LOCAL
         * ==========================================================
         */

        $subscription->update([
            'provider' =>
                'mercadopago',

            'provider_subscription_id' =>
                $mercadoPagoSubscriptionId,

            'provider_plan_id' =>
                $mercadoPagoPlanId,

            'external_reference' =>
                $externalReference,

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

        return $subscription->fresh();
    }

    /**
     * Crea una suscripción individual en Mercado Pago.
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
         * Buscamos cualquier suscripción que todavía
         * pueda bloquear una nueva contratación.
         */
        $existing = Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn(
                'status',
                $this->activeStatuses()
            )
            ->latest('id')
            ->first();

        if ($existing) {

            /*
             * Si está pending y ya tiene ID de MP,
             * intentamos recuperar el checkout.
             */
            if (
                $existing->status === 'pending'
                && $existing->provider_subscription_id
            ) {
                try {
                    $mpSubscription =
                        $this->mercadoPago
                            ->getSubscription(
                                $existing
                                    ->provider_subscription_id
                            );

                    /*
                     * Aprovechamos para sincronizar
                     * el registro local.
                     */
                    $this->syncLocalSubscription(
                        $existing,
                        $mpSubscription
                    );

                    $currentStatus =
                        $mpSubscription['status']
                        ?? null;

                    /*
                     * Si ya está autorizado/activo,
                     * no tiene sentido crear otra.
                     */
                    if (
                        in_array(
                            $currentStatus,
                            [
                                'authorized',
                                'active',
                                'trialing',
                                'past_due',
                            ],
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            'Tu empresa ya tiene una suscripción activa.'
                        );
                    }

                    $initPoint =
                        $mpSubscription['init_point']
                        ?? null;

                    if ($initPoint) {
                        return redirect()->away(
                            $initPoint
                        );
                    }

                } catch (RuntimeException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    Log::warning(
                        'MP ERROR RECUPERANDO CHECKOUT PENDIENTE',
                        [
                            'company_id' =>
                                $company->id,

                            'subscription_id' =>
                                $existing->id,

                            'provider_subscription_id' =>
                                $existing
                                    ->provider_subscription_id,

                            'error' =>
                                $e->getMessage(),
                        ]
                    );
                }
            }

            throw new RuntimeException(
                'Tu empresa ya tiene una suscripción en proceso o activa.'
            );
        }

        /*
         * Eliminamos solamente pendientes que nunca
         * llegaron a crear una suscripción real.
         */
        Subscription::query()
            ->where(
                'company_id',
                $company->id
            )
            ->where(
                'status',
                'pending'
            )
            ->whereNull(
                'provider_subscription_id'
            )
            ->delete();

        /*
         * Esta referencia permite vincular Mercado Pago
         * con nuestra empresa.
         */
        $externalReference =
            'company_' . $company->id;

        /*
         * ==========================================================
         * CREAR REGISTRO LOCAL
         * ==========================================================
         */

        $subscription = DB::transaction(
            function () use (
                $company,
                $plan,
                $externalReference
            ) {
                return Subscription::create([
                    'company_id' =>
                        $company->id,

                    'provider' =>
                        'mercadopago',

                    'provider_subscription_id' =>
                        null,

                    'provider_plan_id' =>
                        $plan->mercadopago_plan_id,

                    'external_reference' =>
                        $externalReference,

                    'plan' =>
                        $plan->slug,

                    'status' =>
                        'pending',

                    'amount' =>
                        $plan->price,

                    'currency' =>
                        $plan->currency,

                    'trial_ends_at' =>
                        null,

                    'current_period_start' =>
                        null,

                    'current_period_end' =>
                        null,

                    'canceled_at' =>
                        null,

                    'cancel_at_period_end' =>
                        false,
                ]);
            }
        );

        /*
         * ==========================================================
         * CREAR SUSCRIPCIÓN EN MERCADO PAGO
         * ==========================================================
         */

        try {

            $mpSubscription =
                $this->mercadoPago
                    ->createSubscription([
                        'preapproval_plan_id' =>
                            $plan->mercadopago_plan_id,

                        'reason' =>
                            'Suscripción Ascento - '
                            . $company->name,

                        'external_reference' =>
                            $externalReference,

                        'back_url' =>
                            route(
                                'subscriptions.show'
                            ),

                        'status' =>
                            'pending',
                    ]);

            $providerSubscriptionId =
                $mpSubscription['id']
                ?? null;

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
             * GUARDAMOS EL ID REAL INMEDIATAMENTE.
             *
             * Esto es fundamental para que el webhook
             * pueda encontrar la suscripción.
             */
            $subscription->update([
                'provider_subscription_id' =>
                    (string) $providerSubscriptionId,
            ]);

            /*
             * Sincronizamos inmediatamente con MP.
             *
             * Así no dependemos de que el primer webhook
             * sea el encargado de llenar todos los datos.
             */
            try {
                $freshMpSubscription =
                    $this->mercadoPago
                        ->getSubscription(
                            (string) $providerSubscriptionId
                        );

                $this->syncLocalSubscription(
                    $subscription,
                    $freshMpSubscription
                );

            } catch (\Throwable $syncException) {

                /*
                 * Si falla esta sincronización secundaria,
                 * NO borramos la suscripción.
                 *
                 * Ya tenemos el ID real de MP y el webhook
                 * podrá sincronizarla después.
                 */
                Log::warning(
                    'MP ERROR SINCRONIZANDO SUSCRIPCION RECIEN CREADA',
                    [
                        'company_id' =>
                            $company->id,

                        'local_subscription_id' =>
                            $subscription->id,

                        'provider_subscription_id' =>
                            $providerSubscriptionId,

                        'error' =>
                            $syncException->getMessage(),
                    ]
                );
            }

            Log::info(
                'MP SUSCRIPCION CREADA',
                [
                    'company_id' =>
                        $company->id,

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

            return redirect()->away(
                $initPoint
            );

        } catch (\Throwable $e) {

            Log::error(
                'MP ERROR CREANDO SUSCRIPCION',
                [
                    'company_id' =>
                        $company->id,

                    'local_subscription_id' =>
                        $subscription->id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            /*
             * Solamente borramos el registro local
             * si NO tenemos una suscripción real de MP.
             */
            if (
                !$subscription
                    ->provider_subscription_id
            ) {
                $subscription->delete();
            }

            throw $e;
        }
    }

    /**
     * Webhook de Mercado Pago.
     */
    public function webhook(Request $request)
    {
        Log::info(
            'MP WEBHOOK RECIBIDO',
            [
                'payload' =>
                    $request->all(),

                'raw' =>
                    $request->getContent(),
            ]
        );

        $type =
            $request->input('type');

        $dataId =
            $request->input('data.id');

        if (
            $type !== 'subscription_preapproval'
            || !$dataId
        ) {
            Log::info(
                'MP WEBHOOK IGNORADO',
                [
                    'type' =>
                        $type,

                    'data_id' =>
                        $dataId,
                ]
            );

            return response()->json([
                'status' =>
                    'ignored',
            ], 200);
        }

        try {

            $response =
                $this->mercadoPago
                    ->getSubscription(
                        (string) $dataId
                    );

        } catch (\Throwable $e) {

            Log::error(
                'MP ERROR OBTENIENDO SUSCRIPCION',
                [
                    'subscription_id' =>
                        $dataId,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            return response()->json([
                'status' =>
                    'error',
            ], 500);
        }

        $mercadoPagoSubscriptionId =
            (string) (
                $response['id']
                ?? $dataId
            );

        $mercadoPagoPlanId =
            $response['preapproval_plan_id']
            ?? null;

        $externalReference =
            $response['external_reference']
            ?? null;

        $status =
            $response['status']
            ?? null;

        Log::info(
            'MP SUSCRIPCION OBTENIDA',
            [
                'subscription_id' =>
                    $mercadoPagoSubscriptionId,

                'status' =>
                    $status,

                'payer_id' =>
                    $response['payer_id']
                    ?? null,

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

                'next_payment_date' =>
                    $response['next_payment_date']
                    ?? null,

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
            ]
        );

        /*
         * ==========================================================
         * BUSCAR POR ID DE MERCADO PAGO
         * ==========================================================
         */

        $subscription =
            Subscription::query()
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
         * ==========================================================
         * FALLBACK POR EXTERNAL_REFERENCE
         * ==========================================================
         *
         * Incluimos también canceled/paused.
         *
         * Esto es importante para webhooks de cancelación.
         */

        if (
            !$subscription
            && $externalReference
        ) {
            $subscription =
                Subscription::query()
                    ->where(
                        'provider',
                        'mercadopago'
                    )
                    ->where(
                        'external_reference',
                        $externalReference
                    )
                    ->whereIn(
                        'status',
                        [
                            'pending',
                            'trialing',
                            'authorized',
                            'active',
                            'past_due',
                            'paused',
                            'canceled',
                            'cancelled',
                        ]
                    )
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
                        $response['payer_id']
                        ?? null,
                ]
            );

            return response()->json([
                'status' =>
                    'subscription_not_found',
            ], 200);
        }

        /*
         * Si todavía no teníamos el ID, lo asociamos.
         */
        if (
            !$subscription
                ->provider_subscription_id
        ) {
            $subscription->update([
                'provider_subscription_id' =>
                    $mercadoPagoSubscriptionId,
            ]);
        }

        /*
         * Sincronizamos todo usando una única función.
         */
        $subscription =
            $this->syncLocalSubscription(
                $subscription,
                $response
            );

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
                    $subscription->status,

                'trial_ends_at' =>
                    $subscription->trial_ends_at,

                'current_period_start' =>
                    $subscription->current_period_start,

                'current_period_end' =>
                    $subscription->current_period_end,
            ]
        );

        return response()->json([
            'status' =>
                'ok',
        ], 200);
    }

    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $company = $user->company;

        abort_unless($company, 403);

        /*
         * Mostramos también canceled para que la vista
         * pueda informar correctamente qué ocurrió.
         */
        $subscription = Subscription::query()
            ->where(
                'company_id',
                $company->id
            )
            ->whereIn(
                'status',
                [
                    'pending',
                    'trialing',
                    'authorized',
                    'active',
                    'past_due',
                    'paused',
                    'canceled',
                    'cancelled',
                ]
            )
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
            $user->isAdmin()
            || $user->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        $subscription = Subscription::query()
            ->where(
                'company_id',
                $company->id
            )
            ->whereIn(
                'status',
                [
                    'pending',
                    'trialing',
                    'authorized',
                    'active',
                    'past_due',
                    'paused',
                ]
            )
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

        if (
            $subscription->status === 'canceled'
            || $subscription->status === 'cancelled'
        ) {
            return back()->withErrors([
                'subscription' =>
                    'La suscripción ya está cancelada.',
            ]);
        }

        try {

            /*
             * ======================================================
             * CANCELAMOS EN MERCADO PAGO
             * ======================================================
             */

            $cancelResponse =
                $this->mercadoPago
                    ->cancelSubscription(
                        $subscription
                            ->provider_subscription_id
                    );

            Log::info(
                'MP CANCELACION SOLICITADA',
                [
                    'company_id' =>
                        $company->id,

                    'local_subscription_id' =>
                        $subscription->id,

                    'provider_subscription_id' =>
                        $subscription
                            ->provider_subscription_id,

                    'response_status' =>
                        $cancelResponse['status']
                        ?? null,
                ]
            );

            /*
             * ======================================================
             * CONSULTAMOS EL ESTADO REAL
             * ======================================================
             *
             * No esperamos al webhook.
             *
             * Si MP ya dice canceled, Ascento queda
             * cancelado inmediatamente.
             */

            $freshMpSubscription =
                $this->mercadoPago
                    ->getSubscription(
                        $subscription
                            ->provider_subscription_id
                    );

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $freshMpSubscription
                );

            /*
             * Si MP devuelve canceled, perfecto.
             */
            if (
                $subscription->status === 'canceled'
            ) {
                return back()->with(
                    'success',
                    'La suscripción fue cancelada correctamente.'
                );
            }

            /*
             * Si todavía no figura canceled,
             * guardamos igualmente la intención.
             *
             * El webhook terminará de sincronizarla.
             */
            $subscription->update([
                'cancel_at_period_end' =>
                    false,

                'canceled_at' =>
                    $subscription->canceled_at
                    ?? now(),
            ]);

            Log::warning(
                'MP CANCELACION SOLICITADA PERO ESTADO AUN NO CANCELED',
                [
                    'company_id' =>
                        $company->id,

                    'subscription_id' =>
                        $subscription
                            ->provider_subscription_id,

                    'mp_status' =>
                        $freshMpSubscription['status']
                        ?? null,
                ]
            );

            return back()->with(
                'success',
                'La cancelación fue enviada a Mercado Pago y se está sincronizando.'
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

                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );

            return back()->withErrors([
                'subscription' =>
                    'No se pudo cancelar la suscripción en Mercado Pago. Revisá el log de producción para ver el error exacto.',
            ]);
        }
    }
}
