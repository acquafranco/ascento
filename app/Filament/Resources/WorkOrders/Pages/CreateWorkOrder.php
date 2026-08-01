<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function afterCreate(): void
    {
        $workOrder = $this->record;

        $workOrder->load('users');

        foreach ($workOrder->users as $technician) {
            if ($technician->phone) {
                Log::info('Telefono tecnico WhatsApp', [
                    'technician_id' => $technician->id,
                    'phone' => $technician->phone,
                ]);

                app(WhatsAppService::class)->sendWorkOrderButton(
                    $workOrder,
                    $technician->phone
                );
            }
        }
    }
}
