<?php

namespace App\Filament\Resources\StockItems\Widgets;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Support\CompanyContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StockOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $companyId = CompanyContext::currentId();
        $items = StockItem::where('company_id', $companyId)->active();

        $low = (clone $items)->low()->count();
        $negative = (clone $items)->where('current_stock', '<', 0)->count();

        return [
            Stat::make('Materiales', (clone $items)->count()),
            Stat::make('Con stock bajo', $low)
                ->color($low > 0 ? 'danger' : 'success')
                ->description($negative > 0 ? "{$negative} en negativo" : ($low > 0 ? 'Reponer pronto' : 'Todo en orden')),
            Stat::make('Movimientos (30 días)', StockMovement::where('company_id', $companyId)->where('occurred_at', '>=', now()->subDays(30))->count()),
            Stat::make('Valor del stock', '$'.number_format((float) (clone $items)->where('current_stock', '>', 0)->selectRaw('SUM(current_stock * cost) as total')->value('total'), 0, ',', '.'))
                ->description('Stock actual × costo'),
        ];
    }
}
