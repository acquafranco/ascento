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

    /*
     * Buscamos la última suscripción de la empresa
     * que tenga un ID real de Mercado Pago.
     *
     * NO filtramos solamente por status local porque
     * justamente puede estar desincronizada.
     */
    $subscription = SubscriptionModel::query()
        ->where('company_id', $company->id)
        ->where('provider', 'mercadopago')
        ->whereNotNull('provider_subscription_id')
        ->latest('id')
        ->first();

    if (!$subscription) {
        Notification::make()
            ->title('No hay una suscripción')
            ->warning()
            ->send();

        return;
    }

    $mercadoPago = app(MercadoPagoService::class);

    try {
        /*
         * PRIMERO consultamos Mercado Pago.
         *
         * Esto es fundamental porque la DB local puede
         * decir authorized mientras Mercado Pago ya dice
         * cancelled.
         */
        $remoteSubscription = $mercadoPago
            ->getSubscription(
                $subscription->provider_subscription_id
            );

        $remoteStatus =
            $remoteSubscription['status'] ?? null;

        /*
         * ======================================================
         * YA ESTÁ CANCELADA EN MERCADO PAGO
         * ======================================================
         */
        if (in_array($remoteStatus, [
            'cancelled',
            'canceled',
        ], true)) {

            $subscription->update([
                'status' => 'cancelled',
                'cancel_at_period_end' => true,
                'canceled_at' =>
                    $subscription->canceled_at
                    ?? now(),
            ]);

            $subscription->refresh();

            $this->dispatch(
                'subscription-updated'
            );

            Notification::make()
                ->title('Suscripción sincronizada')
                ->body(
                    'La suscripción ya estaba cancelada en Mercado Pago y se actualizó Ascento.'
                )
                ->success()
                ->send();

            return;
        }

        /*
         * ======================================================
         * YA ESTÁ PROGRAMADA LOCALMENTE
         * ======================================================
         */
        if ($subscription->cancel_at_period_end) {
            Notification::make()
                ->title('Cancelación ya programada')
                ->warning()
                ->send();

            return;
        }

        /*
         * ======================================================
         * CANCELAR EN MERCADO PAGO
         * ======================================================
         */
        $remoteSubscription =
            $mercadoPago->cancelSubscription(
                $subscription->provider_subscription_id
            );

        $remoteStatus =
            $remoteSubscription['status']
            ?? 'canceled';

        /*
         * ======================================================
         * ACTUALIZAR DB LOCAL
         * ======================================================
         */
        $subscription->update([
            'status' => in_array($remoteStatus, [
                'cancelled',
                'canceled',
            ], true)
                ? 'cancelled'
                : $subscription->status,

            'cancel_at_period_end' => true,

            'canceled_at' =>
                $subscription->canceled_at
                ?? now(),
        ]);

        $subscription->refresh();

        $this->dispatch(
            'subscription-updated'
        );

        Notification::make()
            ->title('Suscripción cancelada')
            ->body(
                $subscription->current_period_end
                    ? 'Vas a seguir teniendo acceso hasta '
                        . $subscription->current_period_end->format('d/m/Y')
                        . '.'
                    : 'La suscripción no se renovará nuevamente.'
            )
            ->success()
            ->send();

    } catch (\Throwable $e) {

        \Log::error(
            'ERROR CANCELANDO SUSCRIPCION DESDE FILAMENT',
            [
                'company_id' =>
                    $company->id,

                'subscription_id' =>
                    $subscription->id,

                'provider_subscription_id' =>
                    $subscription->provider_subscription_id,

                'error' =>
                    $e->getMessage(),

                'trace' =>
                    $e->getTraceAsString(),
            ]
        );

        Notification::make()
            ->title('No se pudo cancelar la suscripción')
            ->body(
                'Mercado Pago respondió: '
                . $e->getMessage()
            )
            ->danger()
            ->send();
    }
}
}
