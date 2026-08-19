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

    protected static string|\BackedEnum|null $navigationIcon =
        'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Mi suscripción';

    protected static ?string $title = 'Mi suscripción';

    protected static ?string $slug = 'subscription';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->isAdmin() ||
            auth()->user()?->isSuperAdmin(),
            403
        );

        $this->syncCurrentSubscription();
    }

    protected function getPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * IMPORTANTE:
     * Este método SOLO lee nuestra BD.
     * NO consulta Mercado Pago.
     *
     * Esto evita que un error de Mercado Pago
     * cambie cómo se muestra la página.
     */
    protected function getActiveSubscription(): ?SubscriptionModel
    {
        $company = auth()->user()?->company;

        if (!$company) {
            return null;
        }

        return SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->whereNotNull('provider_subscription_id')
            ->latest('id')
            ->first();
    }

    /**
     * Sincroniza Mercado Pago UNA VEZ al cargar la página.
     *
     * Si Mercado Pago dice cancelled/canceled,
     * nuestra BD queda cancelled.
     *
     * Si Mercado Pago falla, NO tocamos nuestra BD.
     */
    protected function syncCurrentSubscription(): void
    {
        $company = auth()->user()?->company;

        if (!$company) {
            return;
        }

        $subscription = SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->whereNotNull('provider_subscription_id')
            ->latest('id')
            ->first();

        if (!$subscription) {
            return;
        }

        /*
         * Si nuestra BD ya sabe que está cancelada,
         * NO necesitamos preguntarle nada a Mercado Pago.
         */
        if (in_array(
            $subscription->status,
            ['cancelled', 'canceled'],
            true
        )) {
            if ($subscription->status !== 'cancelled') {
                $subscription->update([
                    'status' => 'cancelled',
                ]);
            }

            return;
        }

        try {
            $mpSubscription = app(MercadoPagoService::class)
                ->getSubscription(
                    $subscription->provider_subscription_id
                );

            $mpStatus = $mpSubscription['status'] ?? null;

            if (!$mpStatus) {
                return;
            }

            if (in_array(
                $mpStatus,
                ['cancelled', 'canceled'],
                true
            )) {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancel_at_period_end' => true,
                    'canceled_at' =>
                        $subscription->canceled_at ?? now(),
                ]);

                return;
            }

            $subscription->update([
                'status' => $mpStatus,
            ]);

        } catch (\Throwable $e) {
            \Log::warning(
                'NO SE PUDO SINCRONIZAR SUSCRIPCION CON MERCADO PAGO',
                [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,
                    'error' => $e->getMessage(),
                ]
            );

            /*
             * MUY IMPORTANTE:
             * Si Mercado Pago falla, dejamos intacto
             * el estado que tenemos en nuestra BD.
             */
        }
    }

    public function checkout(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->isAdmin() ||
            $user?->isSuperAdmin(),
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

        $subscription = $this->getActiveSubscription();

        if (
            $subscription &&
            in_array(
                $subscription->status,
                ['authorized', 'active', 'trialing'],
                true
            )
        ) {
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

        $localSubscription = SubscriptionModel::create([
            'company_id' => $company->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => null,
            'provider_plan_id' => $plan->mercadopago_plan_id,
            'external_reference' =>
                'company_' . $company->id . '_plan_' . $plan->id,
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
                $localSubscription->delete();

                throw new \RuntimeException(
                    "Mercado Pago no devolvió init_point para el plan {$plan->name}."
                );
            }

            $this->redirect(
                $initPoint,
                navigate: false
            );

        } catch (\Throwable $e) {
            $localSubscription->delete();

            throw $e;
        }
    }

    public function cancelSubscription(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->isAdmin() ||
            $user?->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        /*
         * SOLO buscamos una suscripción realmente activa.
         */
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

        /*
         * Si está cancelled, ni siquiera entra acá.
         */
        if (!$subscription) {
            Notification::make()
                ->title('La suscripción no está activa')
                ->warning()
                ->send();

            return;
        }

        if ($subscription->cancel_at_period_end) {
            Notification::make()
                ->title('La suscripción ya está cancelada')
                ->warning()
                ->send();

            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->getSubscription(
                $subscription->provider_subscription_id
            );

            $mpStatus = $mpSubscription['status'] ?? null;

            /*
             * Mercado Pago YA la canceló.
             * NO hacemos PUT.
             */
            if (in_array(
                $mpStatus,
                ['cancelled', 'canceled'],
                true
            )) {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancel_at_period_end' => true,
                    'canceled_at' =>
                        $subscription->canceled_at ?? now(),
                ]);

                $this->dispatch('subscription-updated');

                Notification::make()
                    ->title('Suscripción cancelada')
                    ->body(
                        'La suscripción ya estaba cancelada en Mercado Pago.'
                    )
                    ->success()
                    ->send();

                return;
            }

            /*
             * Solamente intentamos cancelar si sigue activa.
             */
            if (in_array(
                $mpStatus,
                ['authorized', 'active', 'trialing'],
                true
            )) {
                $mp->cancelSubscription(
                    $subscription->provider_subscription_id
                );

                $subscription->update([
                    'status' => 'cancelled',
                    'cancel_at_period_end' => true,
                    'canceled_at' =>
                        $subscription->canceled_at ?? now(),
                ]);

                $this->dispatch('subscription-updated');

                Notification::make()
                    ->title('Suscripción cancelada')
                    ->success()
                    ->send();

                return;
            }

            /*
             * Estado desconocido.
             */
            $subscription->update([
                'status' => $mpStatus ?? $subscription->status,
            ]);

            $this->dispatch('subscription-updated');

            Notification::make()
                ->title('Estado actualizado')
                ->body(
                    'Mercado Pago informa el estado: ' .
                    ($mpStatus ?? 'desconocido')
                )
                ->warning()
                ->send();

        } catch (\Throwable $e) {

            \Log::error(
                'ERROR CANCELANDO SUSCRIPCIÓN',
                [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,
                    'error' => $e->getMessage(),
                ]
            );

            Notification::make()
                ->title('No se pudo cancelar la suscripción')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
