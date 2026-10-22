<?php

namespace App\Filament\Resources\Marketplace\Widgets;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierPayment;
use App\Models\Marketplace\SupplierSubscription;
use App\Support\Marketplace\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Resumen comercial del marketplace para el SuperAdmin. Ingresos = SOLO cobros
 * aprobados por Mercado Pago (suscripciones y destacados). Nunca suma pagos
 * pendientes ni el valor de los pedidos entre compradores y proveedores.
 */
class MarketplaceRevenueStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $month = [now()->startOfMonth(), now()->endOfMonth()];
        $income = fn (string $kind) => SupplierPayment::where('status', SupplierPayment::APPROVED)->where('kind', $kind)->whereBetween('paid_at', $month)
            ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')
            ->map(fn ($total, $currency) => Money::format((float) $total, $currency))->implode(' · ') ?: Money::format(0.0, 'ARS');

        $trial = Supplier::where('status', Supplier::APPROVED)->whereNull('closed_at')->where('trial_ends_at', '>', now());

        return [
            Stat::make('Suscripciones cobradas este mes', $income('subscription'))->description('Cobros aprobados por Mercado Pago'),
            Stat::make('Destacados cobrados este mes', $income('featured'))->description('Pagos aprobados por Mercado Pago'),
            Stat::make('Proveedores en prueba', (clone $trial)->count())->description((clone $trial)->where('trial_ends_at', '<=', now()->addDays(7))->count().' terminan en 7 días'),
            Stat::make('Suscripciones activas', SupplierSubscription::where('current_period_end', '>', now())->count())
                ->description(SupplierSubscription::where('status', SupplierSubscription::PAST_DUE)->count().' con cobro rechazado'),
            Stat::make('Pagos pendientes o con problemas', SupplierPayment::whereIn('status', [SupplierPayment::PENDING, SupplierPayment::AMOUNT_MISMATCH])->count())
                ->color('warning')->description(FeaturedPlacement::where('status', FeaturedPlacement::NEEDS_REVIEW)->count().' destacados pagos para revisar'),
        ];
    }
}
