<?php

namespace App\Filament\Resources\StockItems\Pages;

use App\Filament\Resources\StockItems\StockItemResource;
use App\Services\Stock\StockService;
use Filament\Resources\Pages\CreateRecord;

class CreateStockItem extends CreateRecord
{
    protected static string $resource = StockItemResource::class;

    protected function afterCreate(): void
    {
        $initial = (float) ($this->data['initial_stock'] ?? 0);

        if ($initial > 0) {
            app(StockService::class)->receive($this->record, $initial, auth()->user(), 'Stock inicial');
        }
    }
}
