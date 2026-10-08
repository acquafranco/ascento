<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\WorkOrderAssignmentNotifier;

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

        // Los técnicos se avisan por push y Telegram. Ya no se manda WhatsApp
        // al teléfono del técnico (la integración está desactivada y solo
        // dejaba el número en el log).
    }
}
