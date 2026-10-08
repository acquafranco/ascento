<?php

namespace App\Filament\Resources\StockItems\Pages;

use App\Filament\Resources\StockItems\StockItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStockItem extends EditRecord
{
    protected static string $resource = StockItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StockItemResource::movementAction('in', 'Entrada', 'heroicon-o-arrow-down-tray', 'success'),
            StockItemResource::movementAction('out', 'Salida', 'heroicon-o-arrow-up-tray', 'warning'),
            StockItemResource::movementAction('adjustment', 'Ajuste', 'heroicon-o-scale', 'gray'),
            DeleteAction::make()->label('Eliminar')->modalDescription('El material deja de aparecer, pero su historial de movimientos se conserva.'),
        ];
    }
}
