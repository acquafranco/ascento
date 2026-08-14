<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function __construct(
        private MercadoPagoService $mercadoPago
    ) {
    }

    /**
     * Inicia el checkout del único plan de Ascento.
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

        /*
         * Ascento tiene UN SOLO plan activo.
         */
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

        /*
         * Si ya existe una suscripción activa,
         * no permitimos contratar otra.
         */
        $activeSubscription = $this->getActiveSubscription($company->id);

        if ($activeSubscription) {
            return back()->withErrors([
                'subscription' => 'Tu empresa ya tiene una suscripción activa.',
            ]);
        }

        /*
         * Eliminamos pendientes anteriores.
         */
        Subscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->delete();

        /*
         * Creamos la suscripción pendiente.
         *
         * Los 15 días de prueba los determina Mercado Pago.
         * El webhook posteriormente guarda las fechas reales.
         */
        $subscription = Subscription::create([
            'company_id' => $company->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => null,
            'provider_plan_id' => $plan->mercadopago_plan_id,
            'external_reference' => 'company_' . $company->id,
            'plan' => $plan->slug,
            'status' => 'pending',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'trial_ends_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'canceled_at' => null,
            'cancel_at_period_end' => false,
        ]);

        /*
         * Obtenemos el checkout de Mercado Pago.
         */
        $mercadoPagoPlan = $this->mercadoPago->getSubscriptionPlan(
            (string) $plan->mercadopago_plan_id
        );

        $initPoint = $mercadoPagoPlan['init_point'] ?? null;

        if (!$initPoint) {
            $subscription->delete();

            throw new RuntimeException(
                'Mercado Pago no devolvió el checkout del plan.'
            );
        }

        return redirect()->away($initPoint);
    }

    /**
     * Webhook de Mercado Pago.
     */
    public function webhook(Request $request)
    {
        \Log::info('MP WEBHOOK RECIBIDO', [
            'payload' => $request->all(),
            'raw' => $request->getContent(),
        ]);

        $type = $request->input('type');
        $dataId = $request->input('data.id');

        /*
         * Solamente procesamos eventos de suscripciones.
         */
        if (
            $type !== 'subscription_preapproval' ||
            !$dataId
        ) {
            \Log::info('MP WEBHOOK IGNORADO', [
                'type' => $type,
                'data_id' => $dataId,
            ]);

            return response()->json([
                'status' => 'ignored',
            ]);
        }

        try {
            $response = $this->mercadoPago->getSubscription(
                (string) $dataId
            );
        } catch (\Throwable $e) {

            \Log::error('MP ERROR OBTENIENDO SUSCRIPCION', [
                'subscription_id' => $dataId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
            ], 500);
        }

        \Log::info('MP SUSCRIPCION OBTENIDA', [
            'subscription_id' => $dataId,
            'response' => $response,
        ]);

        /*
         * ID del plan de Mercado Pago.
         */
        $mercadoPagoPlanId =
            $response['preapproval_plan_id'] ?? null;

        if (!$mercadoPagoPlanId) {
            \Log::warning('MP SIN PLAN ID', [
                'subscription_id' => $dataId,
            ]);

            return response()->json([
                'status' => 'missing_plan_id',
            ]);
        }

        /*
         * Buscamos nuestro único plan.
         */
        $plan = SubscriptionPlan::query()
            ->where(
                'mercadopago_plan_id',
                $mercadoPagoPlanId
            )
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            \Log::warning(
                'PLAN LOCAL NO ENCONTRADO',
                [
                    'subscription_id' => $dataId,
                    'mercadopago_plan_id' => $mercadoPagoPlanId,
                ]
            );

            return response()->json([
                'status' => 'plan_not_found',
            ]);
        }

        /*
         * Primero intentamos encontrar una suscripción pendiente.
         */
        $subscription = Subscription::query()
            ->where('provider', 'mercadopago')
            ->where('provider_plan_id', $mercadoPagoPlanId)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        /*
         * Si no encontramos pending, puede ser un webhook
         * repetido de una suscripción que ya sincronizamos.
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

            \Log::warning(
                'SUSCRIPCION LOCAL NO ENCONTRADA',
                [
                    'subscription_id' => $dataId,
                    'mercadopago_plan_id' => $mercadoPagoPlanId,
                ]
            );

            return response()->json([
                'status' => 'subscription_not_found',
            ]);
        }

        /*
         * Estado que devuelve Mercado Pago.
         *
         * Puede ser:
         * authorized
         * paused
         * cancelled
         */
        $status = $response['status']
            ?? $subscription->status;

        /*
         * Fechas reales de Mercado Pago.
         */
        $periodStart = data_get(
            $response,
            'auto_recurring.start_date'
        );

        $periodEnd = data_get(
            $response,
            'next_payment_date'
        );

        /*
         * Mercado Pago informa el trial
         * mediante first_invoice_offset.
         *
         * En nuestro plan son 15 días.
         */
        $trialDays = data_get(
            $response,
            'auto_recurring.free_trial.frequency'
        );

        $trialEndsAt = null;

        if (
            $periodStart &&
            $trialDays
        ) {
            $trialEndsAt = \Carbon\Carbon::parse(
                $periodStart
            )->addDays((int) $trialDays);
        }

        /*
         * Si Mercado Pago ya nos dice que está cancelada,
         * registramos la cancelación.
         */
        $isCancelled = in_array(
            $status,
            [
                'cancelled',
                'canceled',
            ],
            true
        );

        $subscription->update([
            'provider' => 'mercadopago',

            'provider_subscription_id' =>
                (string) $dataId,

            'provider_plan_id' =>
                $mercadoPagoPlanId,

            'plan' =>
                $plan->slug,

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
                $periodStart,

            'current_period_end' =>
                $periodEnd,

            'canceled_at' =>
                $isCancelled
                    ? ($subscription->canceled_at ?? now())
                    : $subscription->canceled_at,
        ]);

        \Log::info(
            'MP SUSCRIPCION SINCRONIZADA',
            [
                'subscription_id' => $dataId,
                'company_id' => $subscription->company_id,
                'status' => $status,
                'trial_ends_at' => $trialEndsAt,
                'current_period_end' => $periodEnd,
            ]
        );

        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * Mostrar suscripción.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $company = $user->company;

        abort_unless($company, 403);

        $subscription = $this->getActiveSubscription(
            $company->id
        );

        return view(
            'subscriptions.show',
            compact(
                'company',
                'subscription'
            )
        );
    }

    /**
     * Cancelar suscripción.
     *
     * Si todavía está en período de prueba:
     * termina el acceso al finalizar el trial.
     *
     * Si ya pasó el trial:
     * mantiene acceso hasta current_period_end.
     */
    public function cancel(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        abort_unless(
            $user->isAdmin() ||
            $user->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        $subscription = $this->getActiveSubscription(
            $company->id
        );

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
                    'La suscripción todavía no tiene ID de Mercado Pago.',
            ]);
        }

        /*
         * Le pedimos a Mercado Pago que cancele
         * la renovación.
         */
        $this->mercadoPago->cancelSubscription(
            $subscription->provider_subscription_id
        );

        /*
         * Determinamos cuándo debe terminar el acceso.
         *
         * Durante trial:
         *      trial_ends_at
         *
         * Después del trial:
         *      current_period_end
         */
        $accessUntil = $subscription->trial_ends_at
            && now()->lt($subscription->trial_ends_at)
                ? $subscription->trial_ends_at
                : $subscription->current_period_end;

        /*
         * Guardamos la cancelación.
         */
        $subscription->update([
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        return back()->with(
            'success',
            'La suscripción fue cancelada. '
            . (
                $accessUntil
                    ? 'Vas a poder seguir usando Ascento hasta '
                        . $accessUntil->format('d/m/Y H:i')
                        . '.'
                    : 'El acceso finalizará cuando termine el período actual.'
            )
        );
    }

    /**
     * Obtiene la suscripción activa de una empresa.
     */
    private function getActiveSubscription(
        int $companyId
    ): ?Subscription {

        return Subscription::query()
            ->where(
                'company_id',
                $companyId
            )
            ->whereIn(
                'status',
                [
                    'authorized',
                    'active',
                    'trialing',
                ]
            )
            ->whereNotNull(
                'provider_subscription_id'
            )
            ->latest('id')
            ->first();
    }
}
