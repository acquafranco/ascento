<?php

namespace App\Filament\Pages;

use App\Models\Subscription as SubscriptionModel;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

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
     * UNA EMPRESA = UNA SUSCRIPCIÓN LOCAL.
     */
    protected function getActiveSubscription(): ?SubscriptionModel
    {
        $company = auth()->user()?->company;

        if (!$company) {
            return null;
        }

        return SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->first();
    }

    /**
     * Sincroniza el estado local con Mercado Pago.
     */
    protected function syncCurrentSubscription(): void
    {
        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            return;
        }

        if (!$subscription->provider_subscription_id) {
            return;
        }

        /*
         * Si ya está cancelada localmente, no insistimos.
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

            /*
             * CANCELACIÓN DEFINITIVA.
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

                return;
            }

            /*
             * PAUSADA.
             */
            if ($mpStatus === 'paused') {
                $subscription->update([
                    'status' => 'paused',
                ]);

                return;
            }

            /*
             * ACTIVA / OTRO ESTADO.
             */
            $subscription->update([
                'status' => $mpStatus,
            ]);

        } catch (\Throwable $e) {
            Log::warning(
                'NO SE PUDO SINCRONIZAR SUSCRIPCION CON MERCADO PAGO',
                [
                    'company_id' =>
                        $subscription->company_id,

                    'subscription_id' =>
                        $subscription->id,

                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Contratar / reactivar.
     *
     * Flujo:
     *
     * SIN SUSCRIPCIÓN
     *     -> crea checkout
     *
     * PAUSADA
     *     -> reactiva la misma suscripción
     *
     * ACTIVA
     *     -> no hace nada
     *
     * CANCELADA
     *     -> no puede reutilizarse
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
         * ==========================================================
         * NO EXISTE SUSCRIPCIÓN
         * ==========================================================
         */

        if (!$subscription) {
            $subscription = SubscriptionModel::create([
                'company_id' =>
                    $company->id,

                'provider' =>
                    'mercadopago',

                'provider_subscription_id' =>
                    null,

                'provider_plan_id' =>
                    $plan->mercadopago_plan_id,

                'external_reference' =>
                    'company_' .
                    $company->id .
                    '_plan_' .
                    $plan->id,

                'plan' =>
                    $plan->slug,

                'status' =>
                    'pending',

                'amount' =>
                    $plan->price,

                'currency' =>
                    $plan->currency,

                'trial_ends_at' =>
                    null,

                'current_period_start' =>
                    null,

                'current_period_end' =>
                    null,

                'canceled_at' =>
                    null,

                'cancel_at_period_end' =>
                    false,
            ]);

            $this->startCheckout(
                $subscription,
                $plan
            );

            return;
        }

        /*
         * ==========================================================
         * YA ESTÁ ACTIVA
         * ==========================================================
         */

        if (in_array(
            $subscription->status,
            [
                'authorized',
                'active',
                'trialing',
                'past_due',
            ],
            true
        )) {
            Notification::make()
                ->title('Ya tenés una suscripción activa')
                ->warning()
                ->send();

            return;
        }

        /*
         * ==========================================================
         * ESTÁ PAUSADA
         *
         * REACTIVAMOS LA MISMA.
         * NO CREAMOS OTRA.
         * ==========================================================
         */

        if (
            $subscription->status === 'paused' &&
            $subscription->provider_subscription_id
        ) {
            $this->resumeSubscription();

            return;
        }

        /*
         * ==========================================================
         * CANCELADA DEFINITIVAMENTE
         * ==========================================================
         */

        if (in_array(
            $subscription->status,
            ['cancelled', 'canceled'],
            true
        )) {
            Notification::make()
                ->title('La suscripción fue cancelada')
                ->body(
                    'Mercado Pago no permite reactivar una suscripción cancelada. Debés contratar nuevamente.'
                )
                ->warning()
                ->send();

            return;
        }

        /*
         * ==========================================================
         * PENDING
         * ==========================================================
         */

        if (
            $subscription->status === 'pending' &&
            !$subscription->provider_subscription_id
        ) {
            $this->startCheckout(
                $subscription,
                $plan
            );

            return;
        }

        Notification::make()
            ->title('Estado de suscripción no reconocido')
            ->body(
                'Estado actual: ' .
                ($subscription->status ?? 'desconocido')
            )
            ->danger()
            ->send();
    }

    /**
     * Inicia checkout de Mercado Pago.
     */
    protected function startCheckout(
        SubscriptionModel $subscription,
        SubscriptionPlan $plan
    ): void {
        try {
            $mp = app(MercadoPagoService::class);

            $mercadoPagoPlan = $mp->getSubscriptionPlan(
                (string) $plan->mercadopago_plan_id
            );

            $initPoint =
                $mercadoPagoPlan['init_point'] ?? null;

            if (!$initPoint) {
                throw new \RuntimeException(
                    "Mercado Pago no devolvió init_point para el plan {$plan->name}."
                );
            }

            $subscription->update([
                'status' => 'pending',
                'provider_plan_id' =>
                    $plan->mercadopago_plan_id,
                'plan' =>
                    $plan->slug,
                'amount' =>
                    $plan->price,
                'currency' =>
                    $plan->currency,
                'cancel_at_period_end' =>
                    false,
            ]);

            $this->redirect(
                $initPoint,
                navigate: false
            );

        } catch (\Throwable $e) {
            Log::error(
                'ERROR INICIANDO CHECKOUT DE MERCADO PAGO',
                [
                    'company_id' =>
                        $subscription->company_id,

                    'subscription_id' =>
                        $subscription->id,

                    'provider_plan_id' =>
                        $subscription->provider_plan_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            Notification::make()
                ->title('No se pudo iniciar el checkout')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * PAUSA la suscripción.
     *
     * IMPORTANTE:
     *
     * NO usamos "canceled".
     *
     * De esta manera podemos reactivarla
     * utilizando el MISMO provider_subscription_id.
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

        if (!$subscription->provider_subscription_id) {
            Notification::make()
                ->title('No hay una suscripción de Mercado Pago')
                ->warning()
                ->send();

            return;
        }

        if ($subscription->status === 'paused') {
            Notification::make()
                ->title('La suscripción ya está pausada')
                ->warning()
                ->send();

            return;
        }

        if (in_array(
            $subscription->status,
            ['cancelled', 'canceled'],
            true
        )) {
            Notification::make()
                ->title('La suscripción ya está cancelada')
                ->warning()
                ->send();

            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            /*
             * Consultamos primero.
             */
            $mpSubscription = $mp->getSubscription(
                $subscription->provider_subscription_id
            );

            $mpStatus =
                $mpSubscription['status'] ?? null;

            /*
             * Si MP ya la canceló, reflejamos eso.
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

                $this->dispatch(
                    'subscription-updated'
                );

                Notification::make()
                    ->title('Suscripción cancelada')
                    ->warning()
                    ->send();

                return;
            }

            /*
             * Si ya está pausada.
             */
            if ($mpStatus === 'paused') {
                $subscription->update([
                    'status' => 'paused',
                ]);

                Notification::make()
                    ->title('Suscripción pausada')
                    ->warning()
                    ->send();

                return;
            }

            /*
             * Solamente pausamos si está activa.
             */
            if (in_array(
                $mpStatus,
                [
                    'authorized',
                    'active',
                    'trialing',
                ],
                true
            )) {
                $mp->pauseSubscription(
                    $subscription->provider_subscription_id
                );

                /*
                 * Volvemos a consultar.
                 */
                $mpSubscription =
                    $mp->getSubscription(
                        $subscription->provider_subscription_id
                    );

                $finalStatus =
                    $mpSubscription['status'] ?? null;

                if ($finalStatus === 'paused') {
                    $subscription->update([
                        'status' => 'paused',
                        'cancel_at_period_end' => true,
                    ]);

                    $this->dispatch(
                        'subscription-updated'
                    );

                    Notification::make()
                        ->title('Suscripción pausada')
                        ->body(
                            'Podés reactivarla cuando quieras.'
                        )
                        ->success()
                        ->send();

                    return;
                }

                $subscription->update([
                    'status' =>
                        $finalStatus ??
                        $subscription->status,
                ]);

                Notification::make()
                    ->title('Estado actualizado')
                    ->body(
                        'Mercado Pago informa: ' .
                        ($finalStatus ?? 'desconocido')
                    )
                    ->warning()
                    ->send();

                return;
            }

            /*
             * Estado desconocido.
             */
            $subscription->update([
                'status' =>
                    $mpStatus ??
                    $subscription->status,
            ]);

            Notification::make()
                ->title('Estado actualizado')
                ->body(
                    'Mercado Pago informa: ' .
                    ($mpStatus ?? 'desconocido')
                )
                ->warning()
                ->send();

        } catch (\Throwable $e) {
            Log::error(
                'ERROR PAUSANDO SUSCRIPCIÓN',
                [
                    'company_id' =>
                        $company->id,

                    'subscription_id' =>
                        $subscription->id,

                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            Notification::make()
                ->title('No se pudo pausar la suscripción')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * REACTIVA la misma suscripción de Mercado Pago.
     */
    public function resumeSubscription(): void
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

        if (!$subscription->provider_subscription_id) {
            Notification::make()
                ->title('No hay una suscripción de Mercado Pago')
                ->warning()
                ->send();

            return;
        }

        if (in_array(
            $subscription->status,
            ['cancelled', 'canceled'],
            true
        )) {
            Notification::make()
                ->title('No se puede reactivar')
                ->body(
                    'Esta suscripción fue cancelada definitivamente en Mercado Pago.'
                )
                ->danger()
                ->send();

            return;
        }

        if ($subscription->status !== 'paused') {
            Notification::make()
                ->title('La suscripción no está pausada')
                ->warning()
                ->send();

            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            /*
             * Consultamos estado real.
             */
            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $mpStatus =
                $mpSubscription['status'] ?? null;

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

                Notification::make()
                    ->title('No se puede reactivar')
                    ->body(
                        'Mercado Pago ya canceló definitivamente esta suscripción.'
                    )
                    ->danger()
                    ->send();

                return;
            }

            if ($mpStatus !== 'paused') {
                $subscription->update([
                    'status' =>
                        $mpStatus ??
                        $subscription->status,
                ]);

                Notification::make()
                    ->title('Estado actualizado')
                    ->warning()
                    ->send();

                return;
            }

            /*
             * REACTIVAMOS EL MISMO PREAPPROVAL.
             */
            $mp->resumeSubscription(
                $subscription->provider_subscription_id
            );

            /*
             * Confirmamos.
             */
            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $finalStatus =
                $mpSubscription['status'] ?? null;

            if (in_array(
                $finalStatus,
                ['authorized', 'active'],
                true
            )) {
                $subscription->update([
                    'status' => 'authorized',
                    'cancel_at_period_end' => false,
                    'canceled_at' => null,
                ]);

                $this->dispatch(
                    'subscription-updated'
                );

                Notification::make()
                    ->title('Suscripción reactivada')
                    ->body(
                        'La misma suscripción de Mercado Pago volvió a estar activa.'
                    )
                    ->success()
                    ->send();

                return;
            }

            $subscription->update([
                'status' =>
                    $finalStatus ??
                    $subscription->status,
            ]);

            Notification::make()
                ->title('Estado actualizado')
                ->body(
                    'Mercado Pago informa: ' .
                    ($finalStatus ?? 'desconocido')
                )
                ->warning()
                ->send();

        } catch (\Throwable $e) {
            Log::error(
                'ERROR REACTIVANDO SUSCRIPCIÓN',
                [
                    'company_id' =>
                        $company->id,

                    'subscription_id' =>
                        $subscription->id,

                    'provider_subscription_id' =>
                        $subscription->provider_subscription_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            Notification::make()
                ->title('No se pudo reactivar la suscripción')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
