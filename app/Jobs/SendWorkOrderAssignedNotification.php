<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\WorkOrderAssignedNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía el push de "Nueva orden de trabajo" a UN técnico.
 *
 * Corre después de responder al admin (dispatchAfterResponse: no hace
 * falta un worker de colas) y vuelve a validar TODO con datos frescos de
 * la base, así un cambio entre la asignación y el envío nunca produce una
 * notificación incorrecta.
 */
class SendWorkOrderAssignedNotification
{
    use Dispatchable;

    /** Estados en los que tiene sentido avisar: la orden se puede tomar o está en curso. */
    public const NOTIFIABLE_STATUSES = ['pending', 'in_progress'];

    public function __construct(
        public int $workOrderId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $workOrder = WorkOrder::withoutGlobalScope('company')
            ->with(['company', 'building.client'])
            ->find($this->workOrderId);

        $user = User::find($this->userId);

        if (! $workOrder || ! $user || ! static::shouldNotify($workOrder, $user)) {
            return;
        }

        try {
            $user->notify(new WorkOrderAssignedNotification($workOrder));
        } catch (Throwable $e) {
            // Un servicio de push caído no debe romper nada del lado del admin.
            Log::warning('No se pudo enviar el push de orden de trabajo', [
                'work_order_id' => $workOrder->id,
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Única regla de "¿le corresponde este aviso a este usuario?".
     */
    public static function shouldNotify(WorkOrder $workOrder, User $user): bool
    {
        $company = $workOrder->company;

        return $company !== null
            // Misma empresa: jamás un aviso de la empresa A a alguien de B.
            && (int) $user->company_id === (int) $workOrder->company_id
            // Solo técnicos activos (no admins, no SuperAdmin, no desactivados).
            && $user->canReceiveWorkOrderPush()
            // La orden sigue viva y abierta (no eliminada/cancelada ni cerrada).
            && ! $workOrder->trashed()
            && in_array($workOrder->status, self::NOTIFIABLE_STATUSES, true)
            // Sigue asignado al momento de enviar.
            && $workOrder->users()->whereKey($user->id)->exists()
            // La empresa tiene acceso vigente (suscripción / prueba).
            && $company->hasActiveAccess();
    }
}
