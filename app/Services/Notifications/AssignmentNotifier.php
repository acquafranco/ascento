<?php

namespace App\Services\Notifications;

use App\Models\Building;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\AssignmentChangedNotification;

/**
 * Avisos al técnico cuando cambia lo que tiene asignado. Solo al técnico
 * afectado, de la misma empresa y con la cuenta activa (Notifier). Los
 * cambios de una orden que sigue teniendo asignada los avisa
 * WorkOrderAssignmentNotifier (push + bandeja).
 */
class AssignmentNotifier
{
    public function __construct(private Notifier $notifier) {}

    public function building(Building $building, int $userId, string $type, bool $assigned): void
    {
        $user = User::find($userId);

        if (! $user || $user->role !== 'technician') {
            return;
        }

        $what = ($type === 'inspection' ? 'la inspección de ' : 'el mantenimiento de ').trim($building->name.' '.$building->address);
        $slug = Company::whereKey($building->company_id)->value('slug');

        $this->notifier->sendTo($user, new AssignmentChangedNotification(
            $assigned ? 'assigned' : 'unassigned',
            $what,
            $assigned && $slug ? route('buildings.index', ['company' => $slug], false) : null,
        ), (int) $building->company_id);
    }

    /** @param iterable<int> $userIds técnicos que ya no están en la orden */
    public function removedFromWorkOrder(WorkOrder $workOrder, iterable $userIds): void
    {
        foreach ($userIds as $userId) {
            if ($user = User::find($userId)) {
                $this->notifier->sendTo($user, new AssignmentChangedNotification('wo_removed', 'la orden de trabajo #'.$workOrder->id), (int) $workOrder->company_id);
            }
        }
    }

    /** Orden eliminada (cancelada) con trabajo pendiente: avisa a sus técnicos, una vez. */
    public function workOrderCancelled(WorkOrder $workOrder): void
    {
        if (! in_array($workOrder->status, ['pending', 'in_progress'], true)) {
            return;
        }

        foreach ($workOrder->users()->get() as $user) {
            $this->notifier->sendTo($user, new AssignmentChangedNotification('wo_cancelled', 'la orden de trabajo #'.$workOrder->id, key: 'wo-cancelled:'.$workOrder->id), (int) $workOrder->company_id);
        }
    }
}
