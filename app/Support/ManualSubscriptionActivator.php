<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Subscription;

class ManualSubscriptionActivator
{
    /**
     * Activa o extiende manualmente el acceso de una empresa (pago
     * por transferencia). Si ya tenía período vigente, suma los días
     * nuevos a partir de esa fecha en vez de resetear desde hoy.
     */
    public static function activate(Company $company, int $days = 30): Subscription
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->latest('id')
            ->first();

        $baseDate = ($subscription?->current_period_end && $subscription->current_period_end->isFuture())
            ? $subscription->current_period_end
            : now();

        $newPeriodEnd = $baseDate->copy()->addDays($days);

        return Subscription::updateOrCreate(
            ['company_id' => $company->id],
            [
                'provider' => 'manual',
                'provider_subscription_id' => 'manual_' . $company->id . '_' . now()->timestamp,
                'provider_plan_id' => null,
                'external_reference' => 'company_' . $company->id,
                'plan' => $subscription->plan ?? 'professional',
                'status' => 'active',
                'amount' => $subscription->amount,
                'currency' => $subscription->currency ?? 'ARS',
                'trial_ends_at' => null,
                'current_period_start' => now(),
                'current_period_end' => $newPeriodEnd,
                'canceled_at' => null,
                'cancel_at_period_end' => false,
            ]
        );
    }

    /**
     * Corta el acceso ya mismo (no pagó, hay un problema, etc.) sin
     * tocar current_period_end — así, si se resuelve, se puede
     * reanudar sin perder los días que ya tenía pagos.
     */
    public static function pause(Company $company): ?Subscription
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->latest('id')
            ->first();

        if (!$subscription) {
            return null;
        }

        $subscription->update(['status' => 'paused']);

        return $subscription->fresh();
    }

    /**
     * Deshace un pausado manual, siempre que todavía quede período
     * vigente (current_period_end en el futuro). No suma días — si ya
     * no queda período vigente, no hace nada y devuelve null: en ese
     * caso corresponde usar activate() en su lugar.
     */
    public static function resume(Company $company): ?Subscription
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->latest('id')
            ->first();

        if (!$subscription
            || !$subscription->current_period_end
            || $subscription->current_period_end->isPast()
        ) {
            return null;
        }

        $subscription->update(['status' => 'active']);

        return $subscription->fresh();
    }

    /**
     * Días restantes de acceso para mostrar en el panel: prioriza la
     * suscripción activa; si no hay, cae al trial gratuito de la
     * empresa; si no hay ninguno de los dos, null (sin acceso).
     */
    public static function daysRemaining(Company $company): ?int
    {
        $subscription = $company->subscription;

        if ($subscription?->current_period_end) {
            if ($subscription->current_period_end->isPast()) {
                return 0;
            }

            return (int) now()->diffInDays($subscription->current_period_end);
        }

        if ($company->onTrial()) {
            return (int) now()->diffInDays($company->trial_ends_at);
        }

        return null;
    }
}
