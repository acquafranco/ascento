<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Models\Company;
use App\Models\Subscription as SubscriptionModel;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoApiException;
use App\Services\MercadoPagoService;
use App\Services\MercadoPagoSubscriptionSync;
use App\Support\ManualSubscriptionActivator;
use App\Support\Plans\PlanGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "Mi suscripción" del admin de cada empresa.
 *
 * El navegador nunca decide el estado: al volver de Mercado Pago (o con
 * "Actualizar estado") se consulta la API de Mercado Pago con el id que
 * Ascento guardó, no con lo que venga en la URL.
 */
class Subscription extends Page
{
    protected string $view = 'filament.pages.subscription';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Mi suscripción';

    protected static ?string $title = 'Mi suscripción';

    protected static ?string $slug = 'subscription';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && ! $user->isSuperAdmin() && $user->company_id !== null;
    }

    /** Motivo por el que se llegó acá (límite o función), para el aviso de upgrade. */
    public ?string $limite = null;

    public ?string $funcion = null;

    public function mount(): void
    {
        $this->company();

        $this->limite = PlanLimit::tryFrom((string) request()->query('limite'))?->value;
        $this->funcion = PlanFeature::tryFrom((string) request()->query('funcion'))?->value;

        // Vuelta del checkout: se lee el estado real en Mercado Pago.
        if (request()->query('mp') === 'return') {
            $this->syncFromMercadoPago(notify: true);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DATOS PARA LA VISTA
    |--------------------------------------------------------------------------
    */

    protected function company(): Company
    {
        $company = auth()->user()?->company;

        abort_unless($company && auth()->user()->isAdmin(), 403);

        return $company;
    }

    /** Plan vigente de la empresa (en la prueba gratis: Profesional). */
    public function getPlan(): SubscriptionPlan
    {
        return $this->company()->plan();
    }

    /** @return Collection<int, SubscriptionPlan> Los tres planes, en orden. */
    public function getPlans(): Collection
    {
        return SubscriptionPlan::offered();
    }

    /**
     * Uso actual vs. límites del plan: [label, used, max, percent, warning].
     *
     * @return list<array{label: string, used: int, max: ?int, percent: int, warning: ?string}>
     */
    public function getUsage(): array
    {
        $guard = PlanGuard::for($this->company());

        return array_map(function (PlanLimit $limit) use ($guard) {
            $max = $guard->limit($limit);
            $used = $guard->usage($limit);

            return [
                'label' => ucfirst($limit->plural()),
                'used' => $used,
                'max' => $max,
                'percent' => $max ? min(100, (int) round($used * 100 / max(1, $max))) : 0,
                'warning' => $guard->warning($limit),
            ];
        }, PlanLimit::cases());
    }

    /** Aviso de por qué llegó acá: [título, qué gana al actualizar] o null. */
    public function getUpgradeReason(): ?array
    {
        $guard = PlanGuard::for($this->company());
        $limit = PlanLimit::tryFrom((string) $this->limite);
        $feature = PlanFeature::tryFrom((string) $this->funcion);

        if ($limit && ! $guard->canAdd($limit)) {
            return [$guard->limitReachedMessage($limit), $guard->upgradePitch($limit)];
        }

        if ($feature && ! $guard->allows($feature)) {
            return [$guard->featureUnavailableMessage($feature), $guard->upgradePitch(feature: $feature)];
        }

        return null;
    }

    /**
     * Qué se puede hacer con cada plan:
     * current | subscribe | change | trial | unavailable
     */
    public function planState(SubscriptionPlan $plan): string
    {
        $subscription = $this->getSubscription();
        $isCurrent = $this->getPlan()->slug === $plan->slug;

        return match (true) {
            $this->isInFreePeriod() => $isCurrent ? 'current' : 'trial',
            $this->canCancel() => $isCurrent ? 'current' : 'change',
            ! $this->canPayOnline() => $isCurrent && $subscription?->grantsAccess() ? 'current' : 'unavailable',
            default => ($isCurrent && $subscription?->grantsAccess()) ? 'current' : 'subscribe',
        };
    }

    /**
     * Recursos en los que la empresa ya supera lo que permite el plan
     * (para avisar antes de elegir un plan más chico).
     *
     * @return list<string>
     */
    public function overLimitsFor(SubscriptionPlan $plan): array
    {
        $guard = PlanGuard::for($this->company());
        $over = [];

        foreach (PlanLimit::cases() as $limit) {
            $max = $plan->limit($limit);

            if (! $limit->isMonthly() && $max !== null && $guard->usage($limit) > $max) {
                $over[] = "tenés {$guard->usage($limit)} {$limit->plural()} y este plan permite {$max}";
            }
        }

        return $over;
    }

    public function getSubscription(): ?SubscriptionModel
    {
        return SubscriptionModel::where('company_id', $this->company()->id)->first();
    }

    public function getCompany(): Company
    {
        return $this->company();
    }

    public function canPayOnline(): bool
    {
        return MercadoPagoService::isConfigured() && $this->getPlans()->isNotEmpty();
    }

    /** @return Collection<int, SubscriptionPayment> */
    public function getPayments(): Collection
    {
        return $this->getSubscription()?->payments()->limit(12)->get() ?? collect();
    }

    /**
     * Estado explicado para el admin: [etiqueta, color, explicación].
     */
    public function getStatusInfo(): array
    {
        $company = $this->company();
        $subscription = $this->getSubscription();
        $date = fn ($value) => $value?->format('d/m/Y');

        if (! $subscription) {
            return $company->onTrial()
                ? ['Prueba gratis', 'info', 'Estás usando Ascento gratis hasta el '.$date($company->trial_ends_at).'. Ese día vas a poder suscribirte con Mercado Pago para seguir usándolo.']
                : ['Prueba terminada', 'danger', 'Tu prueba gratis de 30 días terminó. Suscribite con Mercado Pago para seguir usando Ascento.'];
        }

        if ($subscription->provider === 'manual') {
            return $subscription->grantsAccess()
                ? ['Activa', 'success', 'Acceso pago hasta el '.$date($subscription->current_period_end).'. Desde ese día la suscripción sigue con Mercado Pago.']
                : ['Vencida', 'danger', 'El período pago terminó. Suscribite con Mercado Pago para seguir usando Ascento.'];
        }

        return match (true) {
            $subscription->status === SubscriptionModel::PENDING => ['Pago sin terminar', 'warning', 'Empezaste la suscripción pero Mercado Pago todavía no la confirmó. Si ya pagaste, tocá "Actualizar estado".'],
            $subscription->isAwaitingFirstPayment() => ['Confirmando el primer pago', 'warning', 'Mercado Pago autorizó la suscripción y está procesando el primer cobro. Ya podés usar Ascento.'],
            $subscription->status === SubscriptionModel::AUTHORIZED && $subscription->hasPaidPeriod() => ['Activa', 'success', 'Pagado hasta el '.$date($subscription->current_period_end).'.'.($subscription->next_payment_at ? ' Próximo cobro automático: '.$date($subscription->next_payment_at).'.' : '')],
            $subscription->status === SubscriptionModel::PAST_DUE && $subscription->grantsAccess() => ['Pago rechazado', 'danger', 'Mercado Pago no pudo cobrar la cuota y va a reintentar. Revisá tu medio de pago en Mercado Pago. Mantenés el acceso hasta el '.$date($subscription->graceEndsAt()).'.'],
            $subscription->isCanceled() && $subscription->hasPaidPeriod() => ['Cancelada', 'gray', 'No se va a renovar. Podés usar Ascento hasta el '.$date($subscription->current_period_end).'.'],
            $subscription->isCanceled() => ['Cancelada', 'danger', 'La suscripción está cancelada. Suscribite de nuevo para volver a usar Ascento.'],
            $subscription->status === SubscriptionModel::PAUSED => ['Pausada', 'danger', 'El acceso de tu empresa está pausado. Escribinos para reactivarlo.'],
            default => ['Vencida', 'danger', 'No hay un período pago vigente. Suscribite para volver a usar Ascento.'],
        };
    }

    /**
     * Durante los 30 días gratis no se cobra nada: la suscripción se habilita
     * cuando termina la prueba (y mientras queden días pagos, tampoco).
     */
    public function isInFreePeriod(): bool
    {
        $company = $this->company();
        $subscription = $this->getSubscription();

        if ($subscription && ! ($subscription->isMercadoPago() && $subscription->status === SubscriptionModel::PENDING)) {
            return false;
        }

        return $company->onTrial();
    }

    /** ¿Mostrar "Suscribirme con Mercado Pago"? */
    public function canStartCheckout(): bool
    {
        $subscription = $this->getSubscription();

        return $this->canPayOnline()
            && ! $this->isInFreePeriod()
            && ! ($subscription?->isMercadoPago() && in_array($subscription->status, [SubscriptionModel::AUTHORIZED, SubscriptionModel::PAST_DUE], true));
    }

    public function canCancel(): bool
    {
        $subscription = $this->getSubscription();

        return MercadoPagoService::isConfigured()
            && $subscription?->isMercadoPago()
            && in_array($subscription->status, [SubscriptionModel::AUTHORIZED, SubscriptionModel::PAST_DUE], true);
    }

    public function daysRemaining(): ?int
    {
        return ManualSubscriptionActivator::daysRemaining($this->company());
    }

    /*
    |--------------------------------------------------------------------------
    | ACCIONES
    |--------------------------------------------------------------------------
    */

    public function checkout(?string $plan = null): void
    {
        $company = $this->company();

        // Solo planes que se venden hoy. Un slug inválido o inactivo se rechaza
        // (nunca se cae en otro plan). Sin slug: el actual o el recomendado.
        $plan = $plan !== null
            ? $this->getPlans()->firstWhere('slug', $plan)
            : ($this->getPlans()->firstWhere('slug', $this->getPlan()->slug) ?? $this->getPlans()->firstWhere('is_recommended', true));

        if (! $this->canStartCheckout() || ! $plan) {
            Notification::make()->title('No se puede iniciar el pago ahora')->warning()->send();

            return;
        }

        try {
            $url = app(MercadoPagoSubscriptionSync::class)->startCheckout(
                $company,
                auth()->user(),
                $plan,
                static::getUrl(['mp' => 'return']),
            );
        } catch (Throwable $e) {
            Log::error('Error iniciando el checkout de Mercado Pago', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            report($e);

            Notification::make()
                ->title('No se pudo iniciar el pago')
                ->body($e instanceof MercadoPagoApiException
                    ? $e->hint()
                    : 'Intentá de nuevo en unos minutos.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->redirect($url, navigate: false);
    }

    public function refreshStatus(): void
    {
        $this->syncFromMercadoPago(notify: true);
    }

    /** Cambiar de plan con la suscripción activa (Mercado Pago actualiza el importe). */
    public function changePlanAction(): Action
    {
        return Action::make('changePlan')
            ->label(fn (array $arguments) => 'Cambiar a '.($this->getPlans()->firstWhere('slug', $arguments['plan'] ?? null)?->shortName() ?? 'este plan'))
            ->color(fn (array $arguments) => $this->getPlans()->firstWhere('slug', $arguments['plan'] ?? null)?->is_recommended ? 'primary' : 'gray')
            ->extraAttributes(['style' => 'width:100%'])
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-arrow-path')
            ->modalHeading(fn (array $arguments) => 'Cambiar al plan '.($this->getPlans()->firstWhere('slug', $arguments['plan'] ?? null)?->shortName() ?? ''))
            ->modalDescription(function (array $arguments) {
                $plan = $this->getPlans()->firstWhere('slug', $arguments['plan'] ?? null);

                if (! $plan) {
                    return null;
                }

                $over = $this->overLimitsFor($plan);

                return 'El plan nuevo rige desde ahora. Mercado Pago te va a cobrar '.$plan->formattedPrice().'/mes desde el próximo cobro.'
                    .($over ? ' Ojo: '.implode('; ', $over).'. No se borra nada, pero no vas a poder agregar más hasta estar dentro del límite.' : '');
            })
            ->modalSubmitActionLabel('Sí, cambiar de plan')
            ->modalCancelActionLabel('No, volver')
            ->action(function (array $arguments) {
                $plan = $this->getPlans()->firstWhere('slug', $arguments['plan'] ?? null);
                $subscription = $this->getSubscription();

                if (! $plan || ! $subscription || ! $this->canCancel()) {
                    Notification::make()->title('No se puede cambiar de plan ahora')->warning()->send();

                    return;
                }

                try {
                    app(MercadoPagoSubscriptionSync::class)->changePlan($subscription, $plan);
                } catch (Throwable $e) {
                    Log::error('Error cambiando de plan', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

                    Notification::make()
                        ->title('No se pudo cambiar de plan')
                        ->body($e instanceof MercadoPagoApiException ? $e->hint() : 'Intentá de nuevo en unos minutos.')
                        ->danger()
                        ->send();

                    return;
                }

                $this->company()->forgetPlan();
                $this->limite = $this->funcion = null;

                Notification::make()
                    ->title('Listo: ahora tenés el plan '.$plan->shortName())
                    ->body('Desde el próximo cobro, Mercado Pago te cobra '.$plan->formattedPrice().'/mes.')
                    ->success()
                    ->send();
            });
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar suscripción')
            ->color('danger')
            ->link()
            ->visible(fn () => $this->canCancel())
            ->requiresConfirmation()
            ->modalHeading('¿Cancelar la suscripción?')
            ->modalDescription(fn () => 'Mercado Pago deja de cobrarte. Vas a poder usar Ascento hasta el '
                .($this->getSubscription()?->current_period_end?->format('d/m/Y') ?? 'fin del período pago')
                .'. Después podés volver a suscribirte cuando quieras.')
            ->modalSubmitActionLabel('Sí, cancelar')
            ->modalCancelActionLabel('No, volver')
            ->action(function () {
                $subscription = $this->getSubscription();

                try {
                    app(MercadoPagoSubscriptionSync::class)->cancel($subscription);
                } catch (Throwable $e) {
                    Log::error('Error cancelando la suscripción', ['subscription_id' => $subscription?->id, 'error' => $e->getMessage()]);

                    Notification::make()
                        ->title('No se pudo cancelar')
                        ->body($e instanceof MercadoPagoApiException ? $e->hint() : 'Intentá de nuevo en unos minutos.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Suscripción cancelada')->body('No se te va a volver a cobrar.')->success()->send();
            });
    }

    protected function syncFromMercadoPago(bool $notify): void
    {
        $subscription = $this->getSubscription();

        if (! $subscription?->isMercadoPago() || ! $subscription->provider_subscription_id || ! MercadoPagoService::isConfigured()) {
            return;
        }

        $key = 'mp-sync:'.$subscription->company_id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $notify && Notification::make()->title('Esperá unos segundos antes de volver a actualizar')->warning()->send();

            return;
        }

        RateLimiter::hit($key, 30);

        try {
            app(MercadoPagoSubscriptionSync::class)->reconcile($subscription);
        } catch (Throwable $e) {
            Log::warning('No se pudo sincronizar con Mercado Pago', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

            $notify && Notification::make()->title('No pudimos consultar a Mercado Pago')->body('Probá de nuevo en un rato.')->warning()->send();

            return;
        }

        $notify && Notification::make()->title('Estado actualizado desde Mercado Pago')->success()->send();
    }
}
