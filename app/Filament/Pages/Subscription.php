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
     * Devuelve la última suscripción de la empresa.
     *
     * IMPORTANTE:
     * Este método NO consulta Mercado Pago.
     * El estado se sincroniza únicamente en mount().
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
     * Sincroniza el estado local con Mercado Pago al entrar
     * a la página.
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
         * no necesitamos volver a tocarla.
         */
        if (in_array(
            $subscription->status,
            ['cancelled', 'canceled'],
            true
        )) {
            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->getSubscription(
                $subscription->provider_subscription_id
            );

            $mpStatus = $mpSubscription['status'] ?? null;

            if (!$mpStatus) {
                return;
            }

            $isCancelled = in_array(
                $mpStatus,
                ['cancelled', 'canceled'],
                true
            );

            if ($isCancelled) {
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

            /*
             * Si Mercado Pago devuelve, por ejemplo:
             *
             * "the preapprovalId is not valid for callerId"
             *
             * NO tocamos nuestra BD.
             *
             * Esto es importante porque si nuestra BD ya dice
             * cancelled, debe seguir diciendo cancelled.
             */
            \Log::warning(
                'NO SE PUDO SINCRONIZAR SUSCRIPCION CON MERCADO PAGO',
                [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,
                    'local_status' => $subscription->status,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Inicia el checkout.
     */
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

        /*
         * Solamente bloqueamos checkout si realmente está activa.
         */
        if (
            $subscription &&
            in_array(
                $subscription->status,
                [
                    'authorized',
                    'active',
                    'trialing',
                    'past_due',
                ],
                true
            )
        ) {
            Notification::make()
                ->title('Ya tenés una suscripción activa')
                ->warning()
                ->send();

            return;
        }

        /*
         * Eliminamos pendientes anteriores.
         */
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

    /**
     * Cancela la suscripción.
     */
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

        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            Notification::make()
                ->title('No hay una suscripción')
                ->warning()
                ->send();

            return;
        }

        /*
         * MUY IMPORTANTE:
         *
         * Si nuestra BD ya dice cancelled,
         * NO hacemos ninguna llamada PUT a Mercado Pago.
         */
        if (in_array(
            $subscription->status,
            ['cancelled', 'canceled'],
            true
        )) {
            Notification::make()
                ->title('Suscripción cancelada')
                ->body(
                    'La suscripción ya figura como cancelada.'
                )
                ->success()
                ->send();

            return;
        }

        /*
         * Si ya está marcada para no renovar.
         */
        if ($subscription->cancel_at_period_end) {
            Notification::make()
                ->title('Cancelación ya programada')
                ->body('La suscripción no se volverá a renovar.')
                ->warning()
                ->send();

            return;
        }

        if (!$subscription->provider_subscription_id) {
            Notification::make()
                ->title('Suscripción inválida')
                ->body(
                    'La suscripción no tiene ID de Mercado Pago.'
                )
                ->danger()
                ->send();

            return;
        }

        try {

            $mp = app(MercadoPagoService::class);

            /*
             * Consultamos primero el estado real.
             */
            $mpSubscription = $mp->getSubscription(
                $subscription->provider_subscription_id
            );

            $mpStatus = $mpSubscription['status'] ?? null;

            /*
             * Mercado Pago ya la canceló.
             *
             * SOLO sincronizamos nuestra BD.
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
             * Solamente intentamos cancelar si está activa.
             */
            if (in_array(
                $mpStatus,
                [
                    'authorized',
                    'active',
                    'trialing',
                    'past_due',
                ],
                true
            )) {

                $mp->cancelSubscription(
                    $subscription->provider_subscription_id
                );

                /*
                 * Consultamos nuevamente el resultado.
                 */
                $mpSubscription = $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

                $finalStatus =
                    $mpSubscription['status'] ?? null;

                if (in_array(
                    $finalStatus,
                    ['cancelled', 'canceled'],
                    true
                )) {
                    $subscription->update([
                        'status' => 'cancelled',
                        'cancel_at_period_end' => true,
                        'canceled_at' =>
                            $subscription->canceled_at ?? now(),
                    ]);
                } else {
                    $subscription->update([
                        'status' => $finalStatus ?? 'cancelled',
                        'cancel_at_period_end' => true,
                        'canceled_at' =>
                            $subscription->canceled_at ?? now(),
                    ]);
                }

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
                'ERROR CANCELANDO/SINCRONIZANDO SUSCRIPCIÓN',
                [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,
                    'local_status' => $subscription->status,
                    'error' => $e->getMessage(),
                ]
            );

            /*
             * Si nuestra BD ya quedó cancelada, jamás mostramos
             * un error que implique que hay que cancelar otra vez.
             */
            if (in_array(
                $subscription->status,
                ['cancelled', 'canceled'],
                true
            )) {
                Notification::make()
                    ->title('Suscripción cancelada')
                    ->body(
                        'La suscripción ya figura como cancelada.'
                    )
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title('No se pudo actualizar la suscripción')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
