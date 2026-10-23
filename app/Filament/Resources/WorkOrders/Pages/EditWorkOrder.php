<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\Activity\ActivityLogger;
use App\Services\Notifications\AssignmentNotifier;
use App\Services\WorkOrderAssignmentNotifier;
use App\Services\WorkOrders\AttendanceTimes;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    /** Técnicos asignados y datos de la orden antes de guardar. */
    protected array $previousTechnicianIds = [];

    protected array $previousSnapshot = [];

    protected function beforeSave(): void
    {
        $notifier = app(WorkOrderAssignmentNotifier::class);

        $this->previousTechnicianIds = $notifier->currentAssigneeIds($this->record);
        $this->previousSnapshot = $notifier->snapshot($this->record);
    }

    protected function afterSave(): void
    {
        $this->saveCallTime();

        $notifier = app(WorkOrderAssignmentNotifier::class);

        // Técnicos agregados en esta edición: "Nueva orden de trabajo".
        $notifier->notifyNewAssignees($this->record, $this->previousTechnicianIds);

        // Los que ya estaban: "Orden modificada", solo si cambió algo que les importa.
        $notifier->notifyChanges($this->record, $this->previousSnapshot, $this->previousTechnicianIds);

        // Los que se quitaron: "Ya no tenés asignada la orden" (sin detalle).
        $removed = array_diff($this->previousTechnicianIds, $notifier->currentAssigneeIds($this->record));

        if ($removed !== []) {
            app(AssignmentNotifier::class)->removedFromWorkOrder($this->record, $removed);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Solo órdenes sin historial (ver WorkOrder::canBeDeleted()).
            DeleteAction::make()
                ->visible(fn ($record) => $record->canBeDeleted()),
        ];
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
