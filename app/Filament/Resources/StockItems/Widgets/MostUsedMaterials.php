<?php

namespace App\Filament\Resources\StockItems\Widgets;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Support\CompanyContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Lo que más se consume en órdenes (últimos 90 días). */
class MostUsedMaterials extends TableWidget
{
    protected static ?string $heading = 'Más utilizados (últimos 90 días)';

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $companyId = CompanyContext::currentId();

        return $table
            ->query(
                StockItem::query()
                    ->where('stock_items.company_id', $companyId)
                    ->withSum(['movements as used' => fn ($q) => $q->where('type', StockMovement::OUT)->whereNotNull('work_order_id')->where('occurred_at', '>=', now()->subDays(90))], 'quantity')
                    ->whereHas('movements', fn ($q) => $q->where('type', StockMovement::OUT)->whereNotNull('work_order_id')->where('occurred_at', '>=', now()->subDays(90)))
                    ->orderBy('used')
            )
            ->paginated([5])
            ->columns([
                TextColumn::make('name')->label('Material'),
                TextColumn::make('used')->label('Usado')->formatStateUsing(fn ($state, StockItem $r) => $r->formatQuantity(abs((float) $state))),
                TextColumn::make('current_stock')->label('Stock')->formatStateUsing(fn ($state, StockItem $r) => $r->formatQuantity($state))
                    ->color(fn (StockItem $r) => $r->isLow() ? 'danger' : null),
            ])
            ->emptyStateHeading('Todavía no se usaron materiales en órdenes');
    }
}
