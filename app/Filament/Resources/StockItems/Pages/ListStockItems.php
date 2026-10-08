<?php

namespace App\Filament\Resources\StockItems\Pages;

use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockItems\Widgets\MostUsedMaterials;
use App\Filament\Resources\StockItems\Widgets\RecentStockMovements;
use App\Filament\Resources\StockItems\Widgets\StockOverview;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockItems extends ListRecords
{
    protected static string $resource = StockItemResource::class;

    protected ?string $subheading = 'Repuestos y materiales. Se descuentan solos al completar una orden de trabajo que los usó.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('movements')->label('Historial de movimientos')->icon('heroicon-o-clock')->color('gray')
                ->url(StockMovementResource::getUrl('index')),
            CreateAction::make()->label('Nuevo material'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [StockOverview::class];
    }

    protected function getFooterWidgets(): array
    {
        return [MostUsedMaterials::class, RecentStockMovements::class];
    }
}
