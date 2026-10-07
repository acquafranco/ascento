<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\WorkOrderAssignmentNotifier;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    /** Técnicos asignados antes de guardar (para avisar solo a los nuevos). */
    protected array $previousTechnicianIds = [];

    protected function beforeSave(): void
    {
        $this->previousTechnicianIds = app(WorkOrderAssignmentNotifier::class)
            ->currentAssigneeIds($this->record);
    }

    protected function afterSave(): void
    {
        // Reasignación: avisa solo a los técnicos agregados en esta edición.
        app(WorkOrderAssignmentNotifier::class)
            ->notifyNewAssignees($this->record, $this->previousTechnicianIds);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Solo órdenes sin historial (ver WorkOrder::canBeDeleted()).
            DeleteAction::make()
                ->visible(fn ($record) => $record->canBeDeleted()),
        ];
    }
}
