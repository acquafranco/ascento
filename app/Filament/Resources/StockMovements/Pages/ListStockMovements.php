<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected ?string $subheading = 'Cada entrada, salida y ajuste queda registrado; no se editan ni se borran.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Volver al stock')->color('gray')->url(StockItemResource::getUrl('index')),
        ];
    }
}
