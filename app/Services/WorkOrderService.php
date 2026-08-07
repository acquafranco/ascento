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
        $finishedAt = now();

        DB::transaction(function () use ($workOrder, $user, $finishedAt) {
            $workOrder->load('participants');

            $participants = $workOrder->participants;

            // Si hay más de un participante, no se completa hasta que todos confirmen.
            if ($participants->count() > 1) {
                return;
            }

            $workOrder->update([
                'status' => 'completed',
                'finished_at' => $finishedAt,
            ]);

            foreach ($participants as $technician) {
                if ($technician->id !== $user->id) {
                    continue;
                }

                BuildingVisit::firstOrCreate([
                    'company_id' => $workOrder->company_id,
                    'work_order_id' => $workOrder->id,
                    'user_id' => $technician->id,
                ], [
                    'building_id' => $workOrder->building_id,
                    'source' => 'work_order',
                    'visit_type' => 'work_order',
                    'assignment_type' => 'work_order',
                    'month' => $finishedAt->month,
                    'year' => $finishedAt->year,
                    'visited_at' => $finishedAt,
                    'started_at' => $workOrder->started_at,
                    'finished_at' => $finishedAt,
                    'work_type' => $workOrder->type,
                    'unit' => $workOrder->unit,
                    'notes' => $workOrder->notes,
                ]);
            }
        });
    }
}
