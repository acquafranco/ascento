<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SubscriptionController extends Controller
{
    /**
     * Estados que indican que ya hay algo en curso y no debe
     * iniciarse un checkout nuevo.
     */
    private const BLOCKING_STATUSES = [
        'pending',
        'trialing',
        'authorized',
        'active',
        'past_due',
        'paused',
    ];

    public function __construct(
        private MercadoPagoService $mercadoPago
    ) {
    }

    /**
     * Inicia el checkout de una suscripción.
     *
     * IMPORTANTE: creamos el preapproval por API ANTES de redirigir.
     * Esto nos da el `id` real de la suscripción en Mercado Pago de
     * entrada, sin depender de que el webhook adivine a qué empresa
     * pertenece por external_reference (que el link genérico del
     * plan NO envía).
     */
    public function checkout(Request $request, string $plan)
    {
        $user = $request->user();

        abort_unless($user, 403);
        abort_unless($user->isAdmin() || $user->isSuperAdmin(), 403);

        $company = $user->company;
        abort_unless($company, 403);

        $subscriptionPlan = SubscriptionPlan::query()
            ->whereKey((int) $plan)
            ->where('is_active', true)
            ->firstOrFail();

        if (!$subscriptionPlan->mercadopago_plan_id) {
            return back()->withErrors([
                'subscription' => 'El plan seleccionado todavía no tiene configurado el ID del plan de Mercado Pago.',
            ]);
        }

        $existingSubscription = Subscription::query()
            ->where('company_id', $company->id)
            ->latest('id')
            ->first();

        if ($existingSubscription && in_array($existingSubscription->status, self::BLOCKING_STATUSES, true)) {
            $message = $existingSubscription->status === 'paused'
                ? 'Tu suscripción está pausada. Reactivala en vez de contratar una nueva.'
                : 'Tu empresa ya tiene una suscripción en proceso o activa.';

            return back()->withErrors(['subscription' => $message]);
        }

        $externalReference = 'company_' . $company->id . '_plan_' . $subscriptionPlan->id;

        try {
            $response = $this->mercadoPago->createSubscription([
                'preapproval_plan_id' => $subscriptionPlan->mercadopago_plan_id,
                // TODO: reemplazar por el email de facturación de la empresa
                // si tenés uno (ej. $company->billing_email), si no, el del
                // usuario que está contratando funciona como fallback.
                'payer_email' => $company->billing_email ?? $user->email,
                'external_reference' => $externalReference,
                'back_url' => route('subscriptions.return'),
            ]);

            $providerSubscriptionId = (string) ($response['id'] ?? '');
            $initPoint = $response['init_point'] ?? null;

            if (!$providerSubscriptionId || !$initPoint) {
                throw new RuntimeException('Mercado Pago no devolvió los datos esperados al crear la suscripción.');
            }

            DB::transaction(function () use (
                $company,
                $subscriptionPlan,
                $externalReference,
                $providerSubscriptionId,
                $response
            ) {
                Subscription::updateOrCreate(
                    ['company_id' => $company->id],
                    [
                        'provider' => 'mercadopago',
                        'provider_subscription_id' => $providerSubscriptionId,
                        'provider_plan_id' => $subscriptionPlan->mercadopago_plan_id,
                        'external_reference' => $externalReference,
                        'plan' => $subscriptionPlan->slug,
                        'status' => $response['status'] ?? 'pending',
                        'amount' => $subscriptionPlan->price,
                        'currency' => $subscriptionPlan->currency,
                        'trial_ends_at' => null,
                        'current_period_start' => null,
                        'current_period_end' => null,
                        'canceled_at' => null,
                        'cancel_at_period_end' => false,
                    ]
                );
            });

            return redirect()->away($initPoint);
        } catch (Throwable $e) {
            Log::error('ERROR INICIANDO CHECKOUT DE SUSCRIPCIÓN', [
                'company_id' => $company->id,
                'plan_id' => $subscriptionPlan->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'subscription' => 'No se pudo iniciar el pago. Intentá nuevamente en unos minutos.',
            ]);
        }
    }

    /**
     * Recibe notificaciones de Mercado Pago y sincroniza la suscripción.
     *
     * Matchea primero por provider_subscription_id (confiable, siempre
     * lo tenemos desde que checkout() crea el preapproval por API) y
     * solo si no lo encuentra cae a external_reference como respaldo.
     */
    public function webhook(Request $request)
    {
        $type = $request->input('type');
        $dataId = $request->input('data.id');

        if ($type !== 'subscription_preapproval' || !$dataId) {
            return response()->json(['status' => 'ignored'], 200);
        }

        try {
            $response = $this->mercadoPago->syncSubscription((string) $dataId);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'error'], 500);
        }

        $subscription = Subscription::query()
            ->where('provider_subscription_id', (string) $dataId)
            ->first();

        if (!$subscription) {
            $externalReference = $response['external_reference'] ?? null;

            if ($externalReference && preg_match('/^company_(\d+)_plan_(\d+)$/', (string) $externalReference, $matches)) {
                $subscription = Subscription::query()
                    ->where('company_id', (int) $matches[1])
                    ->first();
            }
        }

        if (!$subscription) {
            Log::warning('WEBHOOK MERCADO PAGO: SUSCRIPCIÓN NO ENCONTRADA', [
                'provider_subscription_id' => $dataId,
                'external_reference' => $response['external_reference'] ?? null,
            ]);

            return response()->json(['status' => 'subscription_not_found'], 200);
        }

        $plan = SubscriptionPlan::query()
            ->where('mercadopago_plan_id', $response['preapproval_plan_id'] ?? null)
            ->first();

        $status = $response['status'] ?? $subscription->status;

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            $status = 'canceled';
        }

        /*
         * Si la cancelación fue "programada" (el usuario pidió cancelar
         * pero le dejamos usar Ascento hasta current_period_end), no
         * pisamos el status todavía cuando llega la notificación de
         * cancelación de Mercado Pago: dejamos que siga reflejando el
         * último estado "usable" hasta que venza el período. Necesitás
         * un job programado que, pasado current_period_end, la pase
         * a 'canceled' definitivamente.
         */
        if ($status === 'canceled'
            && $subscription->cancel_at_period_end
            && $subscription->current_period_end
            && $subscription->current_period_end->isFuture()
        ) {
            $status = $subscription->status;
        }

        $subscription->update([
            'provider' => 'mercadopago',
            'provider_subscription_id' => (string) $dataId,
            'provider_plan_id' => $plan?->mercadopago_plan_id ?? $subscription->provider_plan_id,
            'external_reference' => $response['external_reference'] ?? $subscription->external_reference,
            'plan' => $plan?->slug ?? $subscription->plan,
            'status' => $status,
            'amount' => data_get($response, 'auto_recurring.transaction_amount', $subscription->amount),
            'currency' => data_get($response, 'auto_recurring.currency_id', $subscription->currency),
            'current_period_start' => data_get($response, 'auto_recurring.start_date', $subscription->current_period_start),
            'current_period_end' => data_get($response, 'next_payment_date', $subscription->current_period_end),
            'canceled_at' => $status === 'canceled' ? ($subscription->canceled_at ?? now()) : $subscription->canceled_at,
        ]);

        return response()->json(['status' => 'ok'], 200);
    }

    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $company = $user->company;
        abort_unless($company, 403);

        $subscription = Subscription::query()
            ->where('company_id', $company->id)
            ->latest('id')
            ->first();

        return view('subscriptions.show', compact('company', 'subscription'));
    }

    /**
     * Ruta de retorno (back_url) a la que Mercado Pago manda al usuario
     * después del checkout. No confiamos en esto para actualizar el
     * estado (eso lo hace el webhook), solo la usamos para mostrar un
     * mensaje y forzar una sincronización inmediata si es posible.
     */
    public function returnFromCheckout(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $company = $user->company;
        abort_unless($company, 403);

        $subscription = Subscription::query()
            ->where('company_id', $company->id)
            ->latest('id')
            ->first();

        if ($subscription?->provider_subscription_id) {
            try {
                $response = $this->mercadoPago->getSubscription($subscription->provider_subscription_id);

                $status = $response['status'] ?? $subscription->status;

                if (in_array($status, ['cancelled', 'canceled'], true)) {
                    $status = 'canceled';
                }

                $subscription->update([
                    'status' => $status,
                    'current_period_start' => data_get($response, 'auto_recurring.start_date', $subscription->current_period_start),
                    'current_period_end' => data_get($response, 'next_payment_date', $subscription->current_period_end),
                ]);
            } catch (Throwable $e) {
                Log::warning('NO SE PUDO SINCRONIZAR AL VOLVER DEL CHECKOUT', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()
            ->route('subscriptions.show')
            ->with('success', '¡Listo! Ya podés ver el estado de tu suscripción.');
    }

    public function cancel(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);
        abort_unless($user->isAdmin() || $user->isSuperAdmin(), 403);

        $company = $user->company;
        abort_unless($company, 403);

        $subscription = Subscription::query()
            ->where('company_id', $company->id)
            ->latest('id')
            ->first();

        if (!$subscription) {
            return back()->withErrors([
                'subscription' => 'No hay una suscripción activa.',
            ]);
        }

        if ($subscription->cancel_at_period_end) {
            return back()->withErrors([
                'subscription' => 'La cancelación ya está programada.',
            ]);
        }

        if (!$subscription->provider_subscription_id) {
            return back()->withErrors([
                'subscription' => 'La suscripción todavía no tiene un ID de Mercado Pago.',
            ]);
        }

        try {
            /*
             * Le pedimos a Mercado Pago que no continúe renovando la
             * suscripción. Esto es inmediato del lado de Mercado Pago
             * (no existe "cancelar al final del período" en su API),
             * pero localmente NO marcamos status = canceled: dejamos
             * que la empresa siga usando Ascento hasta current_period_end
             * ya que ese período fue pagado.
             */
            $this->mercadoPago->cancelSubscription(
                $subscription->provider_subscription_id
            );
        } catch (Throwable $e) {
            Log::error('ERROR CANCELANDO SUSCRIPCIÓN', [
                'company_id' => $company->id,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'subscription' => 'No se pudo cancelar la suscripción. Intentá nuevamente.',
            ]);
        }

        $subscription->update([
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        return back()->with(
            'success',
            'La cancelación fue programada. Vas a poder seguir usando Ascento hasta '
            . optional($subscription->current_period_end)->format('d/m/Y') . '.'
        );
    }

    public function changePlan(Request $request, int|string $planId)
    {
        $plan = SubscriptionPlan::query()
            ->whereKey((int) $planId)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->checkout($request, (string) $plan->getKey());
    }
}
