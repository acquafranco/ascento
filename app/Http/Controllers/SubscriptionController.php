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
     * Inicia el checkout de una suscripción.
     *
     * La tarjeta se carga dentro de Mercado Pago. No intentamos crear
     * la preapproval por API porque todavía no tenemos card_token_id.
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
            throw new RuntimeException(
                'El plan seleccionado todavía no tiene configurado el ID del plan de Mercado Pago.'
            );
        }

        $existingSubscription = $company->subscription;

        if ($existingSubscription && in_array($existingSubscription->status, [
            'trialing',
            'pending',
            'authorized',
            'active',
            'past_due',
        ], true)) {
            throw new RuntimeException(
                'Tu empresa ya tiene una suscripción en proceso o activa.'
            );
        }

        $externalReference = 'company_' . $company->id . '_plan_' . $subscriptionPlan->id;

        DB::transaction(function () use ($company, $subscriptionPlan, $externalReference) {
            Subscription::updateOrCreate(
                ['company_id' => $company->id],
                [
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
                ]
            );
        });

        return 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_plan_id='
            . urlencode($subscriptionPlan->mercadopago_plan_id);
    }

    /**
     * Recibe notificaciones de Mercado Pago y sincroniza la suscripción.
     */
  public function webhook(Request $request)
{
    \Log::info('MP WEBHOOK RECIBIDO', [
        'payload' => $request->all(),
        'raw' => $request->getContent(),
    ]);

    $type = $request->input('type');
    $dataId = $request->input('data.id');

    if ($type !== 'subscription_preapproval' || !$dataId) {
        \Log::info('MP WEBHOOK IGNORADO', [
            'type' => $type,
            'data_id' => $dataId,
        ]);

        return response()->json([
            'status' => 'ignored',
        ], 200);
    }

    try {
        $response = $this->mercadoPago->getSubscription((string) $dataId);

        \Log::info('MP SUSCRIPCION OBTENIDA', [
            'subscription_id' => $dataId,
            'response' => $response,
        ]);
    } catch (\Throwable $e) {
        \Log::error('MP ERROR OBTENIENDO SUSCRIPCION', [
            'subscription_id' => $dataId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'status' => 'error',
        ], 500);
    }

    /*
     * Mercado Pago NO devuelve external_reference cuando
     * la suscripción se crea desde un preapproval_plan.
     *
     * La relacionamos usando el ID del plan de Mercado Pago
     * con la suscripción local que está pendiente.
     */
    $mercadoPagoPlanId = $response['preapproval_plan_id'] ?? null;

    \Log::info('MP PLAN RECIBIDO', [
        'subscription_id' => $dataId,
        'mercadopago_plan_id' => $mercadoPagoPlanId,
    ]);

    if (!$mercadoPagoPlanId) {
        \Log::warning('MP SIN PREAPPROVAL PLAN ID', [
            'subscription_id' => $dataId,
        ]);

        return response()->json([
            'status' => 'missing_plan_id',
        ], 200);
    }

    /*
     * Buscamos la suscripción que nuestro checkout creó
     * como pending para ese plan.
     */
    $subscription = Subscription::query()
        ->where('provider', 'mercadopago')
        ->where('provider_plan_id', $mercadoPagoPlanId)
        ->where('status', 'pending')
        ->latest()
        ->first();

    if (!$subscription) {
        \Log::warning('MP SUSCRIPCION LOCAL NO ENCONTRADA', [
            'subscription_id' => $dataId,
            'mercadopago_plan_id' => $mercadoPagoPlanId,
        ]);

        return response()->json([
            'status' => 'subscription_not_found',
        ], 200);
    }

    $companyId = $subscription->company_id;

    $externalReference = $subscription->external_reference;

    $plan = SubscriptionPlan::query()
        ->where('mercadopago_plan_id', $mercadoPagoPlanId)
        ->first();

    \Log::info('MP SUSCRIPCION LOCAL ENCONTRADA', [
        'subscription_id' => $dataId,
        'company_id' => $companyId,
        'mercadopago_plan_id' => $mercadoPagoPlanId,
        'external_reference' => $externalReference,
        'plan_id' => $plan?->id,
    ]);

    $status = $response['status'] ?? $subscription->status;

    $subscription->update([
        'provider' => 'mercadopago',
        'provider_subscription_id' => (string) $dataId,
        'provider_plan_id' => $mercadoPagoPlanId,
        'external_reference' => $externalReference,
        'plan' => $plan?->slug ?? $subscription->plan,
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
        'current_period_start' => data_get(
            $response,
            'auto_recurring.start_date',
            $subscription->current_period_start
        ),
        'current_period_end' => data_get(
            $response,
            'next_payment_date',
            $subscription->current_period_end
        ),
        'canceled_at' => $status === 'canceled'
            ? now()
            : null,
    ]);

    \Log::info('MP SUSCRIPCION SINCRONIZADA', [
        'subscription_id' => $dataId,
        'company_id' => $companyId,
        'status' => $status,
    ]);

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

        $subscription = $company->subscription;

        return view('subscriptions.show', compact('company', 'subscription'));
    }

    public function cancel(Request $request)
{
    $user = $request->user();

    abort_unless($user, 403);
    abort_unless($user->isAdmin() || $user->isSuperAdmin(), 403);

    $company = $user->company;
    abort_unless($company, 403);

    $subscription = $company->subscription;

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

    /*
     * Le pedimos a Mercado Pago que no continúe renovando
     * la suscripción.
     */
    $response = $this->mercadoPago->cancelSubscription(
        $subscription->provider_subscription_id
    );

    /*
     * IMPORTANTE:
     *
     * NO ponemos status = canceled acá.
     *
     * El usuario mantiene acceso hasta current_period_end.
     */
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


}
