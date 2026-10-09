<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\WorkOrderUpdatedNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push "Orden modificada" a UN técnico. Mismas validaciones que el aviso de
 * orden nueva (empresa, rol, asignación, orden abierta, acceso de la empresa).
 */
class SendWorkOrderUpdatedNotification
{
    use Dispatchable;

    /** @param  list<string>  $changes */
    public function __construct(
        public int $workOrderId,
        public int $userId,
        public array $changes,
    ) {}

    public function handle(): void
    {
        $workOrder = WorkOrder::withoutGlobalScope('company')
            ->with(['company', 'building.client'])
            ->find($this->workOrderId);

        $user = User::find($this->userId);

        if (! $workOrder || ! $user || ! SendWorkOrderAssignedNotification::shouldNotify($workOrder, $user)) {
            return;
        }

        try {
            $user->notify(new WorkOrderUpdatedNotification($workOrder, $this->changes));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el push de orden modificada', [
                'work_order_id' => $workOrder->id,
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
        }
    }
}
