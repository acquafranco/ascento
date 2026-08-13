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
        'method' => $request->method(),
        'url' => $request->fullUrl(),
        'headers' => $request->headers->all(),
        'payload' => $request->all(),
    ]);

    return response()->json([
        'status' => 'received',
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
