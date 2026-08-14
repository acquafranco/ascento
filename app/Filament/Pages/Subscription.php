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

    $subscription = \App\Models\Subscription::query()
        ->where('company_id', $company->id)
        ->latest('id')
        ->first();

    /*
     * Si ya existe una suscripción activa,
     * NO la pisamos.
     */
    if (
        $subscription &&
        in_array($subscription->status, [
            'authorized',
            'active',
            'trialing',
        ], true) &&
        $subscription->provider_subscription_id
    ) {
        throw new \RuntimeException(
            'La empresa ya tiene una suscripción activa. '
            . 'Para cambiar de plan se debe iniciar el proceso de cambio.'
        );
    }

    /*
     * Creamos la suscripción local pendiente.
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
    $user = auth()->user();

    abort_unless(
        $user?->isAdmin() || $user?->isSuperAdmin(),
        403
    );

    $company = $user->company;

    abort_unless($company, 403);

    $newPlan = SubscriptionPlan::query()
        ->whereKey((int) $planId)
        ->where('is_active', true)
        ->firstOrFail();

    $currentSubscription = \App\Models\Subscription::query()
        ->where('company_id', $company->id)
        ->whereIn('status', [
            'authorized',
            'active',
            'trialing',
        ])
        ->whereNotNull('provider_subscription_id')
        ->latest('id')
        ->first();

    /*
     * Si no hay suscripción activa, es una contratación inicial.
     */
    if (!$currentSubscription) {
        $this->checkout($newPlan->getKey());
        return;
    }

    /*
     * Si eligió el mismo plan, no hacemos nada.
     */
    if ($currentSubscription->plan === $newPlan->slug) {
        return;
    }

    /*
     * IMPORTANTE:
     * NO modificamos la suscripción actual.
     *
     * La actual sigue siendo el plan que el cliente pagó.
     */

    $pending = \App\Models\Subscription::query()
        ->where('company_id', $company->id)
        ->where('status', 'pending')
        ->first();

    if ($pending) {
        $pending->delete();
    }

    /*
     * Creamos una nueva suscripción PENDIENTE.
     * Esta representa solamente el cambio que el usuario
     * está intentando contratar.
     */
        \App\Models\Subscription::create([
        'company_id' => $company->id,
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
    ]);

    $mercadoPagoPlan = app(MercadoPagoService::class)
        ->getSubscriptionPlan(
            (string) $newPlan->mercadopago_plan_id
        );

    $initPoint = $mercadoPagoPlan['init_point'] ?? null;

    if (!$initPoint) {
        throw new \RuntimeException(
            "Mercado Pago no devolvió init_point para el plan {$newPlan->name}."
        );
    }

    $this->redirect($initPoint, navigate: false);
}

public function cancelSubscription(): void
{
    $user = auth()->user();

    abort_unless(
        $user?->isAdmin() || $user?->isSuperAdmin(),
        403
    );

    $company = $user->company;

    abort_unless($company, 403);

    // Forzamos a obtener la suscripción actual directamente desde DB.
    $subscription = \App\Models\Subscription::query()
        ->where('company_id', $company->id)
        ->latest('id')
        ->first();

    if (!$subscription) {
        throw new \RuntimeException(
            'No se encontró una suscripción para esta empresa.'
        );
    }

    if ($subscription->cancel_at_period_end) {
        return;
    }

    if (!$subscription->provider_subscription_id) {
        throw new \RuntimeException(
            'La suscripción existe pero no tiene ID de Mercado Pago. '
            . 'Estado actual: ' . $subscription->status
        );
    }

    if (!in_array($subscription->status, [
        'authorized',
        'active',
        'trialing',
    ], true)) {
        throw new \RuntimeException(
            'La suscripción no puede cancelarse porque su estado actual es: '
            . $subscription->status
        );
    }

    // Cancelamos la suscripción REAL en Mercado Pago.
    app(MercadoPagoService::class)->cancelSubscription(
        $subscription->provider_subscription_id
    );

    // No quitamos el acceso inmediatamente.
    // La empresa sigue teniendo acceso hasta current_period_end.
    $subscription->update([
        'cancel_at_period_end' => true,
        'canceled_at' => now(),
    ]);

    // Recargamos el modelo para que Livewire muestre el estado actualizado.
    $subscription->refresh();

    $this->dispatch('subscription-updated');

    \Filament\Notifications\Notification::make()
        ->title('Cancelación programada')
        ->body(
            $subscription->current_period_end
                ? 'Vas a seguir teniendo acceso hasta '
                    . $subscription->current_period_end->format('d/m/Y') . '.'
                : 'La suscripción no se renovará nuevamente.'
        )
        ->success()
        ->send();
}
}
