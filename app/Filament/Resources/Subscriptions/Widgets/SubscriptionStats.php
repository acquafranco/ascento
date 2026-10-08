<?php

namespace App\Filament\Resources\Subscriptions\Widgets;

use App\Models\Company;
use App\Models\SubscriptionPayment;
use App\Support\SubscriptionStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Resumen comercial para el SuperAdmin.
 */
class SubscriptionStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $counts = Company::query()->with('latestSubscription')->get()
            ->countBy(fn (Company $company) => SubscriptionStatus::key($company));

        $monthIncome = SubscriptionPayment::where('status', SubscriptionPayment::APPROVED)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        return [
            Stat::make('Suscripciones activas', ($counts['active'] ?? 0) + ($counts['awaiting_payment'] ?? 0))
                ->color('success'),
            Stat::make('En prueba gratis', $counts['trial'] ?? 0)
                ->description(($counts['trial_ended'] ?? 0).' con la prueba terminada sin suscribirse'),
            Stat::make('Pago rechazado', $counts['past_due'] ?? 0)
                ->color(($counts['past_due'] ?? 0) > 0 ? 'danger' : 'gray')
                ->description('Mercado Pago está reintentando'),
            Stat::make('Cobrado este mes', '$'.number_format((float) $monthIncome, 0, ',', '.'))
                ->description('Cuotas aprobadas en '.now()->translatedFormat('F')),
        ];
    }
}
