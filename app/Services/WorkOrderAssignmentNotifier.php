<?php

namespace App\Services;

use App\Jobs\SendWorkOrderAssignedNotification;
use App\Models\WorkOrder;

/**
 * Avisa a los técnicos que QUEDARON asignados a una orden y antes no lo
 * estaban (alta de la orden o reasignación). A quien ya estaba asignado no
 * se le vuelve a avisar por cada edición.
 */
class WorkOrderAssignmentNotifier
{
    /**
     * @param  iterable<int>  $previousUserIds  Técnicos asignados antes del cambio.
     * @return list<int> Ids a los que se les programó el aviso.
     */
    public function notifyNewAssignees(WorkOrder $workOrder, iterable $previousUserIds = []): array
    {
        $previous = collect($previousUserIds)->map(fn ($id) => (int) $id);

        $newIds = $workOrder->users()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->diff($previous)
            ->values()
            ->all();

        foreach ($newIds as $userId) {
            SendWorkOrderAssignedNotification::dispatchAfterResponse($workOrder->id, $userId);
        }

        return $newIds;
    }

    /** @return list<int> */
    public function currentAssigneeIds(WorkOrder $workOrder): array
    {
        return $workOrder->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
