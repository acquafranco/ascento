<?php

namespace App\Services;

use App\Jobs\SendWorkOrderAssignedNotification;
use App\Jobs\SendWorkOrderUpdatedNotification;
use App\Models\Building;
use App\Models\WorkOrder;
use App\Support\WorkOrderLabels;

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

    /** Campos que, si cambian, le interesan al técnico. */
    public const WATCHED_FIELDS = ['building_id', 'unit', 'type', 'priority', 'notes', 'status'];

    /** @return array<string, mixed> */
    public function snapshot(WorkOrder $workOrder): array
    {
        return $workOrder->only(self::WATCHED_FIELDS);
    }

    /**
     * Avisa "Orden modificada" a los técnicos que YA estaban asignados y
     * siguen asignados (a los nuevos les llega "Nueva orden").
     *
     * @param  array<string, mixed>  $before  snapshot() antes de guardar
     * @param  iterable<int>  $previousUserIds
     * @return list<string> Lo que cambió (vacío si no se avisó nada)
     */
    public function notifyChanges(WorkOrder $workOrder, array $before, iterable $previousUserIds): array
    {
        $changes = $this->describeChanges($before, $this->snapshot($workOrder->fresh()));

        if ($changes === []) {
            return [];
        }

        $stillAssigned = collect($previousUserIds)
            ->map(fn ($id) => (int) $id)
            ->intersect($this->currentAssigneeIds($workOrder));

        foreach ($stillAssigned as $userId) {
            SendWorkOrderUpdatedNotification::dispatchAfterResponse($workOrder->id, $userId, $changes);
        }

        return $changes;
    }

    /** @return list<string> */
    private function describeChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::WATCHED_FIELDS as $field) {
            if ((string) ($before[$field] ?? '') === (string) ($after[$field] ?? '')) {
                continue;
            }

            $changes[] = match ($field) {
                'building_id' => 'edificio ('.trim((string) Building::withoutGlobalScopes()->whereKey($after[$field])->get(['name', 'address'])->map(fn ($b) => "{$b->name} {$b->address}")->first()).')',
                'unit' => 'unidad ('.($after[$field] ?: '—').')',
                'type' => 'tipo ('.WorkOrderLabels::type((string) $after[$field]).')',
                'priority' => 'prioridad ('.WorkOrderLabels::priority((string) $after[$field]).')',
                'notes' => 'detalle del trabajo',
                'status' => 'estado ('.WorkOrderLabels::status((string) $after[$field]).')',
            };
        }

        return $changes;
    }
}
