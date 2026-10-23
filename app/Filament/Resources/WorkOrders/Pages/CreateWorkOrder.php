<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\Activity\ActivityLogger;
use App\Services\WorkOrderAssignmentNotifier;
use App\Services\WorkOrders\AttendanceTimes;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Orden de trabajo creada. Avisamos a los técnicos que tienen las notificaciones activadas.';
    }

    protected function afterCreate(): void
    {
        $this->saveCallTime();

        $workOrder = $this->record;

        $workOrder->load('users');

        // Push a los técnicos asignados (después de responder; ver
        // SendWorkOrderAssignedNotification para las validaciones).
        app(WorkOrderAssignmentNotifier::class)->notifyNewAssignees($workOrder);

        // Los técnicos se avisan por push y Telegram. Ya no se manda WhatsApp
        // al teléfono del técnico (la integración está desactivada y solo
        // dejaba el número en el log).
    }

    /** Hora del aviso: se guarda aparte, con quién la cargó, solo si cambió (AttendanceTimes). */
    private function saveCallTime(): void
    {
        $value = $this->data['call_received_at'] ?? null;
        $at = filled($value) ? Carbon::parse($value, config('app.timezone')) : null;
        $current = $this->record->call_received_at;
        if (($at === null && $current === null) || ($at && $current && $at->equalTo($current))) {
            return;
        }
        app(AttendanceTimes::class)->recordCall(auth()->user(), $this->record, $at);
        ActivityLogger::record('work_order.call_time', $this->record, ['aviso' => $at?->format('d/m/Y H:i') ?? 'sin registrar'], 'Orden #'.$this->record->id);
    }
}
