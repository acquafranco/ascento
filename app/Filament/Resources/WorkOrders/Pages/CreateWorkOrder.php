<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\WhatsAppService;
use App\Services\WorkOrderAssignmentNotifier;
use Illuminate\Support\Facades\Log;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Orden de trabajo creada. Avisamos a los técnicos que tienen las notificaciones activadas.';
    }

    protected function afterCreate(): void
    {
        $workOrder = $this->record;

        $workOrder->load('users');

        // Push a los técnicos asignados (después de responder; ver
        // SendWorkOrderAssignedNotification para las validaciones).
        app(WorkOrderAssignmentNotifier::class)->notifyNewAssignees($workOrder);

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
