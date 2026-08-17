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

    /**
     * Obtiene el único plan activo de Ascento.
     */
    protected function getPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Obtiene la suscripción activa real de la empresa.
     *
     * Nunca considera una pending.
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
     * Inicia la contratación del único plan de Ascento.
     */
    public function checkout(): void
{
    $user = auth()->user();

    abort_unless(
        $user?->isAdmin() || $user?->isSuperAdmin(),
        403
    );

    $company = $user->company;

    abort_unless($company, 403);

    $plan = $this->getPlan();

    if (!$plan) {
        throw new \RuntimeException(
            'No hay ningún plan activo configurado para Ascento.'
        );
    }

    if (!$plan->mercadopago_plan_id) {
        throw new \RuntimeException(
            "El plan {$plan->name} no tiene un plan de Mercado Pago configurado."
        );
    }

    if ($this->getActiveSubscription()) {
        Notification::make()
            ->title('Ya tenés una suscripción activa')
            ->warning()
            ->send();

        return;
    }

    SubscriptionModel::query()
        ->where('company_id', $company->id)
        ->where('status', 'pending')
        ->delete();

    $subscription = SubscriptionModel::create([
        'company_id' => $company->id,
        'provider' => 'mercadopago',
        'provider_subscription_id' => null,
        'provider_plan_id' => $plan->mercadopago_plan_id,
        'external_reference' => 'company_' . $company->id . '_plan_' . $plan->id,
        'plan' => $plan->slug,
        'status' => 'pending',
        'amount' => $plan->price,
        'currency' => $plan->currency,
        'trial_ends_at' => now()->addDays(30),
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(30),
        'canceled_at' => null,
        'cancel_at_period_end' => false,
    ]);

    try {
        $mercadoPagoPlan = app(MercadoPagoService::class)
            ->getSubscriptionPlan(
                (string) $plan->mercadopago_plan_id
            );

        $initPoint = $mercadoPagoPlan['init_point'] ?? null;

        if (!$initPoint) {
            $subscription->delete();

            throw new \RuntimeException(
                "Mercado Pago no devolvió init_point para el plan {$plan->name}."
            );
        }

        $this->redirect($initPoint, navigate: false);

    } catch (\Throwable $e) {
        $subscription->delete();
        throw $e;
    }
}

    /**
     * Cancela la suscripción activa.
     *
     * La empresa mantiene el acceso hasta
     * current_period_end.
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
         * Buscamos únicamente la suscripción activa.
         */
        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            Notification::make()
                ->title('No hay una suscripción activa')
                ->warning()
                ->send();

            return;
        }

        /*
         * Si ya está marcada para cancelar, no hacemos nada.
         */
        if ($subscription->cancel_at_period_end) {
            Notification::make()
                ->title('Cancelación ya programada')
                ->warning()
                ->send();

            return;
        }

        if (!$subscription->provider_subscription_id) {
            throw new \RuntimeException(
                'La suscripción activa no tiene ID de Mercado Pago.'
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
