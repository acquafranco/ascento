<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Solo órdenes sin historial (ver WorkOrder::canBeDeleted()).
            DeleteAction::make()
                ->visible(fn ($record) => $record->canBeDeleted()),
        ];
    }
}
