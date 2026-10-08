<?php

namespace App\Filament\Resources\Receivables\Widgets;

use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Support\CompanyContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Resumen de cobranzas de la empresa actual. */
class ReceivablesOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $companyId = CompanyContext::currentId();
        $base = fn () => Receivable::where('company_id', $companyId);
        $balance = 'SUM(amount - paid_amount)';
        $money = fn ($value) => '$'.number_format((float) $value, 0, ',', '.');

        $pendingTotal = $base()->open()->selectRaw("{$balance} as total")->value('total');
        $overdueTotal = $base()->overdue()->selectRaw("{$balance} as total")->value('total');
        $pendingCount = $base()->open()->count();
        $overdueCount = $base()->overdue()->count();
        $collected = ReceivablePayment::where('company_id', $companyId)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        return [
            Stat::make('Total pendiente', $money($pendingTotal))
                ->description($pendingCount.' '.($pendingCount === 1 ? 'cuenta pendiente' : 'cuentas pendientes')),
            Stat::make('Total vencido', $money($overdueTotal))
                ->description($overdueCount.' '.($overdueCount === 1 ? 'cuenta vencida' : 'cuentas vencidas'))
                ->color($overdueCount > 0 ? 'danger' : 'success'),
            Stat::make('Cobrado este mes', $money($collected))
                ->description(ucfirst(now()->translatedFormat('F Y')))
                ->color('success'),
        ];
    }
}
