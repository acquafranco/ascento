<?php

namespace App\Filament\Pages;

use App\Http\Controllers\SubscriptionController;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Filament\Pages\Page;

class Subscription extends Page
{
    protected string $view = 'filament.pages.subscription';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Mi suscripción';

    protected static ?string $title = 'Mi suscripción';

    protected static ?string $slug = 'subscription';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->isAdmin() || auth()->user()?->isSuperAdmin(),
            403
        );
    }

    public function getPlans()
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();
    }
public function checkout(int|string $planId): void
{
    $user = auth()->user();

    abort_unless(
        $user?->isAdmin() || $user?->isSuperAdmin(),
        403
    );

    $company = $user->company;

    abort_unless($company, 403);

    $plan = SubscriptionPlan::query()
        ->whereKey((int) $planId)
        ->where('is_active', true)
        ->firstOrFail();

    if (!$plan->mercadopago_plan_id) {
        throw new \RuntimeException(
            "El plan {$plan->name} no tiene un plan de Mercado Pago configurado."
        );
    }

    /*
     * Creamos/actualizamos la suscripción local como pending
     * antes de enviar al usuario a Mercado Pago.
     */
    \App\Models\Subscription::updateOrCreate(
        [
            'company_id' => $company->id,
        ],
        [
            'provider' => 'mercadopago',
            'provider_subscription_id' => null,
            'provider_plan_id' => $plan->mercadopago_plan_id,
            'external_reference' => 'company_' . $company->id . '_plan_' . $plan->id,
            'plan' => $plan->slug,
            'status' => 'pending',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'trial_ends_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'canceled_at' => null,
            'cancel_at_period_end' => false,
        ]
    );

    $mercadoPagoPlan = app(MercadoPagoService::class)
        ->getSubscriptionPlan(
            (string) $plan->mercadopago_plan_id
        );

    $initPoint = $mercadoPagoPlan['init_point'] ?? null;

    if (!$initPoint) {
        throw new \RuntimeException(
            "Mercado Pago no devolvió init_point para el plan {$plan->name}."
        );
    }

    $this->redirect($initPoint, navigate: false);
}

    public function changePlan(int|string $planId): void
{
    $plan = SubscriptionPlan::query()
        ->whereKey((int) $planId)
        ->where('is_active', true)
        ->firstOrFail();

    $this->checkout($plan->getKey());
}

public function cancelSubscription(): void
{
    $user = auth()->user();

    abort_unless(
        $user?->isAdmin() || $user?->isSuperAdmin(),
        403
    );

    $subscription = $user->company?->subscription;

    if (!$subscription) {
        return;
    }

    if ($subscription->cancel_at_period_end) {
        return;
    }

    if (!$subscription->provider_subscription_id) {
        throw new \RuntimeException(
            'La suscripción todavía no tiene ID de Mercado Pago.'
        );
    }

    app(\App\Services\MercadoPagoService::class)
        ->cancelSubscription(
            $subscription->provider_subscription_id
        );

    $subscription->update([
        'cancel_at_period_end' => true,
        'canceled_at' => now(),
    ]);

    $this->redirect(request()->header('Referer'));
}
}
