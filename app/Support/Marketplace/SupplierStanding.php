<?php

namespace App\Support\Marketplace;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use App\Services\Marketplace\Billing\SupplierBilling;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estado comercial de un proveedor: la ÚNICA regla que decide si su catálogo
 * se ve, si puede publicar y si recibe pedidos nuevos. La moderación
 * (aprobado, rechazado, suspendido) y el pago son cosas distintas: pagar
 * nunca aprueba ni levanta una suspensión.
 *
 * Se calcula siempre con los datos actuales (no depende de que corra una
 * tarea programada): al vencer la prueba o el período pago, el catálogo deja
 * de verse en ese mismo momento. Nada se borra.
 */
final class SupplierStanding
{
    public const PENDING_APPROVAL = 'pending_approval';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const CLOSED = 'closed';

    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    /** Argentina con los cobros todavía sin habilitar: no se le puede cobrar, sigue sin cargo. */
    public const FREE_UNBILLED = 'free_unbilled';

    /** País sin pasarela de cobro, con la modalidad gratuita habilitada por el SuperAdmin. */
    public const FREE_INTERNATIONAL = 'free_international';

    public const TRIAL_EXPIRED = 'trial_expired';

    public const SUBSCRIPTION_PENDING = 'subscription_pending';

    public const LAPSED = 'lapsed';

    /** País sin pasarela y sin modalidad gratuita: no puede operar (ni pagar) por ahora. */
    public const UNAVAILABLE_COUNTRY = 'unavailable_country';

    public const LABELS = [
        self::PENDING_APPROVAL => 'Pendiente de aprobación',
        self::REJECTED => 'Rechazado',
        self::SUSPENDED => 'Suspendido por moderación',
        self::CLOSED => 'Cuenta cerrada',
        self::TRIAL => 'Aprobado, en período de prueba',
        self::ACTIVE => 'Suscripción activa',
        self::FREE_UNBILLED => 'Aprobado, sin cargo (cobros no habilitados)',
        self::FREE_INTERNATIONAL => 'Aprobado, modalidad gratuita internacional',
        self::TRIAL_EXPIRED => 'Prueba vencida',
        self::SUBSCRIPTION_PENDING => 'Suscripción pendiente de pago',
        self::LAPSED => 'Suscripción vencida o cancelada',
        self::UNAVAILABLE_COUNTRY => 'Sin modalidad disponible en su país',
    ];

    /** Estados en los que el catálogo se ve y se reciben pedidos nuevos. */
    public const OPERATING = [self::TRIAL, self::ACTIVE, self::FREE_UNBILLED, self::FREE_INTERNATIONAL];

    public static function of(Supplier $supplier, ?MarketplaceSettings $settings = null): string
    {
        $settings ??= MarketplaceSettings::current();

        if ($supplier->closed_at !== null) {
            return self::CLOSED;
        }

        $moderation = match ($supplier->status) {
            Supplier::PENDING => self::PENDING_APPROVAL,
            Supplier::REJECTED => self::REJECTED,
            Supplier::SUSPENDED => self::SUSPENDED,
            Supplier::APPROVED => null,
            default => self::SUSPENDED,
        };
        if ($moderation !== null) {
            return $moderation;
        }

        $subscription = $supplier->relationLoaded('subscription') ? $supplier->subscription : $supplier->subscription()->first();
        if ($subscription?->grantsAccess()) {
            return self::ACTIVE;
        }
        if ($supplier->trial_ends_at?->isFuture()) {
            return self::TRIAL;
        }

        if (! SupplierBilling::supportsCountry($supplier->country)) {
            return $settings->international_free ? self::FREE_INTERNATIONAL : self::UNAVAILABLE_COUNTRY;
        }
        if (! $settings->chargesSubscription()) {
            return self::FREE_UNBILLED;
        }

        return match (true) {
            $subscription !== null && in_array($subscription->status, [SupplierSubscription::PENDING, SupplierSubscription::AUTHORIZED], true) && $subscription->current_period_end === null => self::SUBSCRIPTION_PENDING,
            $subscription !== null && $subscription->current_period_end !== null => self::LAPSED,
            default => self::TRIAL_EXPIRED,
        };
    }

    public static function operating(Supplier $supplier, ?MarketplaceSettings $settings = null): bool
    {
        return in_array(self::of($supplier, $settings), self::OPERATING, true);
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /**
     * La misma regla en SQL, para el catálogo y los pedidos nuevos (consultas
     * sobre la tabla suppliers). Los parámetros salen del servidor, nunca del navegador.
     */
    public static function applyOperating(Builder $query, ?MarketplaceSettings $settings = null): Builder
    {
        $settings ??= MarketplaceSettings::current();
        $billable = SupplierBilling::countries();

        return $query->where('suppliers.status', Supplier::APPROVED)
            ->whereNull('suppliers.closed_at')
            ->where(function (Builder $w) use ($settings, $billable) {
                $w->where('suppliers.trial_ends_at', '>', now())
                    ->orWhereHas('subscription', fn (Builder $s) => $s->where(fn (Builder $p) => $p
                        ->where('current_period_end', '>', now())
                        ->orWhere(fn (Builder $d) => $d->where('status', SupplierSubscription::PAST_DUE)
                            ->where('current_period_end', '>', now()->subDays(SupplierSubscription::PAST_DUE_GRACE_DAYS)))));

                if (! $settings->chargesSubscription()) {
                    $w->orWhereIn('suppliers.country', $billable);
                }
                if ($settings->international_free) {
                    $w->orWhereNotIn('suppliers.country', $billable);
                }
            });
    }
}
