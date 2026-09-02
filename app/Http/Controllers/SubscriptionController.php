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

    private const CANCELED_STATUSES = [
        'canceled',
        'cancelled',
    ];

    public function __construct(
        private MercadoPagoService $mercadoPago
    ) {
    }

    /**
     * Inicia el checkout de una suscripción.
     *
     * Creamos el preapproval por API ANTES de redirigir: así tenemos
     * el `id` real de Mercado Pago desde el vamos, sin depender de que
     * el webhook adivine a qué empresa pertenece.
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
            /*
             * IMPORTANTE: NO mandamos preapproval_plan_id. Mercado Pago
             * exige card_token_id para crear por API una suscripción
             * CON plan asociado (tokenización de tarjeta de tu lado,
             * que no tenemos armada). El modo "sin plan asociado" sí
             * soporta el flujo de redirección y external_reference,
             * así que replicamos acá los datos de recurrencia del plan.
             */
            // Sin free_trial acá: el trial de 30 días ahora lo da la
            // app (companies.trial_ends_at), sin pedir tarjeta.
            $autoRecurring = [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $subscriptionPlan->price,
                'currency_id' => $subscriptionPlan->currency,
            ];

            // Ver nota en Subscription.php (Filament) sobre
            // MERCADOPAGO_TEST_PAYER_EMAIL.
            $payerEmail = config('services.mercadopago.test_payer_email')
                ?: ($company->billing_email ?? $user->email);

            $response = $this->mercadoPago->createSubscription([
                'reason' => $subscriptionPlan->name,
                'payer_email' => $payerEmail,
                'external_reference' => $externalReference,
                'back_url' => route('subscriptions.return'),
                'auto_recurring' => $autoRecurring,
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
     * OJO: esta ruta tiene que estar exenta de CSRF (o vivir en
     * routes/api.php) y estar dada de alta como notification_url en
     * tu aplicación de Mercado Pago. Si no, Mercado Pago nunca va a
     * poder avisarte de nada y vas a depender pura y exclusivamente
     * de la sincronización activa que agregamos en show().
     */
    public function webhook(Request $request)
    {
        // Log del payload crudo: dejalo unos días mientras confirmamos
        // el formato exacto que manda Mercado Pago, después lo podés sacar.
        Log::info('WEBHOOK MERCADO PAGO RECIBIDO', [
            'query' => $request->query(),
            'body' => $request->all(),
            'raw_query_string' => $request->server('QUERY_STRING'),
        ]);

        $type = $request->input('type') ?? $request->input('topic');
        $dataId = $this->extractDataId($request);

        if (!$dataId) {
            Log::warning('WEBHOOK MERCADO PAGO IGNORADO (data.id no reconocido)', ['type' => $type]);

            return response()->json(['status' => 'ignored'], 200);
        }

        try {
            if (in_array($type, ['subscription_preapproval', 'preapproval'], true)) {
                // La notificación YA es sobre el preapproval en sí.
                $response = $this->mercadoPago->syncSubscription((string) $dataId);
            } elseif ($type === 'subscription_authorized_payment') {
                // La notificación es sobre un COBRO puntual (alta, renovación).
                // data.id acá es el ID del pago, no del preapproval: hay que
                // ir a buscar a qué preapproval pertenece.
                $payment = $this->mercadoPago->getAuthorizedPayment((string) $dataId);

                $preapprovalId = $payment['preapproval_id'] ?? null;

                if (!$preapprovalId) {
                    Log::warning('WEBHOOK MERCADO PAGO: PAGO SIN preapproval_id', [
                        'payment_id' => $dataId,
                    ]);

                    return response()->json(['status' => 'ignored_no_preapproval'], 200);
                }

                $response = $this->mercadoPago->getSubscription((string) $preapprovalId);
                $dataId = $preapprovalId;
            } else {
                Log::warning('WEBHOOK MERCADO PAGO IGNORADO (tipo no reconocido)', [
                    'type' => $type,
                    'data_id' => $dataId,
                ]);

                return response()->json(['status' => 'ignored'], 200);
            }
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

        $this->applyMercadoPagoResponse($subscription, $response);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Muestra el estado de la suscripción.
     *
     * Sincroniza activamente contra Mercado Pago antes de renderizar,
     * así el estado se ve correcto aunque el webhook todavía no esté
     * llegando (por ejemplo, mientras terminás de configurarlo).
     */
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

        if ($subscription) {
            $subscription = $this->syncSubscriptionState($subscription);
        }

        return view('subscriptions.show', compact('company', 'subscription'));
    }

    /**
     * Ruta de retorno (back_url) a la que Mercado Pago manda al usuario
     * después del checkout. Forzamos una sincronización inmediata para
     * que, apenas vuelve, ya vea el estado real sin esperar al webhook.
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

        if ($subscription) {
            $this->syncSubscriptionState($subscription);
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
             * Mercado Pago no tiene "cancelar al final del período":
             * esto detiene la renovación YA. Localmente no marcamos
             * status = canceled porque dejamos que la empresa siga
             * usando Ascento hasta current_period_end (ya pagado).
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

    /**
     * Busca el ID del recurso notificado en todas las formas en que
     * Mercado Pago puede llegar a mandarlo:
     *
     * 1. Body JSON anidado:      {"data": {"id": "123"}}
     * 2. Query string "mangleada" por PHP: ?data.id=123 -> $_GET['data_id']
     * 3. Como último recurso, un regex contra la query string cruda,
     *    por si PHP la parseó de alguna otra forma inesperada.
     */
    protected function extractDataId(Request $request): ?string
    {
        $dataId = $request->input('data.id')
            ?? $request->query('data_id')
            ?? $request->input('id');

        if ($dataId) {
            return (string) $dataId;
        }

        $rawQueryString = (string) $request->server('QUERY_STRING');

        if (preg_match('/data\.id=([^&]+)/', $rawQueryString, $matches)) {
            return urldecode($matches[1]);
        }

        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | SINCRONIZACIÓN COMPARTIDA
    |--------------------------------------------------------------------------
    */

    /**
     * Vuelve a consultar Mercado Pago y aplica el resultado. Se usa
     * desde show(), returnFromCheckout() y potencialmente desde un
     * botón manual de "actualizar estado" en la vista.
     */
    protected function syncSubscriptionState(Subscription $subscription): Subscription
    {
        if (!$subscription->provider_subscription_id) {
            Log::info('SYNC OMITIDO: SUSCRIPCIÓN SIN provider_subscription_id', [
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
            ]);

            return $subscription;
        }

        try {
            $response = $this->mercadoPago->getSubscription($subscription->provider_subscription_id);

            Log::info('SYNC MERCADO PAGO OK', [
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
                'provider_subscription_id' => $subscription->provider_subscription_id,
                'status_antes' => $subscription->status,
                'status_mercadopago' => $response['status'] ?? null,
            ]);

            $subscription = $this->applyMercadoPagoResponse($subscription, $response);

            Log::info('SYNC MERCADO PAGO: ESTADO GUARDADO', [
                'subscription_id' => $subscription->id,
                'status_despues' => $subscription->status,
            ]);

            return $subscription;
        } catch (Throwable $e) {
            Log::error('NO SE PUDO SINCRONIZAR SUSCRIPCIÓN CON MERCADO PAGO', [
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
                'provider_subscription_id' => $subscription->provider_subscription_id,
                'error' => $e->getMessage(),
            ]);

            // Si Mercado Pago está caído o hay un problema de red,
            // seguimos mostrando el último estado local conocido en
            // vez de romper la página.
            return $subscription;
        }
    }

    /**
     * Aplica la respuesta de Mercado Pago (de getSubscription o del
     * webhook) al registro local. Único punto de verdad para el mapeo.
     */
    protected function applyMercadoPagoResponse(Subscription $subscription, array $response): Subscription
    {
        $plan = SubscriptionPlan::query()
            ->where('mercadopago_plan_id', $response['preapproval_plan_id'] ?? null)
            ->first();

        $status = $response['status'] ?? $subscription->status;

        if (in_array($status, self::CANCELED_STATUSES, true)) {
            $status = 'canceled';
        }

        // Cancelación programada: no bajamos el status todavía si
        // seguimos dentro del período ya pagado.
        if ($status === 'canceled'
            && $subscription->cancel_at_period_end
            && $subscription->current_period_end
            && $subscription->current_period_end->isFuture()
        ) {
            $status = $subscription->status;
        }

        $subscription->update([
            'provider' => 'mercadopago',
            'provider_subscription_id' => (string) ($response['id'] ?? $subscription->provider_subscription_id),
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

        return $subscription->fresh();
    }
}
