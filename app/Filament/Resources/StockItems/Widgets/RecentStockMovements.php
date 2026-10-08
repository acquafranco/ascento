<?php

namespace App\Filament\Resources\StockItems\Widgets;

use App\Models\StockMovement;
use App\Support\CompanyContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentStockMovements extends TableWidget
{
    protected static ?string $heading = 'Movimientos recientes';

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(StockMovement::query()->where('company_id', CompanyContext::currentId())->with('stockItem')->latest('occurred_at')->latest('id'))
            ->paginated([5])
            ->columns([
                TextColumn::make('occurred_at')->label('Fecha')->dateTime('d/m H:i'),
                TextColumn::make('stockItem.name')->label('Material'),
                TextColumn::make('quantity')->label('Cantidad')
                    ->formatStateUsing(fn ($state, StockMovement $r) => ((float) $state > 0 ? '+' : '').$r->stockItem?->formatQuantity($state))
                    ->color(fn ($state) => (float) $state < 0 ? 'warning' : 'success'),
                TextColumn::make('reason')->label('Motivo')->limit(30)->placeholder('—'),
            ])
            ->emptyStateHeading('Sin movimientos todavía');
    }
}
