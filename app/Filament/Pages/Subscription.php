<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Subscription as SubscriptionModel;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class Subscription extends Page
{
    protected string $view = 'filament.pages.subscription';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Mi suscripción';

    protected static ?string $title = 'Mi suscripción';

    protected static ?string $slug = 'subscription';

    /**
     * Estados que consideramos "vivos" en Mercado Pago:
     * la suscripción está pagando o a la espera de pagar.
     */
    protected const ACTIVE_STATUSES = [
        'authorized',
        'active',
        'trialing',
        'past_due',
    ];

    /**
     * Estados que bloquean la creación de una suscripción nueva
     * (ya existe algo en curso que hay que resolver primero).
     */
    protected const BLOCKING_STATUSES = [
        'pending',
        'trialing',
        'authorized',
        'active',
        'past_due',
        'paused',
    ];

    protected const CANCELED_STATUSES = [
        'canceled',
        'cancelled',
    ];

    public function mount(): void
    {
        $this->authorizeAndGetCompany();

        $this->syncCurrentSubscription();
    }

    /*
    |--------------------------------------------------------------------
    | Estado computado (para la vista)
    |--------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        $subscription = $this->getActiveSubscription();

        return $subscription !== null
            && in_array($subscription->status, self::ACTIVE_STATUSES, true);
    }

    public function isPending(): bool
    {
        $subscription = $this->getActiveSubscription();

        return $subscription !== null
            && $subscription->status === 'pending';
    }

    public function isPaused(): bool
    {
        $subscription = $this->getActiveSubscription();

        return $subscription !== null
            && $subscription->status === 'paused';
    }

    public function isCanceled(): bool
    {
        $subscription = $this->getActiveSubscription();

        return $subscription !== null
            && in_array($subscription->status, self::CANCELED_STATUSES, true);
    }

    /*
    |--------------------------------------------------------------------
    | Helpers de autorización / notificación
    |--------------------------------------------------------------------
    */

    protected function authorizeAndGetCompany(): Company
    {
        $user = auth()->user();

        abort_unless(
            $user?->isAdmin() || $user?->isSuperAdmin(),
            403
        );

        $company = $user->company;

        abort_unless($company, 403);

        return $company;
    }

    protected function notifySuccess(string $title, ?string $body = null): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }

    protected function notifyWarning(string $title, ?string $body = null): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->send();
    }

    protected function notifyDanger(string $title, ?string $body = null): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();
    }

    /**
     * Registra el error en el log y le muestra al usuario un mensaje
     * entendible en vez de dejar que la excepción reviente en un 500.
     */
    protected function reportFailure(string $logMessage, SubscriptionModel $subscription, Throwable $e, string $userTitle): void
    {
        Log::error($logMessage, [
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'provider_subscription_id' => $subscription->provider_subscription_id,
            'error' => $e->getMessage(),
        ]);

        $this->notifyDanger($userTitle, $e->getMessage());
    }

    /*
    |--------------------------------------------------------------------
    | Consultas
    |--------------------------------------------------------------------
    */

    protected function getPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->whereNotNull('mercadopago_plan_id')
            ->orderBy('id')
            ->first();
    }

    protected function getActiveSubscription(): ?SubscriptionModel
    {
        $company = auth()->user()?->company;

        if (!$company) {
            return null;
        }

        return SubscriptionModel::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [
                ...self::BLOCKING_STATUSES,
                ...self::CANCELED_STATUSES,
            ])
            ->latest('id')
            ->first();
    }

    /*
    |--------------------------------------------------------------------
    | Sincronización con Mercado Pago
    |--------------------------------------------------------------------
    */

    protected function syncCurrentSubscription(): void
    {
        $subscription = $this->getActiveSubscription();

        if (!$subscription || !$subscription->provider_subscription_id) {
            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);

            $this->syncLocalSubscription($subscription, $mpSubscription);
        } catch (Throwable $e) {
            // No bloqueamos el mount() de la página por un problema
            // transitorio de red con Mercado Pago; solo lo dejamos logueado.
            Log::warning('NO SE PUDO SINCRONIZAR SUSCRIPCIÓN CON MERCADO PAGO', [
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
                'provider_subscription_id' => $subscription->provider_subscription_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncLocalSubscription(SubscriptionModel $subscription, array $response): SubscriptionModel
    {
        $providerSubscriptionId = (string) (
            $response['id']
            ?? $subscription->provider_subscription_id
            ?? ''
        );

        $providerPlanId = $response['preapproval_plan_id']
            ?? $subscription->provider_plan_id;

        $externalReference = $response['external_reference']
            ?? $subscription->external_reference;

        $status = $response['status'] ?? $subscription->status;

        if (in_array($status, self::CANCELED_STATUSES, true)) {
            $status = 'canceled';
        }

        $plan = $providerPlanId
            ? SubscriptionPlan::query()->where('mercadopago_plan_id', $providerPlanId)->first()
            : null;

        // Trial
        $trialDays = data_get($response, 'auto_recurring.free_trial.frequency');
        $trialType = data_get($response, 'auto_recurring.free_trial.frequency_type');
        $startDate = data_get($response, 'auto_recurring.start_date');

        $trialEndsAt = $subscription->trial_ends_at;

        if ($trialDays && $trialType === 'days' && $startDate) {
            $trialEndsAt = Carbon::parse($startDate)->addDays((int) $trialDays);
        }

        // Períodos
        $currentPeriodStart = $startDate
            ? Carbon::parse($startDate)
            : $subscription->current_period_start;

        $nextPaymentDate = $response['next_payment_date'] ?? null;

        $currentPeriodEnd = $nextPaymentDate
            ? Carbon::parse($nextPaymentDate)
            : $subscription->current_period_end;

        // Cancelación
        $isCanceled = $status === 'canceled';

        $canceledAt = $isCanceled
            ? ($subscription->canceled_at ?? now())
            : null;

        $subscription->update([
            'provider' => 'mercadopago',
            'provider_subscription_id' => $providerSubscriptionId ?: $subscription->provider_subscription_id,
            'provider_plan_id' => $providerPlanId,
            'external_reference' => $externalReference,
            'plan' => $plan?->slug ?? $subscription->plan,
            'status' => $status,
            'amount' => data_get($response, 'auto_recurring.transaction_amount', $subscription->amount),
            'currency' => data_get($response, 'auto_recurring.currency_id', $subscription->currency),
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $currentPeriodStart,
            'current_period_end' => $currentPeriodEnd,
            'canceled_at' => $canceledAt,
            'cancel_at_period_end' => $status === 'paused',
        ]);

        return $subscription->fresh();
    }

    /*
    |--------------------------------------------------------------------
    | CHECKOUT
    |--------------------------------------------------------------------
    |
    | SIN SUSCRIPCIÓN       -> crea preapproval
    | PENDING SIN ID        -> crea preapproval
    | PENDING CON ID        -> recupera checkout
    | ACTIVA                -> no hace nada
    | PAUSADA               -> reactiva la misma
    | CANCELADA             -> crea una suscripción nueva
    */

    public function checkout(): void
    {
        $company = $this->authorizeAndGetCompany();

        try {
            $plan = $this->getPlan();

            if (!$plan) {
                $this->notifyDanger(
                    'No hay un plan disponible',
                    'No hay ningún plan activo configurado para Ascento. Contactá a soporte.'
                );

                return;
            }

            if (!$plan->mercadopago_plan_id) {
                $this->notifyDanger(
                    'Plan mal configurado',
                    "El plan {$plan->name} no tiene un plan de Mercado Pago configurado."
                );

                return;
            }

            $subscription = DB::transaction(
                fn () => SubscriptionModel::query()
                    ->where('company_id', $company->id)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first()
            );

            if (!$subscription) {
                $subscription = $this->createLocalSubscription($company, $plan);
                $this->startCheckout($subscription, $plan);

                return;
            }

            // Si tenemos un id de MP y el estado es "bloqueante", lo
            // sincronizamos primero para decidir con datos frescos.
            if ($subscription->provider_subscription_id
                && in_array($subscription->status, self::BLOCKING_STATUSES, true)
            ) {
                try {
                    $mp = app(MercadoPagoService::class);
                    $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);
                    $subscription = $this->syncLocalSubscription($subscription, $mpSubscription);
                } catch (Throwable $e) {
                    Log::warning('ERROR SINCRONIZANDO ANTES DEL CHECKOUT', [
                        'company_id' => $company->id,
                        'subscription_id' => $subscription->id,
                        'provider_subscription_id' => $subscription->provider_subscription_id,
                        'error' => $e->getMessage(),
                    ]);
                    // Seguimos con el último estado local conocido.
                }
            }

            if (in_array($subscription->status, self::ACTIVE_STATUSES, true)) {
                $this->notifyWarning('Ya tenés una suscripción activa');

                return;
            }

            if ($subscription->status === 'paused' && $subscription->provider_subscription_id) {
                $this->doResume($subscription);

                return;
            }

            if (in_array($subscription->status, self::CANCELED_STATUSES, true)) {
                $subscription->update([
                    'provider_subscription_id' => null,
                    'provider_plan_id' => $plan->mercadopago_plan_id,
                    'external_reference' => 'company_' . $company->id,
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

                $this->startCheckout($subscription, $plan);

                return;
            }

            if ($subscription->status === 'pending' && !$subscription->provider_subscription_id) {
                $this->startCheckout($subscription, $plan);

                return;
            }

            if ($subscription->status === 'pending' && $subscription->provider_subscription_id) {
                $this->resumePendingCheckout($subscription);

                return;
            }

            $this->notifyDanger(
                'Estado de suscripción no reconocido',
                'Estado actual: ' . ($subscription->status ?? 'desconocido')
            );
        } catch (Throwable $e) {
            Log::error('ERROR INESPERADO EN CHECKOUT', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            $this->notifyDanger(
                'No se pudo iniciar el pago',
                'Ocurrió un error inesperado. Intentá nuevamente en unos minutos.'
            );
        }
    }

    protected function resumePendingCheckout(SubscriptionModel $subscription): void
    {
        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);

            $subscription = $this->syncLocalSubscription($subscription, $mpSubscription);

            if (in_array($subscription->status, self::ACTIVE_STATUSES, true)) {
                $this->notifyWarning('Ya tenés una suscripción activa');

                return;
            }

            $initPoint = $mpSubscription['init_point'] ?? null;

            if ($initPoint) {
                $this->redirect($initPoint, navigate: false);

                return;
            }

            $this->notifyDanger(
                'No se pudo recuperar el checkout',
                'Intentá nuevamente en unos segundos.'
            );
        } catch (Throwable $e) {
            $this->reportFailure(
                'ERROR RECUPERANDO CHECKOUT PENDIENTE',
                $subscription,
                $e,
                'No se pudo recuperar el checkout'
            );
        }
    }

    protected function createLocalSubscription(Company $company, SubscriptionPlan $plan): SubscriptionModel
    {
        return DB::transaction(fn () => SubscriptionModel::create([
            'company_id' => $company->id,
            'provider' => 'mercadopago',
            'provider_subscription_id' => null,
            'provider_plan_id' => $plan->mercadopago_plan_id,
            'external_reference' => 'company_' . $company->id,
            'plan' => $plan->slug,
            'status' => 'pending',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'trial_ends_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'canceled_at' => null,
            'cancel_at_period_end' => false,
        ]));
    }

    /**
     * Crea el preapproval real en Mercado Pago y redirige al checkout.
     * Cualquier fallo se convierte en notificación, nunca en un 500.
     */
    protected function startCheckout(SubscriptionModel $subscription, SubscriptionPlan $plan): void
    {
        try {
            $mp = app(MercadoPagoService::class);

            $mpPlan = $mp->getSubscriptionPlan($plan->mercadopago_plan_id);

            $initPoint = $mpPlan['init_point'] ?? null;

            if (!$initPoint) {
                throw new RuntimeException('Mercado Pago no devolvió el checkout del plan.');
            }

            $this->redirect($initPoint, navigate: false);
        } catch (Throwable $e) {
            $this->reportFailure(
                'ERROR CREANDO CHECKOUT EN MERCADO PAGO',
                $subscription,
                $e,
                'No se pudo iniciar el pago'
            );
        }
    }

    /*
    |--------------------------------------------------------------------
    | PAUSAR (reversible)
    |--------------------------------------------------------------------
    */

    public function pauseSubscription(): void
    {
        $this->authorizeAndGetCompany();

        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            $this->notifyWarning('No hay una suscripción');

            return;
        }

        if (!$subscription->provider_subscription_id) {
            $this->notifyWarning('No hay una suscripción de Mercado Pago');

            return;
        }

        if ($subscription->status === 'paused') {
            $this->notifyWarning('La suscripción ya está pausada');

            return;
        }

        if (in_array($subscription->status, self::CANCELED_STATUSES, true)) {
            $this->notifyWarning('La suscripción ya está cancelada');

            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);
            $mpStatus = $mpSubscription['status'] ?? null;

            if (in_array($mpStatus, self::CANCELED_STATUSES, true)) {
                $this->syncLocalSubscription($subscription, $mpSubscription);
                $this->notifyWarning('Suscripción cancelada', 'Mercado Pago ya la había cancelado.');

                return;
            }

            if ($mpStatus === 'paused') {
                $this->syncLocalSubscription($subscription, $mpSubscription);
                $this->notifyWarning('Suscripción pausada');

                return;
            }

            if (!in_array($mpStatus, self::ACTIVE_STATUSES, true)) {
                $this->notifyWarning(
                    'No se puede pausar',
                    'Mercado Pago informa: ' . ($mpStatus ?? 'desconocido')
                );

                return;
            }

            $mp->pauseSubscription($subscription->provider_subscription_id);

            $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);
            $subscription = $this->syncLocalSubscription($subscription, $mpSubscription);

            if ($subscription->status === 'paused') {
                $this->dispatch('subscription-updated');
                $this->notifySuccess('Suscripción pausada', 'Podés reactivarla cuando quieras.');

                return;
            }

            $this->notifyWarning(
                'Estado actualizado',
                'Mercado Pago informa: ' . ($subscription->status ?? 'desconocido')
            );
        } catch (Throwable $e) {
            $this->reportFailure(
                'ERROR PAUSANDO SUSCRIPCIÓN',
                $subscription,
                $e,
                'No se pudo pausar la suscripción'
            );
        }
    }

    /*
    |--------------------------------------------------------------------
    | CANCELAR (definitivo, irreversible)
    |--------------------------------------------------------------------
    */

    public function cancelSubscription(): void
    {
        $this->authorizeAndGetCompany();

        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            $this->notifyWarning('No hay una suscripción');

            return;
        }

        if (!$subscription->provider_subscription_id) {
            $this->notifyWarning('No hay una suscripción de Mercado Pago');

            return;
        }

        if (in_array($subscription->status, self::CANCELED_STATUSES, true)) {
            $this->notifyWarning('La suscripción ya está cancelada');

            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->cancelSubscription($subscription->provider_subscription_id);

            $subscription = $this->syncLocalSubscription($subscription, $mpSubscription);

            if (in_array($subscription->status, self::CANCELED_STATUSES, true)) {
                $this->dispatch('subscription-updated');
                $this->notifySuccess(
                    'Suscripción cancelada',
                    'La cancelación es definitiva. Para volver a suscribirte vas a tener que empezar un checkout nuevo.'
                );

                return;
            }

            $this->notifyWarning(
                'Estado actualizado',
                'Mercado Pago informa: ' . ($subscription->status ?? 'desconocido')
            );
        } catch (Throwable $e) {
            $this->reportFailure(
                'ERROR CANCELANDO SUSCRIPCIÓN',
                $subscription,
                $e,
                'No se pudo cancelar la suscripción'
            );
        }
    }

    /*
    |--------------------------------------------------------------------
    | REACTIVAR
    |--------------------------------------------------------------------
    */

    public function resumeSubscription(): void
    {
        $this->authorizeAndGetCompany();

        $subscription = $this->getActiveSubscription();

        if (!$subscription) {
            $this->notifyWarning('No hay una suscripción');

            return;
        }

        $this->doResume($subscription);
    }

    protected function doResume(SubscriptionModel $subscription): void
    {
        if (!$subscription->provider_subscription_id) {
            $this->notifyWarning('No hay una suscripción de Mercado Pago');

            return;
        }

        if (in_array($subscription->status, self::CANCELED_STATUSES, true)) {
            $this->notifyDanger(
                'No se puede reactivar',
                'Esta suscripción fue cancelada definitivamente. Debés crear una nueva.'
            );

            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);
            $mpStatus = $mpSubscription['status'] ?? null;

            if (in_array($mpStatus, self::CANCELED_STATUSES, true)) {
                $this->syncLocalSubscription($subscription, $mpSubscription);
                $this->notifyDanger(
                    'No se puede reactivar',
                    'Mercado Pago canceló definitivamente esta suscripción.'
                );

                return;
            }

            if (in_array($mpStatus, ['authorized', 'active'], true)) {
                $this->syncLocalSubscription($subscription, $mpSubscription);
                $this->notifySuccess('La suscripción ya está activa');

                return;
            }

            if ($mpStatus !== 'paused') {
                $this->syncLocalSubscription($subscription, $mpSubscription);
                $this->notifyWarning(
                    'No se puede reactivar',
                    'Mercado Pago informa: ' . ($mpStatus ?? 'desconocido')
                );

                return;
            }

            $mp->resumeSubscription($subscription->provider_subscription_id);

            $mpSubscription = $mp->getSubscription($subscription->provider_subscription_id);
            $subscription = $this->syncLocalSubscription($subscription, $mpSubscription);

            if (in_array($subscription->status, ['authorized', 'active'], true)) {
                $this->dispatch('subscription-updated');
                $this->notifySuccess(
                    'Suscripción reactivada',
                    'La misma suscripción de Mercado Pago volvió a estar activa.'
                );

                return;
            }

            $this->notifyWarning(
                'Estado actualizado',
                'Mercado Pago informa: ' . ($subscription->status ?? 'desconocido')
            );
        } catch (Throwable $e) {
            $this->reportFailure(
                'ERROR REACTIVANDO SUSCRIPCIÓN',
                $subscription,
                $e,
                'No se pudo reactivar la suscripción'
            );
        }
    }
}
