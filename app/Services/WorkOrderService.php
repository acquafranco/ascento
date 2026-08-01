<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\User;
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
            // Mover aquí el contenido del método start()
            // del WorkOrderController (sin redirects ni mensajes flash).
        });
    }

    /**
     * Finaliza una orden de trabajo.
     * Toda la lógica de negocio debe vivir aquí.
     */
    public function finish(WorkOrder $workOrder, User $user): void
    {
        DB::transaction(function () use ($workOrder, $user) {
            // Mover aquí el contenido del método finish()
            // del WorkOrderController (sin redirects ni mensajes flash).
        });
    }
}
