<?php

namespace App\Support\Marketplace;

use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierSubscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estado de un proveedor: moderación (la decide el SuperAdmin) y plan
 * (gratuito para siempre o Premium pago). Son independientes: pagar nunca
 * aprueba ni levanta una suspensión, y no pagar nunca deja a un proveedor sin
 * publicar: vuelve al plan gratuito con su límite (PublicationLimits).
 *
 * Se calcula siempre con los datos actuales; no depende de una tarea programada.
 */
final class SupplierStanding
{
    public const PENDING_APPROVAL = 'pending_approval';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const CLOSED = 'closed';

    public const FREE = 'free';

    public const PREMIUM = 'premium';

    public const LABELS = [
        self::PENDING_APPROVAL => 'Pendiente de aprobación',
        self::REJECTED => 'Rechazado',
        self::SUSPENDED => 'Suspendido por moderación',
        self::CLOSED => 'Cuenta cerrada',
        self::FREE => 'Plan gratuito',
        self::PREMIUM => 'Premium',
    ];

    /** Estados en los que el catálogo se ve y se reciben pedidos nuevos. */
    public const OPERATING = [self::FREE, self::PREMIUM];

    public static function of(Supplier $supplier): string
    {
        if ($supplier->closed_at !== null) {
            return self::CLOSED;
        }

        return match ($supplier->status) {
            Supplier::PENDING => self::PENDING_APPROVAL,
            Supplier::REJECTED => self::REJECTED,
            Supplier::APPROVED => self::isPremium($supplier) ? self::PREMIUM : self::FREE,
            default => self::SUSPENDED,
        };
    }

    public static function operating(Supplier $supplier): bool
    {
        return in_array(self::of($supplier), self::OPERATING, true);
    }

    /** ¿Tiene Premium hoy? Solo con un período pagado (o la tolerancia de un cobro rechazado). */
    public static function isPremium(Supplier $supplier): bool
    {
        $subscription = $supplier->relationLoaded('subscription') ? $supplier->subscription : $supplier->subscription()->first();

        return (bool) $subscription?->grantsAccess();
    }

    /** Publicaciones simultáneas permitidas (según el plan; la moderación no cambia el número). */
    public static function publicationLimit(Supplier $supplier, ?MarketplaceSettings $settings = null): int
    {
        $settings ??= MarketplaceSettings::current();

        return self::isPremium($supplier) ? $settings->premiumLimit() : $settings->freeLimit();
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /** Detalle de Premium para las pantallas (null = no tiene ni tuvo Premium). */
    public static function premiumDetail(Supplier $supplier): ?string
    {
        $sub = $supplier->relationLoaded('subscription') ? $supplier->subscription : $supplier->subscription()->first();
        if (! $sub) {
            return null;
        }
        $until = $sub->current_period_end?->format('d/m/Y');

        return match (true) {
            $sub->status === SupplierSubscription::PAST_DUE && $sub->grantsAccess() => 'Cobro rechazado: Mercado Pago reintenta; Premium sigue unos días más',
            $sub->grantsAccess() && $sub->status === SupplierSubscription::CANCELED => 'Cancelado: Premium sigue hasta el '.$until,
            $sub->grantsAccess() => 'Activo hasta el '.$until.' (se renueva solo)',
            in_array($sub->status, [SupplierSubscription::PENDING, SupplierSubscription::AUTHORIZED], true) && $sub->current_period_end === null => 'Pendiente de pago: seguís en el plan gratuito hasta que Mercado Pago confirme el cobro',
            $until !== null => 'Terminó el '.$until.': volviste al plan gratuito',
            default => null,
        };
    }

    /** La misma regla de moderación en SQL (catálogo y pedidos nuevos). */
    public static function applyOperating(Builder $query): Builder
    {
        return $query->where('suppliers.status', Supplier::APPROVED)->whereNull('suppliers.closed_at');
    }
}
