<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Subscription as SubscriptionModel;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoApiException;
use App\Services\MercadoPagoService;
use App\Services\MercadoPagoSubscriptionSync;
use App\Support\ManualSubscriptionActivator;
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

    public function mount(): void
    {
        $this->company();

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

    public function getPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('is_active', true)->orderBy('id')->first();
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
        return MercadoPagoService::isConfigured() && $this->getPlan() !== null;
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
                ? ['Período de prueba', 'info', 'Tu prueba gratis termina el '.$date($company->trial_ends_at).'. Suscribite antes para no perder el acceso.']
                : ['Sin suscripción', 'danger', 'Tu prueba gratis terminó. Suscribite para seguir usando Ascento.'];
        }

        if ($subscription->provider === 'manual') {
            return $subscription->grantsAccess()
                ? ['Activa (transferencia)', 'success', 'Acceso pago hasta el '.$date($subscription->current_period_end).'.']
                : ['Vencida', 'danger', 'El período pagado por transferencia terminó.'];
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

    /** ¿Mostrar "Suscribirme con Mercado Pago"? */
    public function canStartCheckout(): bool
    {
        $subscription = $this->getSubscription();

        return $this->canPayOnline()
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

    public function checkout(): void
    {
        $company = $this->company();
        $plan = $this->getPlan();

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
                    : 'Intentá de nuevo en unos minutos. Si sigue fallando, podés pagar por transferencia.')
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
