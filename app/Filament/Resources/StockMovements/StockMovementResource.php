<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\StockMovement;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Historial de movimientos de stock (solo lectura: los movimientos son inmutables). */
class StockMovementResource extends Resource
{
    use ScopedToCurrentCompany;

    protected static ?string $model = StockMovement::class;

    protected static ?string $modelLabel = 'Movimiento';

    protected static ?string $pluralModelLabel = 'Movimientos de stock';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['stockItem', 'user', 'workOrder.building']))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('stockItem.name')->label('Material')->searchable(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->formatStateUsing(fn ($state) => StockMovement::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'in' => 'success', 'out' => 'warning', default => 'gray'
                    }),
                TextColumn::make('quantity')->label('Cantidad')
                    ->formatStateUsing(fn ($state, StockMovement $r) => ((float) $state > 0 ? '+' : '').$r->stockItem?->formatQuantity($state)),
                TextColumn::make('balance_after')->label('Saldo')
                    ->formatStateUsing(fn ($state, StockMovement $r) => $r->stockItem?->formatQuantity($state))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
                TextColumn::make('reason')->label('Motivo')->wrap()->placeholder('—'),
                TextColumn::make('workOrder.id')->label('Orden')
                    ->formatStateUsing(fn ($state, StockMovement $r) => '#'.$state.($r->workOrder?->building ? ' · '.$r->workOrder->building->name : ''))
                    ->url(fn (StockMovement $r) => $r->work_order_id ? WorkOrderResource::getUrl('edit', ['record' => $r->work_order_id]) : null)
                    ->placeholder('—'),
                TextColumn::make('user.name')->label('Usuario')->placeholder('Automático'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(StockMovement::TYPES),
                SelectFilter::make('stock_item_id')->label('Material')->relationship('stockItem', 'name')->searchable()->preload(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
