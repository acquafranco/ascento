<?php

namespace App\Filament\Pages;

use App\Models\Subscription as SubscriptionModel;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Filament\Notifications\Notification;
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

    /**
     * Obtiene únicamente la suscripción activa de la empresa.
     *
     * Nunca devuelve una pending.
     */
    protected function getActiveSubscription(): ?SubscriptionModel
    {
        $company = auth()->user()?->company;

        if (!$company) {
            return null;
        }

        return SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                'authorized',
                'active',
                'trialing',
            ])
            ->whereNotNull('provider_subscription_id')
            ->latest('id')
            ->first();
    }

    /**
     * Obtiene la suscripción pendiente más reciente.
     */
    protected function getPendingSubscription(): ?SubscriptionModel
    {
        $company = auth()->user()?->company;

        if (!$company) {
            return null;
        }

        return SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();
    }

    /**
     * Contratación inicial.
     */
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
         * Checkout solamente sirve para contratación inicial.
         */
        $activeSubscription = $this->getActiveSubscription();

        if ($activeSubscription) {
            throw new \RuntimeException(
                'La empresa ya tiene una suscripción activa. '
                . 'Para cambiar de plan se debe utilizar el cambio de plan.'
            );
        }

        /*
         * Eliminamos pendientes anteriores.
         */
        SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->delete();

        /*
         * Creamos la nueva suscripción pendiente.
         */
        SubscriptionModel::create([
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

        /*
         * Obtenemos el checkout de Mercado Pago.
         */
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

    /**
     * Cambiar de plan.
     */
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

        /*
         * Buscamos la suscripción ACTIVA REAL.
         *
         * En tu caso actualmente:
         *
         * ID 4
         * professional
         * authorized
         */
        $currentSubscription = $this->getActiveSubscription();

        /*
         * Si no hay suscripción activa,
         * hacemos una contratación inicial.
         */
        if (!$currentSubscription) {
            $this->checkout($newPlan->getKey());
            return;
        }

        /*
         * Si ya está en ese plan, no hacemos nada.
         */
        if ($currentSubscription->plan === $newPlan->slug) {
            Notification::make()
                ->title('Ya tenés este plan')
                ->warning()
                ->send();

            return;
        }

        /*
         * Eliminamos cualquier cambio pendiente anterior.
         */
        SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->delete();

        /*
         * Creamos la nueva suscripción pendiente.
         *
         * IMPORTANTE:
         * NO modificamos la suscripción actual.
         */
        $pendingSubscription = SubscriptionModel::create([
            'company_id' => $company->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => null,
            'provider_plan_id' => $newPlan->mercadopago_plan_id,
            'external_reference' => 'company_' . $company->id . '_plan_' . $newPlan->id,
            'plan' => $newPlan->slug,
            'status' => 'pending',
            'amount' => $newPlan->price,
            'currency' => $newPlan->currency,
            'trial_ends_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'canceled_at' => null,
            'cancel_at_period_end' => false,
        ]);

        /*
         * Obtenemos el checkout del nuevo plan.
         */
        $mercadoPagoPlan = app(MercadoPagoService::class)
            ->getSubscriptionPlan(
                (string) $newPlan->mercadopago_plan_id
            );

        $initPoint = $mercadoPagoPlan['init_point'] ?? null;

        if (!$initPoint) {
            /*
             * Si Mercado Pago falla, eliminamos
             * la pending que acabamos de crear.
             */
            $pendingSubscription->delete();

            throw new \RuntimeException(
                "Mercado Pago no devolvió init_point para el plan {$newPlan->name}."
            );
        }

        /*
         * Mandamos al usuario a Mercado Pago.
         */
        $this->redirect($initPoint, navigate: false);
    }

    /**
     * Cancelar la suscripción activa.
     */
    public function cancelSubscription(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->isAdmin() || $user?->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        /*
         * IMPORTANTE:
         * Buscamos la última suscripción ACTIVA,
         * nunca simplemente la última fila.
         */
        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            throw new \RuntimeException(
                'No se encontró una suscripción activa para esta empresa.'
            );
        }

        if ($subscription->cancel_at_period_end) {
            return;
        }

        if (!$subscription->provider_subscription_id) {
            throw new \RuntimeException(
                'La suscripción activa no tiene ID de Mercado Pago.'
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

        /*
         * Cancelamos la suscripción REAL en Mercado Pago.
         */
        app(MercadoPagoService::class)->cancelSubscription(
            $subscription->provider_subscription_id
        );

        /*
         * No quitamos el acceso inmediatamente.
         */
        $subscription->update([
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        $subscription->refresh();

        $this->dispatch('subscription-updated');

        Notification::make()
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
