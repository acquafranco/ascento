<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\User;
use App\Models\BuildingVisit;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    /**
     * Toma una orden de trabajo.
     * Toda la lógica de negocio debe vivir aquí.
     */
    public function start(WorkOrder $workOrder, User $user): void
    {
        DB::transaction(function () use ($workOrder, $user) {
            $workOrder = WorkOrder::lockForUpdate()
                ->where('id', $workOrder->id)
                ->where('company_id', $user->company_id)
                ->firstOrFail();

            if ($workOrder->status !== 'pending') {
                return;
            }

            $workOrder->participants()->syncWithoutDetaching([
                $user->id => [
                    'role' => 'participant',
                ],
            ]);

            $workOrder->update([
                'status' => 'in_progress',
                'started_at' => $workOrder->started_at ?? now(),
            ]);
        });
    }

    /**
     * Solicita la finalización de una orden.
     * No cambia el estado. Solo valida que el usuario pueda operar la orden.
     */
    public function requestCompletion(WorkOrder $workOrder, User $user): array
    {
        if ($workOrder->company_id !== $user->company_id) {
            abort(403);
        }

        if ($workOrder->status !== 'in_progress') {
            abort(409, 'Esta orden ya no puede finalizarse.');
        }

        return [
            'url' => route('delivery-notes.work-order', $workOrder),
        ];
    }

    /**
     * Finaliza una orden de trabajo.
     * Toda la lógica de negocio debe vivir aquí.
     */
    public function finish(WorkOrder $workOrder, User $user): void
{
    DB::transaction(function () use ($workOrder, $user) {

        $workOrder = WorkOrder::lockForUpdate()
            ->where('id', $workOrder->id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if ($workOrder->status !== 'in_progress') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | COMPLETAR ORDEN
        |--------------------------------------------------------------------------
        |
        | La finalización real se registra cuando se genera el remito.
        | La WorkOrder ya conoce a todos sus participantes.
        |
        */

        $workOrder->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);
    });
}
}
