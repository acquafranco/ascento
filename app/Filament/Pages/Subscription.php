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

        $plan = SubscriptionPlan::query()
            ->whereKey($planId)
            ->where('is_active', true)
            ->firstOrFail();

        if (!$plan->mercadopago_plan_id) {
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

        $response = $this->mercadoPago->getSubscriptionPlan(
            $plan->mercadopago_plan_id
        );

        if (empty($response['init_point'])) {
            throw new RuntimeException(
                'Mercado Pago no devolvió el enlace de checkout del plan.'
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
