<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\WhatsAppService;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function afterCreate(): void
{
    $workOrder = $this->record;

    if ($workOrder->technician?->phone) {
        app(WhatsAppService::class)->sendWorkOrder($workOrder);
    }
}
}
