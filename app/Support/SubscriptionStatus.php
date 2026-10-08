<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Subscription;

/**
 * Estado comercial de una empresa en una palabra, para el SuperAdmin
 * (Empresas y Suscripciones). Se deriva de las mismas reglas de acceso que
 * Company::hasActiveAccess(): lo que se ve acá es lo que pasa en la app.
 */
class SubscriptionStatus
{
    public const OPTIONS = [
        'trial' => 'Prueba gratis',
        'trial_ended' => 'Prueba terminada',
        'pending' => 'Pago sin terminar',
        'awaiting_payment' => 'Confirmando primer pago',
        'active' => 'Activa',
        'past_due' => 'Pago rechazado',
        'canceled_period' => 'Cancelada (con días pagos)',
        'canceled' => 'Cancelada',
        'paused' => 'Pausada',
        'expired' => 'Vencida',
        'company_disabled' => 'Empresa desactivada',
    ];

    public const COLORS = [
        'trial' => 'info',
        'trial_ended' => 'danger',
        'pending' => 'warning',
        'awaiting_payment' => 'warning',
        'active' => 'success',
        'past_due' => 'danger',
        'canceled_period' => 'gray',
        'canceled' => 'danger',
        'paused' => 'danger',
        'expired' => 'danger',
        'company_disabled' => 'gray',
    ];

    public static function key(Company $company): string
    {
        if (! $company->is_active || $company->trashed()) {
            return 'company_disabled';
        }

        $subscription = $company->latestSubscription;

        if (! $subscription || ($subscription->status === Subscription::PENDING && $company->onTrial())) {
            return $company->onTrial() ? 'trial' : ($subscription ? 'pending' : 'trial_ended');
        }

        return match (true) {
            $subscription->status === Subscription::PENDING => 'pending',
            $subscription->isAwaitingFirstPayment() => 'awaiting_payment',
            in_array($subscription->status, [Subscription::AUTHORIZED, Subscription::ACTIVE, 'trialing'], true) && $subscription->hasPaidPeriod() => 'active',
            $subscription->status === Subscription::PAST_DUE => 'past_due',
            $subscription->isCanceled() && $subscription->hasPaidPeriod() => 'canceled_period',
            $subscription->isCanceled() => 'canceled',
            $subscription->status === Subscription::PAUSED => 'paused',
            default => 'expired',
        };
    }

    public static function label(Company $company): string
    {
        return self::OPTIONS[self::key($company)];
    }

    public static function color(Company $company): string
    {
        return self::COLORS[self::key($company)];
    }
}
