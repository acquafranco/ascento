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


    /*
    |--------------------------------------------------------------------------
    | ESTADOS
    |--------------------------------------------------------------------------
    */

    protected const ACTIVE_STATUSES = [
        'authorized',
        'active',
        'trialing',
        'past_due',
    ];

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


    /*
    |--------------------------------------------------------------------------
    | MOUNT
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->authorizeAndGetCompany();

        $this->syncCurrentSubscription();
    }


    /*
    |--------------------------------------------------------------------------
    | ESTADOS PARA LA VISTA
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        $subscription = $this->getActiveSubscription();

        return $subscription !== null
            && in_array(
                $subscription->status,
                self::ACTIVE_STATUSES,
                true
            );
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
            && in_array(
                $subscription->status,
                self::CANCELED_STATUSES,
                true
            );
    }


    /*
    |--------------------------------------------------------------------------
    | AUTORIZACIÓN
    |--------------------------------------------------------------------------
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


    /*
    |--------------------------------------------------------------------------
    | NOTIFICACIONES
    |--------------------------------------------------------------------------
    */

    protected function notifySuccess(
        string $title,
        ?string $body = null
    ): void {
        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }

    protected function notifyWarning(
        string $title,
        ?string $body = null
    ): void {
        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->send();
    }

    protected function notifyDanger(
        string $title,
        ?string $body = null
    ): void {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();
    }

    protected function reportFailure(
        string $logMessage,
        SubscriptionModel $subscription,
        Throwable $e,
        string $userTitle
    ): void {
        Log::error($logMessage, [
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'provider_subscription_id' => $subscription->provider_subscription_id,
            'error' => $e->getMessage(),
        ]);

        $this->notifyDanger(
            $userTitle,
            $e->getMessage()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PLAN
    |--------------------------------------------------------------------------
    */

    protected function getPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->whereNotNull('mercadopago_plan_id')
            ->orderBy('id')
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | SUSCRIPCIÓN LOCAL
    |--------------------------------------------------------------------------
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
                ...self::BLOCKING_STATUSES,
                ...self::CANCELED_STATUSES,
            ])
            ->latest('id')
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | SINCRONIZACIÓN MERCADO PAGO -> BASE LOCAL
    |--------------------------------------------------------------------------
    */

    protected function syncCurrentSubscription(): void
    {
        $subscription = $this->getActiveSubscription();

        if (
            !$subscription ||
            !$subscription->provider_subscription_id
        ) {
            return;
        }

        try {
            $mp = app(MercadoPagoService::class);

            $response = $mp->getSubscription(
                $subscription->provider_subscription_id
            );

            $this->syncLocalSubscription(
                $subscription,
                $response
            );
        } catch (Throwable $e) {

            Log::warning(
                'NO SE PUDO SINCRONIZAR SUSCRIPCIÓN CON MERCADO PAGO',
                [
                    'company_id' => $subscription->company_id,
                    'subscription_id' => $subscription->id,
                    'provider_subscription_id' => $subscription->provider_subscription_id,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }


    protected function syncLocalSubscription(
        SubscriptionModel $subscription,
        array $response
    ): SubscriptionModel {

        $providerSubscriptionId = (string) (
            $response['id']
            ?? $subscription->provider_subscription_id
            ?? ''
        );

        $providerPlanId =
            $response['preapproval_plan_id']
            ?? $subscription->provider_plan_id;

        $externalReference =
            $response['external_reference']
            ?? $subscription->external_reference;

        $status =
            $response['status']
            ?? $subscription->status;


        /*
        |--------------------------------------------------------------------------
        | NORMALIZAR CANCELACIÓN
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $status,
                self::CANCELED_STATUSES,
                true
            )
        ) {
            $status = 'canceled';
        }


        /*
        |--------------------------------------------------------------------------
        | PLAN
        |--------------------------------------------------------------------------
        */

        $plan = $providerPlanId
            ? SubscriptionPlan::query()
                ->where(
                    'mercadopago_plan_id',
                    $providerPlanId
                )
                ->first()
            : null;


        /*
        |--------------------------------------------------------------------------
        | TRIAL
        |--------------------------------------------------------------------------
        */

        $trialDays = data_get(
            $response,
            'auto_recurring.free_trial.frequency'
        );

        $trialType = data_get(
            $response,
            'auto_recurring.free_trial.frequency_type'
        );

        $startDate = data_get(
            $response,
            'auto_recurring.start_date'
        );

        $trialEndsAt = $subscription->trial_ends_at;

        if (
            $trialDays &&
            $trialType === 'days' &&
            $startDate
        ) {
            $trialEndsAt = Carbon::parse($startDate)
                ->addDays((int) $trialDays);
        }


        /*
        |--------------------------------------------------------------------------
        | PERÍODOS
        |--------------------------------------------------------------------------
        */

        $currentPeriodStart = $startDate
            ? Carbon::parse($startDate)
            : $subscription->current_period_start;

        $nextPaymentDate =
            $response['next_payment_date']
            ?? null;

        $currentPeriodEnd = $nextPaymentDate
            ? Carbon::parse($nextPaymentDate)
            : $subscription->current_period_end;


        /*
        |--------------------------------------------------------------------------
        | CANCELACIÓN
        |--------------------------------------------------------------------------
        */

        $isCanceled = $status === 'canceled';

        $canceledAt = $isCanceled
            ? ($subscription->canceled_at ?? now())
            : null;


        /*
        |--------------------------------------------------------------------------
        | GUARDAR
        |--------------------------------------------------------------------------
        */

        $subscription->update([
            'provider' => 'mercadopago',

            'provider_subscription_id' =>
                $providerSubscriptionId
                ?: $subscription->provider_subscription_id,

            'provider_plan_id' =>
                $providerPlanId,

            'external_reference' =>
                $externalReference,

            'plan' =>
                $plan?->slug
                ?? $subscription->plan,

            'status' =>
                $status,

            'amount' =>
                data_get(
                    $response,
                    'auto_recurring.transaction_amount',
                    $subscription->amount
                ),

            'currency' =>
                data_get(
                    $response,
                    'auto_recurring.currency_id',
                    $subscription->currency
                ),

            'trial_ends_at' =>
                $trialEndsAt,

            'current_period_start' =>
                $currentPeriodStart,

            'current_period_end' =>
                $currentPeriodEnd,

            'canceled_at' =>
                $canceledAt,

            'cancel_at_period_end' =>
                $status === 'paused',
        ]);

        return $subscription->fresh();
    }


    /*
    |--------------------------------------------------------------------------
    | CHECKOUT
    |--------------------------------------------------------------------------
    */

    public function checkout(): void
    {
        $company = $this->authorizeAndGetCompany();

        try {

            $plan = $this->getPlan();

            if (!$plan) {

                $this->notifyDanger(
                    'No hay un plan disponible',
                    'No hay ningún plan activo configurado para Ascento.'
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


            /*
            |--------------------------------------------------------------------------
            | OBTENER ÚLTIMA SUSCRIPCIÓN
            |--------------------------------------------------------------------------
            */

            $subscription = DB::transaction(
                fn () => SubscriptionModel::query()
                    ->where(
                        'company_id',
                        $company->id
                    )
                    ->latest('id')
                    ->lockForUpdate()
                    ->first()
            );


            /*
            |--------------------------------------------------------------------------
            | NO EXISTE
            |--------------------------------------------------------------------------
            */

            if (!$subscription) {

                $subscription =
                    $this->createLocalSubscription(
                        $company,
                        $plan
                    );

                $this->startCheckout(
                    $subscription,
                    $plan
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | SINCRONIZAR ANTES DE DECIDIR
            |--------------------------------------------------------------------------
            */

            if (
                $subscription->provider_subscription_id &&
                in_array(
                    $subscription->status,
                    self::BLOCKING_STATUSES,
                    true
                )
            ) {

                try {

                    $mp = app(
                        MercadoPagoService::class
                    );

                    $response =
                        $mp->getSubscription(
                            $subscription->provider_subscription_id
                        );

                    $subscription =
                        $this->syncLocalSubscription(
                            $subscription,
                            $response
                        );

                } catch (Throwable $e) {

                    Log::warning(
                        'ERROR SINCRONIZANDO ANTES DEL CHECKOUT',
                        [
                            'company_id' => $company->id,
                            'subscription_id' => $subscription->id,
                            'provider_subscription_id' => $subscription->provider_subscription_id,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | ACTIVA
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $subscription->status,
                    self::ACTIVE_STATUSES,
                    true
                )
            ) {

                $this->notifyWarning(
                    'Ya tenés una suscripción activa'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | PAUSADA
            |--------------------------------------------------------------------------
            */

            if (
                $subscription->status === 'paused' &&
                $subscription->provider_subscription_id
            ) {

                $this->doResume(
                    $subscription
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | CANCELADA
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $subscription->status,
                    self::CANCELED_STATUSES,
                    true
                )
            ) {

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

                $this->startCheckout(
                    $subscription,
                    $plan
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | PENDING SIN ID
            |--------------------------------------------------------------------------
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


            /*
            |--------------------------------------------------------------------------
            | PENDING CON ID
            |--------------------------------------------------------------------------
            */

            if (
                $subscription->status === 'pending' &&
                $subscription->provider_subscription_id
            ) {

                $this->resumePendingCheckout(
                    $subscription
                );

                return;
            }


            $this->notifyDanger(
                'Estado de suscripción no reconocido',
                'Estado actual: '
                . ($subscription->status ?? 'desconocido')
            );

        } catch (Throwable $e) {

            Log::error(
                'ERROR INESPERADO EN CHECKOUT',
                [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]
            );

            $this->notifyDanger(
                'No se pudo iniciar el pago',
                'Ocurrió un error inesperado. Intentá nuevamente.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CHECKOUT PENDIENTE
    |--------------------------------------------------------------------------
    */

    protected function resumePendingCheckout(
        SubscriptionModel $subscription
    ): void {

        try {

            $mp = app(
                MercadoPagoService::class
            );

            $response =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $response
                );


            if (
                in_array(
                    $subscription->status,
                    self::ACTIVE_STATUSES,
                    true
                )
            ) {

                $this->notifyWarning(
                    'Ya tenés una suscripción activa'
                );

                return;
            }


            $initPoint =
                $response['init_point']
                ?? null;

            if ($initPoint) {

                $this->redirect(
                    $initPoint,
                    navigate: false
                );

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


    /*
    |--------------------------------------------------------------------------
    | CREAR SUSCRIPCIÓN LOCAL
    |--------------------------------------------------------------------------
    */

    protected function createLocalSubscription(
        Company $company,
        SubscriptionPlan $plan
    ): SubscriptionModel {

        return DB::transaction(
            fn () => SubscriptionModel::create([
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
            ])
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INICIAR CHECKOUT
    |--------------------------------------------------------------------------
    */

    protected function startCheckout(
        SubscriptionModel $subscription,
        SubscriptionPlan $plan
    ): void {

        try {

            $mp = app(
                MercadoPagoService::class
            );

            $mpPlan =
                $mp->getSubscriptionPlan(
                    $plan->mercadopago_plan_id
                );

            $initPoint =
                $mpPlan['init_point']
                ?? null;

            if (!$initPoint) {

                throw new RuntimeException(
                    'Mercado Pago no devolvió el checkout del plan.'
                );
            }

            $this->redirect(
                $initPoint,
                navigate: false
            );

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
    |--------------------------------------------------------------------------
    | PAUSAR
    |--------------------------------------------------------------------------
    */

    public function pauseSubscription(): void
    {
        $this->authorizeAndGetCompany();

        $subscription =
            $this->getActiveSubscription();

        if (!$subscription) {

            $this->notifyWarning(
                'No hay una suscripción'
            );

            return;
        }

        if (!$subscription->provider_subscription_id) {

            $this->notifyWarning(
                'No hay una suscripción de Mercado Pago'
            );

            return;
        }

        if ($subscription->status === 'paused') {

            $this->notifyWarning(
                'La suscripción ya está pausada'
            );

            return;
        }

        if (
            in_array(
                $subscription->status,
                self::CANCELED_STATUSES,
                true
            )
        ) {

            $this->notifyWarning(
                'La suscripción ya está cancelada'
            );

            return;
        }

        try {

            $mp = app(
                MercadoPagoService::class
            );


            /*
            |--------------------------------------------------------------------------
            | ESTADO REAL DE MERCADO PAGO
            |--------------------------------------------------------------------------
            */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $mpStatus =
                $mpSubscription['status']
                ?? null;


            /*
            |--------------------------------------------------------------------------
            | YA CANCELADA
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $mpStatus,
                    self::CANCELED_STATUSES,
                    true
                )
            ) {

                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                $this->notifyWarning(
                    'Suscripción cancelada',
                    'Mercado Pago ya la había cancelado.'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | YA PAUSADA
            |--------------------------------------------------------------------------
            */

            if ($mpStatus === 'paused') {

                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                $this->notifyWarning(
                    'Suscripción pausada'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | NO SE PUEDE PAUSAR
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $mpStatus,
                    self::ACTIVE_STATUSES,
                    true
                )
            ) {

                $this->notifyWarning(
                    'No se puede pausar',
                    'Mercado Pago informa: '
                    . ($mpStatus ?? 'desconocido')
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | PAUSAR
            |--------------------------------------------------------------------------
            */

            $mp->pauseSubscription(
                $subscription->provider_subscription_id
            );


            /*
            |--------------------------------------------------------------------------
            | VOLVER A CONSULTAR
            |--------------------------------------------------------------------------
            */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );


            if ($subscription->status === 'paused') {

                $this->dispatch(
                    'subscription-updated'
                );

                $this->notifySuccess(
                    'Suscripción pausada',
                    'Podés reactivarla cuando quieras.'
                );

                return;
            }


            $this->notifyWarning(
                'Estado actualizado',
                'Mercado Pago informa: '
                . ($subscription->status ?? 'desconocido')
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
    |--------------------------------------------------------------------------
    | CANCELAR
    |--------------------------------------------------------------------------
    */

    public function cancelSubscription(): void
    {
        $this->authorizeAndGetCompany();

        $subscription =
            $this->getActiveSubscription();

        if (!$subscription) {

            $this->notifyWarning(
                'No hay una suscripción'
            );

            return;
        }

        if (!$subscription->provider_subscription_id) {

            $this->notifyWarning(
                'No hay una suscripción de Mercado Pago'
            );

            return;
        }

        if (
            in_array(
                $subscription->status,
                self::CANCELED_STATUSES,
                true
            )
        ) {

            $this->notifyWarning(
                'La suscripción ya está cancelada'
            );

            return;
        }


        try {

            $mp = app(
                MercadoPagoService::class
            );


            /*
            |--------------------------------------------------------------------------
            | CONSULTAR ESTADO REAL
            |--------------------------------------------------------------------------
            */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $mpStatus =
                $mpSubscription['status']
                ?? null;


            /*
            |--------------------------------------------------------------------------
            | YA CANCELADA
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $mpStatus,
                    self::CANCELED_STATUSES,
                    true
                )
            ) {

                $subscription =
                    $this->syncLocalSubscription(
                        $subscription,
                        $mpSubscription
                    );

                $this->notifyWarning(
                    'La suscripción ya estaba cancelada'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | PAUSADA
            |--------------------------------------------------------------------------
            |
            | IMPORTANTE:
            |
            | El MercadoPagoService que pasaste actualmente
            | NO permite cancelar una suscripción pausada.
            |
            | Por lo tanto, no intentamos llamar a cancelSubscription()
            | directamente.
            |
            */

            if ($mpStatus === 'paused') {

                $this->notifyWarning(
                    'La suscripción está pausada',
                    'Primero reactivala y luego podrás cancelarla definitivamente.'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDAR ESTADO
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $mpStatus,
                    self::ACTIVE_STATUSES,
                    true
                )
            ) {

                $this->notifyWarning(
                    'No se puede cancelar',
                    'Mercado Pago informa: '
                    . ($mpStatus ?? 'desconocido')
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | CANCELAR
            |--------------------------------------------------------------------------
            */

            $mpSubscription =
                $mp->cancelSubscription(
                    $subscription->provider_subscription_id
                );


            /*
            |--------------------------------------------------------------------------
            | SINCRONIZAR
            |--------------------------------------------------------------------------
            */

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );


            if (
                in_array(
                    $subscription->status,
                    self::CANCELED_STATUSES,
                    true
                )
            ) {

                $this->dispatch(
                    'subscription-updated'
                );

                $this->notifySuccess(
                    'Suscripción cancelada',
                    'La cancelación es definitiva. Para volver a suscribirte vas a tener que iniciar un checkout nuevo.'
                );

                return;
            }


            $this->notifyWarning(
                'Estado actualizado',
                'Mercado Pago informa: '
                . ($subscription->status ?? 'desconocido')
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
    |--------------------------------------------------------------------------
    | REACTIVAR
    |--------------------------------------------------------------------------
    */

    public function resumeSubscription(): void
    {
        $this->authorizeAndGetCompany();

        $subscription =
            $this->getActiveSubscription();

        if (!$subscription) {

            $this->notifyWarning(
                'No hay una suscripción'
            );

            return;
        }

        $this->doResume(
            $subscription
        );
    }


    protected function doResume(
        SubscriptionModel $subscription
    ): void {

        if (!$subscription->provider_subscription_id) {

            $this->notifyWarning(
                'No hay una suscripción de Mercado Pago'
            );

            return;
        }


        if (
            in_array(
                $subscription->status,
                self::CANCELED_STATUSES,
                true
            )
        ) {

            $this->notifyDanger(
                'No se puede reactivar',
                'Esta suscripción fue cancelada definitivamente. Debés crear una nueva.'
            );

            return;
        }


        try {

            $mp = app(
                MercadoPagoService::class
            );


            /*
            |--------------------------------------------------------------------------
            | ESTADO REAL
            |--------------------------------------------------------------------------
            */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $mpStatus =
                $mpSubscription['status']
                ?? null;


            /*
            |--------------------------------------------------------------------------
            | CANCELADA
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $mpStatus,
                    self::CANCELED_STATUSES,
                    true
                )
            ) {

                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                $this->notifyDanger(
                    'No se puede reactivar',
                    'Mercado Pago canceló definitivamente esta suscripción.'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | YA ACTIVA
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $mpStatus,
                    ['authorized', 'active'],
                    true
                )
            ) {

                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                $this->notifySuccess(
                    'La suscripción ya está activa'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | NO ESTÁ PAUSADA
            |--------------------------------------------------------------------------
            */

            if ($mpStatus !== 'paused') {

                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                $this->notifyWarning(
                    'No se puede reactivar',
                    'Mercado Pago informa: '
                    . ($mpStatus ?? 'desconocido')
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | REACTIVAR
            |--------------------------------------------------------------------------
            */

            $mp->resumeSubscription(
                $subscription->provider_subscription_id
            );


            /*
            |--------------------------------------------------------------------------
            | VOLVER A CONSULTAR
            |--------------------------------------------------------------------------
            */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription->provider_subscription_id
                );

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );


            if (
                in_array(
                    $subscription->status,
                    ['authorized', 'active'],
                    true
                )
            ) {

                $this->dispatch(
                    'subscription-updated'
                );

                $this->notifySuccess(
                    'Suscripción reactivada',
                    'La misma suscripción de Mercado Pago volvió a estar activa.'
                );

                return;
            }


            $this->notifyWarning(
                'Estado actualizado',
                'Mercado Pago informa: '
                . ($subscription->status ?? 'desconocido')
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
