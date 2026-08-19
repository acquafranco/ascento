<?php

namespace App\Filament\Pages;

use App\Models\Subscription as SubscriptionModel;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class Subscription extends Page
{
    protected string $view =
        'filament.pages.subscription';

    protected static string|\BackedEnum|null $navigationIcon =
        'heroicon-o-credit-card';

    protected static ?string $navigationLabel =
        'Mi suscripción';

    protected static ?string $title =
        'Mi suscripción';

    protected static ?string $slug =
        'subscription';

    /**
     * Estados que indican que no debe crearse
     * otra suscripción.
     */
    protected array $blockingStatuses = [
        'pending',
        'trialing',
        'authorized',
        'active',
        'past_due',
        'paused',
    ];

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->isAdmin()
            || auth()->user()?->isSuperAdmin(),
            403
        );

        $this->syncCurrentSubscription();
    }

        public function isActive(): bool
    {
        $subscription = $this->getActiveSubscription();

        return $subscription !== null
            && in_array(
                $subscription->status,
                [
                    'authorized',
                    'active',
                    'trialing',
                    'past_due',
                ],
                true
            );
    }

    public function isPending(): bool
{
    $subscription = $this->getActiveSubscription();

    return $subscription !== null
        && $subscription->status === 'pending';
}
    /**
     * Obtiene el plan activo.
     */
    protected function getPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where(
                'is_active',
                true
            )
            ->whereNotNull(
                'mercadopago_plan_id'
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * Obtiene la última suscripción de la empresa.
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
            'pending',
            'trialing',
            'authorized',
            'active',
            'past_due',
            'paused',
            'canceled',
            'cancelled',
        ])
        ->latest('id')
        ->first();
}

    /**
     * Sincroniza la suscripción local
     * con Mercado Pago.
     */
    protected function syncCurrentSubscription(): void
    {
        $subscription =
            $this->getActiveSubscription();

        if (
            !$subscription
            || !$subscription->provider_subscription_id
        ) {
            return;
        }

        try {
            $mp =
                app(MercadoPagoService::class);

            $mpSubscription =
                $mp->getSubscription(
                    $subscription
                        ->provider_subscription_id
                );

            $this->syncLocalSubscription(
                $subscription,
                $mpSubscription
            );

        } catch (\Throwable $e) {
            Log::warning(
                'NO SE PUDO SINCRONIZAR SUSCRIPCION CON MERCADO PAGO',
                [
                    'company_id' =>
                        $subscription->company_id,

                    'subscription_id' =>
                        $subscription->id,

                    'provider_subscription_id' =>
                        $subscription
                            ->provider_subscription_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Sincronización local idéntica a la del Controller.
     */
    protected function syncLocalSubscription(
        SubscriptionModel $subscription,
        array $response
    ): SubscriptionModel {
        $providerSubscriptionId =
            (string) (
                $response['id']
                ?? $subscription
                    ->provider_subscription_id
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

        if (in_array(
            $status,
            ['cancelled', 'canceled'],
            true
        )) {
            $status = 'canceled';
        }

        $plan = null;

        if ($providerPlanId) {
            $plan =
                SubscriptionPlan::query()
                    ->where(
                        'mercadopago_plan_id',
                        $providerPlanId
                    )
                    ->first();
        }

        /*
         * ==========================================================
         * TRIAL
         * ==========================================================
         */

        $trialEndsAt =
            $subscription->trial_ends_at;

        $trialDays =
            data_get(
                $response,
                'auto_recurring.free_trial.frequency'
            );

        $trialType =
            data_get(
                $response,
                'auto_recurring.free_trial.frequency_type'
            );

        $startDate =
            data_get(
                $response,
                'auto_recurring.start_date'
            );

        if (
            $trialDays
            && $trialType === 'days'
            && $startDate
        ) {
            $trialEndsAt =
                Carbon::parse(
                    $startDate
                )->addDays(
                    (int) $trialDays
                );
        }

        /*
         * ==========================================================
         * PERÍODOS
         * ==========================================================
         */

        $currentPeriodStart =
            $startDate
                ? Carbon::parse(
                    $startDate
                )
                : $subscription
                    ->current_period_start;

        $nextPaymentDate =
            $response['next_payment_date']
            ?? null;

        $currentPeriodEnd =
            $nextPaymentDate
                ? Carbon::parse(
                    $nextPaymentDate
                )
                : $subscription
                    ->current_period_end;

        /*
         * ==========================================================
         * CANCELACIÓN
         * ==========================================================
         */

        $isCanceled =
            $status === 'canceled';

        $canceledAt =
            $isCanceled
                ? (
                    $subscription->canceled_at
                    ?? now()
                )
                : null;

        /*
         * ==========================================================
         * ACTUALIZACIÓN
         * ==========================================================
         */

        $subscription->update([
            'provider' =>
                'mercadopago',

            'provider_subscription_id' =>
                $providerSubscriptionId
                    ?: $subscription
                        ->provider_subscription_id,

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
                $status === 'paused'
                    ? true
                    : false,
        ]);

        return $subscription->fresh();
    }

    /**
     * ==============================================================
     * CHECKOUT
     * ==============================================================
     *
     * Flujo:
     *
     * SIN SUSCRIPCIÓN
     *     -> crea preapproval
     *
     * PENDING SIN ID
     *     -> crea preapproval
     *
     * PENDING CON ID
     *     -> recupera checkout
     *
     * ACTIVE
     *     -> no hace nada
     *
     * PAUSED
     *     -> reactiva la misma
     *
     * CANCELED
     *     -> crea una nueva suscripción
     */
    public function checkout(): void
    {
        $user =
            auth()->user();

        abort_unless(
            $user?->isAdmin()
            || $user?->isSuperAdmin(),
            403
        );

        $company =
            $user->company;

        abort_unless(
            $company,
            403
        );

        $plan =
            $this->getPlan();

        if (!$plan) {
            throw new RuntimeException(
                'No hay ningún plan activo configurado para Ascento.'
            );
        }

        if (!$plan->mercadopago_plan_id) {
            throw new RuntimeException(
                "El plan {$plan->name} no tiene un plan de Mercado Pago configurado."
            );
        }

        /*
         * ==========================================================
         * BUSCAR SUSCRIPCIÓN EXISTENTE
         * ==========================================================
         */

        $subscription =
            DB::transaction(
                function () use ($company) {
                    return SubscriptionModel::query()
                        ->where(
                            'company_id',
                            $company->id
                        )
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();
                }
            );

        /*
         * ==========================================================
         * NO EXISTE
         * ==========================================================
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
         * ==========================================================
         * SINCRONIZAR SI TENEMOS ID
         * ==========================================================
         */

        if (
            $subscription->provider_subscription_id
            && in_array(
                $subscription->status,
                $this->blockingStatuses,
                true
            )
        ) {
            try {
                $mp =
                    app(MercadoPagoService::class);

                $mpSubscription =
                    $mp->getSubscription(
                        $subscription
                            ->provider_subscription_id
                    );

                $subscription =
                    $this->syncLocalSubscription(
                        $subscription,
                        $mpSubscription
                    );
            } catch (\Throwable $e) {
                Log::warning(
                    'ERROR SINCRONIZANDO ANTES DEL CHECKOUT',
                    [
                        'company_id' =>
                            $company->id,

                        'subscription_id' =>
                            $subscription->id,

                        'provider_subscription_id' =>
                            $subscription
                                ->provider_subscription_id,

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }
        }

        /*
         * ==========================================================
         * ACTIVA
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
                ->title(
                    'Ya tenés una suscripción activa'
                )
                ->warning()
                ->send();

            return;
        }

        /*
         * ==========================================================
         * PAUSADA
         * ==========================================================
         */

        if (
            $subscription->status === 'paused'
            && $subscription->provider_subscription_id
        ) {
            $this->resumeSubscription();

            return;
        }

        /*
         * ==========================================================
         * CANCELADA
         * ==========================================================
         *
         * Una cancelada NO se reutiliza.
         *
         * Se limpia el registro local y se genera
         * un nuevo checkout de Mercado Pago.
         */

        if (in_array(
            $subscription->status,
            [
                'canceled',
                'cancelled',
            ],
            true
        )) {
            $subscription->update([
                'provider_subscription_id' =>
                    null,

                'provider_plan_id' =>
                    $plan->mercadopago_plan_id,

                'external_reference' =>
                    'company_' .
                    $company->id,

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
         * PENDING SIN ID
         * ==========================================================
         */

        if (
            $subscription->status === 'pending'
            && !$subscription->provider_subscription_id
        ) {
            $this->startCheckout(
                $subscription,
                $plan
            );

            return;
        }

        /*
         * ==========================================================
         * PENDING CON ID
         * ==========================================================
         */

        if (
            $subscription->status === 'pending'
            && $subscription->provider_subscription_id
        ) {
            try {
                $mp =
                    app(MercadoPagoService::class);

                $mpSubscription =
                    $mp->getSubscription(
                        $subscription
                            ->provider_subscription_id
                    );

                $subscription =
                    $this->syncLocalSubscription(
                        $subscription,
                        $mpSubscription
                    );

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
                        ->title(
                            'Ya tenés una suscripción activa'
                        )
                        ->warning()
                        ->send();

                    return;
                }

                $initPoint =
                    $mpSubscription['init_point']
                    ?? null;

                if ($initPoint) {
                    $this->redirect(
                        $initPoint,
                        navigate: false
                    );

                    return;
                }

            } catch (\Throwable $e) {
                Log::warning(
                    'ERROR RECUPERANDO CHECKOUT PENDIENTE',
                    [
                        'company_id' =>
                            $company->id,

                        'subscription_id' =>
                            $subscription->id,

                        'provider_subscription_id' =>
                            $subscription
                                ->provider_subscription_id,

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }

            Notification::make()
                ->title(
                    'No se pudo recuperar el checkout'
                )
                ->body(
                    'Intentá nuevamente en unos segundos.'
                )
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(
                'Estado de suscripción no reconocido'
            )
            ->body(
                'Estado actual: '
                . (
                    $subscription->status
                    ?? 'desconocido'
                )
            )
            ->danger()
            ->send();
    }

    /**
     * Crea el registro local.
     */
    protected function createLocalSubscription(
        $company,
        SubscriptionPlan $plan
    ): SubscriptionModel {
        return DB::transaction(
            function () use (
                $company,
                $plan
            ) {
                return SubscriptionModel::create([
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
                        $company->id,

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
            }
        );
    }

    /**
     * ==============================================================
     * CREA PREAPPROVAL REAL EN MERCADO PAGO
     * ==============================================================
     */
    protected function startCheckout(
        SubscriptionModel $subscription,
        SubscriptionPlan $plan
    ): void {
        try {
            $mp =
                app(MercadoPagoService::class);

            $externalReference =
                'company_' .
                $subscription->company_id;

            /*
             * IMPORTANTE:
             *
             * NO usamos getSubscriptionPlan()
             * para obtener el init_point.
             *
             * Creamos una suscripción real /preapproval.
             */

            $mpSubscription =
                $mp->createSubscription([
                    'preapproval_plan_id' =>
                        $plan->mercadopago_plan_id,

                    'reason' =>
                        'Suscripción Ascento',

                    'external_reference' =>
                        $externalReference,

                    'back_url' => url('/admin/subscription'),

                    'status' =>
                        'pending',
                ]);

            $providerSubscriptionId =
                $mpSubscription['id']
                ?? null;

            if (!$providerSubscriptionId) {
                throw new RuntimeException(
                    'Mercado Pago no devolvió el ID de la suscripción.'
                );
            }

            $initPoint =
                $mpSubscription['init_point']
                ?? null;

            if (!$initPoint) {
                throw new RuntimeException(
                    'Mercado Pago no devolvió init_point.'
                );
            }

            /*
             * Guardamos el ID inmediatamente.
             */

            $subscription->update([
                'provider_subscription_id' =>
                    (string) $providerSubscriptionId,

                'provider_plan_id' =>
                    $plan->mercadopago_plan_id,

                'external_reference' =>
                    $externalReference,

                'plan' =>
                    $plan->slug,

                'status' =>
                    'pending',

                'amount' =>
                    $plan->price,

                'currency' =>
                    $plan->currency,

                'cancel_at_period_end' =>
                    false,
            ]);

            /*
             * Sincronización inmediata.
             */

            try {
                $fresh =
                    $mp->getSubscription(
                        (string) $providerSubscriptionId
                    );

                $this->syncLocalSubscription(
                    $subscription,
                    $fresh
                );
            } catch (\Throwable $e) {
                Log::warning(
                    'ERROR SINCRONIZANDO NUEVA SUSCRIPCION FILAMENT',
                    [
                        'company_id' =>
                            $subscription->company_id,

                        'subscription_id' =>
                            $subscription->id,

                        'provider_subscription_id' =>
                            $providerSubscriptionId,

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }

            Log::info(
                'MP SUSCRIPCION CREADA DESDE FILAMENT',
                [
                    'company_id' =>
                        $subscription->company_id,

                    'subscription_id' =>
                        $subscription->id,

                    'provider_subscription_id' =>
                        $providerSubscriptionId,

                    'provider_plan_id' =>
                        $plan->mercadopago_plan_id,

                    'external_reference' =>
                        $externalReference,
                ]
            );

            /*
             * REDIRECT AL CHECKOUT REAL.
             */

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

                    'provider_subscription_id' =>
                        $subscription
                            ->provider_subscription_id,

                    'provider_plan_id' =>
                        $subscription
                            ->provider_plan_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            /*
             * Solo eliminamos el registro si MP
             * nunca creó la suscripción.
             */

            if (
                !$subscription
                    ->provider_subscription_id
            ) {
                $subscription->delete();
            }

            Notification::make()
                ->title(
                    'No se pudo iniciar el checkout'
                )
                ->body(
                    $e->getMessage()
                )
                ->danger()
                ->send();
        }
    }

    /**
     * ==============================================================
     * PAUSAR SUSCRIPCIÓN
     * ==============================================================
     *
     * Esta acción NO cancela definitivamente.
     */
    public function cancelSubscription(): void
    {
        $user =
            auth()->user();

        abort_unless(
            $user?->isAdmin()
            || $user?->isSuperAdmin(),
            403
        );

        $company =
            $user->company;

        abort_unless(
            $company,
            403
        );

        $subscription =
            $this->getActiveSubscription();

        if (!$subscription) {
            Notification::make()
                ->title(
                    'No hay una suscripción'
                )
                ->warning()
                ->send();

            return;
        }

        if (
            !$subscription
                ->provider_subscription_id
        ) {
            Notification::make()
                ->title(
                    'No hay una suscripción de Mercado Pago'
                )
                ->warning()
                ->send();

            return;
        }

        if (
            $subscription->status === 'paused'
        ) {
            Notification::make()
                ->title(
                    'La suscripción ya está pausada'
                )
                ->warning()
                ->send();

            return;
        }

        if (in_array(
            $subscription->status,
            [
                'canceled',
                'cancelled',
            ],
            true
        )) {
            Notification::make()
                ->title(
                    'La suscripción ya está cancelada'
                )
                ->warning()
                ->send();

            return;
        }

        try {
            $mp =
                app(MercadoPagoService::class);

            /*
             * Consultamos estado real.
             */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription
                        ->provider_subscription_id
                );

            $mpStatus =
                $mpSubscription['status']
                ?? null;

            /*
             * Si MP ya canceló.
             */

            if (in_array(
                $mpStatus,
                [
                    'cancelled',
                    'canceled',
                ],
                true
            )) {
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                Notification::make()
                    ->title(
                        'Suscripción cancelada'
                    )
                    ->warning()
                    ->send();

                return;
            }

            /*
             * Ya pausada.
             */

            if ($mpStatus === 'paused') {
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

                Notification::make()
                    ->title(
                        'Suscripción pausada'
                    )
                    ->warning()
                    ->send();

                return;
            }

            /*
             * Solamente pausamos estados válidos.
             */

            if (!in_array(
                $mpStatus,
                [
                    'authorized',
                    'active',
                    'trialing',
                    'past_due',
                ],
                true
            )) {
                Notification::make()
                    ->title(
                        'No se puede pausar'
                    )
                    ->body(
                        'Mercado Pago informa: '
                        . (
                            $mpStatus
                            ?? 'desconocido'
                        )
                    )
                    ->warning()
                    ->send();

                return;
            }

            /*
             * PAUSA REAL.
             */

            $mp->pauseSubscription(
                $subscription
                    ->provider_subscription_id
            );

            /*
             * Confirmamos estado.
             */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription
                        ->provider_subscription_id
                );

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

            if (
                $subscription->status === 'paused'
            ) {
                $this->dispatch(
                    'subscription-updated'
                );

                Notification::make()
                    ->title(
                        'Suscripción pausada'
                    )
                    ->body(
                        'Podés reactivarla cuando quieras.'
                    )
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title(
                    'Estado actualizado'
                )
                ->body(
                    'Mercado Pago informa: '
                    . (
                        $subscription->status
                        ?? 'desconocido'
                    )
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
                        $subscription
                            ->provider_subscription_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            Notification::make()
                ->title(
                    'No se pudo pausar la suscripción'
                )
                ->body(
                    $e->getMessage()
                )
                ->danger()
                ->send();
        }
    }

    /**
     * ==============================================================
     * REACTIVAR
     * ==============================================================
     */
    public function resumeSubscription(): void
    {
        $user =
            auth()->user();

        abort_unless(
            $user?->isAdmin()
            || $user?->isSuperAdmin(),
            403
        );

        $company =
            $user->company;

        abort_unless(
            $company,
            403
        );

        $subscription =
            $this->getActiveSubscription();

        if (!$subscription) {
            Notification::make()
                ->title(
                    'No hay una suscripción'
                )
                ->warning()
                ->send();

            return;
        }

        if (
            !$subscription
                ->provider_subscription_id
        ) {
            Notification::make()
                ->title(
                    'No hay una suscripción de Mercado Pago'
                )
                ->warning()
                ->send();

            return;
        }

        if (in_array(
            $subscription->status,
            [
                'canceled',
                'cancelled',
            ],
            true
        )) {
            Notification::make()
                ->title(
                    'No se puede reactivar'
                )
                ->body(
                    'Esta suscripción fue cancelada definitivamente. Debés crear una nueva.'
                )
                ->danger()
                ->send();

            return;
        }

        try {
            $mp =
                app(MercadoPagoService::class);

            /*
             * Consultamos estado real.
             */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription
                        ->provider_subscription_id
                );

            $mpStatus =
                $mpSubscription['status']
                ?? null;

            /*
             * Cancelada definitivamente.
             */

            if (in_array(
                $mpStatus,
                [
                    'cancelled',
                    'canceled',
                ],
                true
            )) {
                $subscription =
                    $this->syncLocalSubscription(
                        $subscription,
                        $mpSubscription
                    );

                Notification::make()
                    ->title(
                        'No se puede reactivar'
                    )
                    ->body(
                        'Mercado Pago canceló definitivamente esta suscripción.'
                    )
                    ->danger()
                    ->send();

                return;
            }

            /*
             * Ya activa.
             */

            if (in_array(
                $mpStatus,
                [
                    'authorized',
                    'active',
                ],
                true
            )) {
                $subscription =
                    $this->syncLocalSubscription(
                        $subscription,
                        $mpSubscription
                    );

                Notification::make()
                    ->title(
                        'La suscripción ya está activa'
                    )
                    ->success()
                    ->send();

                return;
            }

            /*
             * Solamente podemos reactivar una pausada.
             */

            if ($mpStatus !== 'paused') {
                $subscription =
                    $this->syncLocalSubscription(
                        $subscription,
                        $mpSubscription
                    );

                Notification::make()
                    ->title(
                        'No se puede reactivar'
                    )
                    ->body(
                        'Mercado Pago informa: '
                        . (
                            $mpStatus
                            ?? 'desconocido'
                        )
                    )
                    ->warning()
                    ->send();

                return;
            }

            /*
             * REACTIVAMOS EL MISMO PREAPPROVAL.
             */

            $mp->resumeSubscription(
                $subscription
                    ->provider_subscription_id
            );

            /*
             * Confirmamos.
             */

            $mpSubscription =
                $mp->getSubscription(
                    $subscription
                        ->provider_subscription_id
                );

            $subscription =
                $this->syncLocalSubscription(
                    $subscription,
                    $mpSubscription
                );

            if (in_array(
                $subscription->status,
                [
                    'authorized',
                    'active',
                ],
                true
            )) {
                $this->dispatch(
                    'subscription-updated'
                );

                Notification::make()
                    ->title(
                        'Suscripción reactivada'
                    )
                    ->body(
                        'La misma suscripción de Mercado Pago volvió a estar activa.'
                    )
                    ->success()
                    ->send();

                return;
            }

            Notification::make()
                ->title(
                    'Estado actualizado'
                )
                ->body(
                    'Mercado Pago informa: '
                    . (
                        $subscription->status
                        ?? 'desconocido'
                    )
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
                        $subscription
                            ->provider_subscription_id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            Notification::make()
                ->title(
                    'No se pudo reactivar la suscripción'
                )
                ->body(
                    $e->getMessage()
                )
                ->danger()
                ->send();
        }
    }
}
