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
     * El usuario debe cargar/seleccionar su medio de pago dentro de
     * Mercado Pago. Por eso NO se crea un /preapproval desde nuestro
     * backend en este punto y no se envía card_token_id.
     */
    public function checkout(Request $request, string $plan)
    {
        $user = $request->user();

        abort_unless($user, 403);

        abort_unless(
            $user->isAdmin() || $user->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        $planId = (int) $plan;

        $subscriptionPlan = SubscriptionPlan::query()
            ->whereKey($planId)
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

        $payerEmail = $company->email ?: $user->email;

        if (!$payerEmail) {
            throw new RuntimeException(
                'La empresa necesita un correo electrónico para iniciar la suscripción.'
            );
        }

        $externalReference = 'company_' . $company->id . '_plan_' . $subscriptionPlan->id;

        // El checkout se realiza en Mercado Pago. Allí el usuario ingresa
        // o selecciona su tarjeta y Mercado Pago crea la preapproval.
        $checkoutUrl = 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_plan_id='
            . urlencode($subscriptionPlan->mercadopago_plan_id);

        // Guardamos solamente la intención de checkout. Todavía NO existe
        // una suscripción de Mercado Pago autorizada, por lo que no debemos
        // inventar provider_subscription_id ni marcarla como activa.
        DB::transaction(function () use ($company, $subscriptionPlan, $externalReference) {
            Subscription::updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
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

        return $checkoutUrl;
    }

    /**
     * Muestra el estado actual de la suscripción.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $company = $user->company;

        abort_unless($company, 403);

        $subscription = $company->subscription;

        return view('subscriptions.show', compact(
            'company',
            'subscription'
        ));
    }

    /**
     * Cancela la suscripción actual.
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

        $subscription = $company->subscription;

        if (!$subscription?->provider_subscription_id) {
            return back()->withErrors([
                'subscription' => 'No hay una suscripción de Mercado Pago activa para cancelar.',
            ]);
        }

        $response = $this->mercadoPago->cancelSubscription(
            $subscription->provider_subscription_id
        );

        $subscription->update([
            'status' => $response['status'] ?? 'canceled',
            'canceled_at' => now(),
        ]);

        return back()->with(
            'success',
            'La suscripción fue cancelada correctamente.'
        );
    }
}
