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

    $subscription = SubscriptionModel::query()
        ->where('company_id', $company->id)
        ->whereNotNull('provider_subscription_id')
        ->latest('id')
        ->first();

    if (!$subscription) {
        return null;
    }

    try {
        $mpSubscription = app(MercadoPagoService::class)
            ->getSubscription(
                $subscription->provider_subscription_id
            );

        $mpStatus = $mpSubscription['status'] ?? null;

        if ($mpStatus) {
            $isCancelled = in_array(
                $mpStatus,
                ['cancelled', 'canceled'],
                true
            );

            $subscription->update([
                'status' => $mpStatus,
                'cancel_at_period_end' =>
                    $isCancelled
                        ? true
                        : $subscription->cancel_at_period_end,
                'canceled_at' =>
                    $isCancelled
                        ? ($subscription->canceled_at ?? now())
                        : $subscription->canceled_at,
            ]);

            $subscription->refresh();
        }
    } catch (\Throwable $e) {
        \Log::warning(
            'ERROR SINCRONIZANDO SUSCRIPCION AL CARGAR PAGINA',
            [
                'company_id' => $company->id,
                'subscription_id' => $subscription->id,
                'provider_subscription_id' =>
                    $subscription->provider_subscription_id,
                'error' => $e->getMessage(),
            ]
        );
    }

    return $subscription;
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
        'trial_ends_at' => null,
        'current_period_start' => null,
        'current_period_end' => null,
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

    $subscription = SubscriptionModel::query()
        ->where('company_id', $company->id)
        ->whereNotNull('provider_subscription_id')
        ->whereIn('status', [
            'authorized',
            'active',
            'trialing',
            'past_due',
        ])
        ->latest('id')
        ->first();

    if (!$subscription) {
        Notification::make()
            ->title('No hay una suscripción activa')
            ->warning()
            ->send();

        return;
    }

    if ($subscription->cancel_at_period_end) {
        Notification::make()
            ->title('La suscripción ya está cancelada')
            ->body('No se volverá a renovar.')
            ->warning()
            ->send();

        return;
    }

    try {
        $mpSubscription = app(MercadoPagoService::class)
            ->getSubscription(
                $subscription->provider_subscription_id
            );

        $mpStatus = $mpSubscription['status'] ?? null;

        /*
         * Mercado Pago ya la canceló.
         * Sin intentar cancelarla nuevamente.
         */
        if (in_array($mpStatus, ['cancelled', 'canceled'], true)) {
            $subscription->update([
                'status' => 'cancelled',
                'cancel_at_period_end' => true,
                'canceled_at' => $subscription->canceled_at ?? now(),
            ]);

            $this->dispatch('subscription-updated');

            Notification::make()
                ->title('Suscripción cancelada')
                ->body('La suscripción ya estaba cancelada en Mercado Pago.')
                ->success()
                ->send();

            return;
        }

        /*
         * Si todavía está activa, la cancelamos en Mercado Pago.
         */
        app(MercadoPagoService::class)->cancelSubscription(
            $subscription->provider_subscription_id
        );

        /*
         * Sincronizamos inmediatamente con Mercado Pago
         * para conocer el estado real.
         */
        $mpSubscription = app(MercadoPagoService::class)
            ->getSubscription(
                $subscription->provider_subscription_id
            );

        $mpStatus = $mpSubscription['status'] ?? null;

        $subscription->update([
            'status' => $mpStatus ?? 'cancelled',
            'cancel_at_period_end' => true,
            'canceled_at' => $subscription->canceled_at ?? now(),
        ]);

        $this->dispatch('subscription-updated');

        Notification::make()
            ->title('Suscripción cancelada')
            ->body(
                $subscription->current_period_end
                    ? 'Vas a seguir teniendo acceso hasta '
                        . $subscription->current_period_end->format('d/m/Y') . '.'
                    : 'La suscripción no se renovará nuevamente.'
            )
            ->success()
            ->send();

    } catch (\Throwable $e) {

        \Log::error('ERROR CANCELANDO/SINCRONIZANDO SUSCRIPCIÓN', [
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'provider_subscription_id' =>
                $subscription->provider_subscription_id,
            'error' => $e->getMessage(),
        ]);

        Notification::make()
            ->title('No se pudo cancelar la suscripción')
            ->body(
                'Mercado Pago respondió: ' . $e->getMessage()
            )
            ->danger()
            ->send();
    }
}

protected function syncSubscriptionFromMercadoPago(
    SubscriptionModel $subscription
): SubscriptionModel {
    $mp = app(MercadoPagoService::class);

    $response = $mp->getSubscription(
        $subscription->provider_subscription_id
    );

    $status = $response['status'] ?? $subscription->status;

    $isCancelled = in_array(
        $status,
        ['cancelled', 'canceled'],
        true
    );

    $subscription->update([
        'status' => $status,
        'cancel_at_period_end' =>
            $isCancelled
                ? true
                : $subscription->cancel_at_period_end,
        'canceled_at' =>
            $isCancelled
                ? ($subscription->canceled_at ?? now())
                : $subscription->canceled_at,
    ]);

    return $subscription->fresh();
}
}
