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
     * Inicia el proceso de suscripción de la empresa.
     */
    public function checkout(Request $request, SubscriptionPlan $plan)
    {
        $user = $request->user();
        $company = $user?->company;

        abort_unless($company, 403);

        if (!$plan->is_active) {
            abort(404);
        }

        if (!$plan->mercadopago_plan_id) {
            return back()->withErrors([
                'subscription' => 'Este plan todavía no está configurado en Mercado Pago.',
            ]);
        }

        $existingSubscription = $company->subscription;

        if ($existingSubscription) {
            if (in_array($existingSubscription->status, [
                'trialing',
                'pending',
                'authorized',
                'active',
                'past_due',
            ], true)) {
                return redirect()
                    ->route('subscription.show')
                    ->with('info', 'Tu empresa ya tiene una suscripción en proceso o activa.');
            }
        }

        $payerEmail = $company->email ?: $user->email;

        if (!$payerEmail) {
            return back()->withErrors([
                'subscription' => 'La empresa necesita un correo electrónico para iniciar la suscripción.',
            ]);
        }

        $externalReference = 'company_' . $company->id . '_plan_' . $plan->id;

        $response = $this->mercadoPago->createSubscription([
            'preapproval_plan_id' => $plan->mercadopago_plan_id,
            'reason' => 'Suscripción Ascento - ' . $plan->name,
            'external_reference' => $externalReference,
            'payer_email' => $payerEmail,
            'back_url' => route('subscription.show'),
        ]);

        DB::transaction(function () use ($company, $plan, $response, $externalReference) {
            Subscription::updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'provider' => 'mercadopago',
                    'provider_subscription_id' => $response['id'] ?? null,
                    'provider_plan_id' => $plan->mercadopago_plan_id,
                    'external_reference' => $externalReference,
                    'plan' => $plan->slug,
                    'status' => $response['status'] ?? 'pending',
                    'amount' => data_get(
                        $response,
                        'auto_recurring.transaction_amount',
                        $plan->price
                    ),
                    'currency' => data_get(
                        $response,
                        'auto_recurring.currency_id',
                        $plan->currency
                    ),
                    'trial_ends_at' => null,
                    'current_period_start' => data_get(
                        $response,
                        'auto_recurring.start_date'
                    ),
                    'current_period_end' => null,
                    'canceled_at' => null,
                ]
            );
        });

        if (empty($response['init_point'])) {
            throw new RuntimeException(
                'Mercado Pago no devolvió el enlace de checkout.'
            );
        }

        return redirect()->away($response['init_point']);
    }

    /**
     * Muestra el estado actual de la suscripción.
     */
    public function show(Request $request)
    {
        $company = $request->user()->company;

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
        $company = $request->user()->company;

        abort_unless($company, 403);

        $subscription = $company->subscription;

        if (!$subscription?->provider_subscription_id) {
            return back()->withErrors([
                'subscription' => 'No hay una suscripción activa para cancelar.',
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
