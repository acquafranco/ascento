<?php

namespace App\Services\Stock;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ÚNICO punto que modifica el stock. Cada cambio es un movimiento inmutable
 * con el saldo resultante, dentro de una transacción con lock del material.
 *
 * Stock insuficiente:
 * - salida MANUAL: se rechaza (el admin está en la oficina y puede cargar
 *   primero la entrada);
 * - consumo de una ORDEN: se permite quedar en negativo, con alerta. El
 *   repuesto ya se colocó en el edificio y no se bloquea el cierre de un
 *   trabajo real (lo cierra el técnico firmando el remito en la obra).
 */
class StockService
{
    public function receive(StockItem $item, float $quantity, ?User $user, ?string $reason = null): StockMovement
    {
        $this->assertPositive($quantity);

        return $this->record($item, StockMovement::IN, $quantity, $user, $reason);
    }

    public function issue(StockItem $item, float $quantity, ?User $user, ?string $reason = null): StockMovement
    {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($item, $quantity, $user, $reason) {
            $locked = StockItem::withoutGlobalScopes()->lockForUpdate()->findOrFail($item->id);

            if ((float) $locked->current_stock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'No hay stock suficiente: quedan '.$locked->formatQuantity($locked->current_stock).'. Cargá primero la entrada o hacé un ajuste.',
                ]);
            }

            return $this->record($locked, StockMovement::OUT, -$quantity, $user, $reason);
        });
    }

    /** Ajuste por conteo físico: deja el stock en $countedQuantity. */
    public function adjustTo(StockItem $item, float $countedQuantity, ?User $user, string $reason): ?StockMovement
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo del ajuste.']);
        }

        return DB::transaction(function () use ($item, $countedQuantity, $user, $reason) {
            $locked = StockItem::withoutGlobalScopes()->lockForUpdate()->findOrFail($item->id);
            $delta = round($countedQuantity - (float) $locked->current_stock, 2);

            return $delta == 0.0 ? null : $this->record($locked, StockMovement::ADJUSTMENT, $delta, $user, $reason);
        });
    }

    /**
     * Descuenta los materiales de una orden completada. Idempotente: los
     * renglones ya descontados se saltean (y la base impide un segundo
     * movimiento por renglón).
     *
     * @return int Cantidad de renglones descontados ahora.
     */
    public function consumeWorkOrder(WorkOrder $workOrder): int
    {
        $count = 0;

        WorkOrderMaterial::withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->whereNull('stock_movement_id')
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                if ($this->consumeMaterial(WorkOrderMaterial::withoutGlobalScopes()->find($id))) {
                    $count++;
                }
            });

        return $count;
    }

    public function consumeMaterial(WorkOrderMaterial $material): bool
    {
        return DB::transaction(function () use ($material) {
            $row = WorkOrderMaterial::withoutGlobalScopes()->lockForUpdate()->find($material->id);

            if (! $row || $row->stock_movement_id !== null) {
                return false; // ya descontado
            }

            $workOrder = WorkOrder::withoutGlobalScopes()->withTrashed()->findOrFail($row->work_order_id);
            $item = StockItem::withoutGlobalScopes()->withTrashed()->lockForUpdate()->findOrFail($row->stock_item_id);

            // Nunca entre empresas.
            if ((int) $item->company_id !== (int) $workOrder->company_id || (int) $row->company_id !== (int) $workOrder->company_id) {
                abort(422, 'El material no pertenece a la empresa de la orden.');
            }

            $movement = $this->record(
                $item,
                StockMovement::OUT,
                -(float) $row->quantity,
                auth()->user(),
                'Orden de trabajo #'.$workOrder->id,
                $workOrder->id,
                $row->id,
            );

            $row->stock_movement_id = $movement->id;
            $row->saveQuietly();

            return true;
        });
    }

    private function record(
        StockItem $item,
        string $type,
        float $delta,
        ?User $user,
        ?string $reason,
        ?int $workOrderId = null,
        ?int $workOrderMaterialId = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $type, $delta, $user, $reason, $workOrderId, $workOrderMaterialId) {
            $locked = StockItem::withoutGlobalScopes()->withTrashed()->lockForUpdate()->findOrFail($item->id);

            $balance = round((float) $locked->current_stock + $delta, 2);
            $locked->forceFill(['current_stock' => $balance])->saveQuietly();
            $item->setRawAttributes($locked->getAttributes(), true);

            $movement = new StockMovement([
                'stock_item_id' => $locked->id,
                'type' => $type,
                'quantity' => $delta,
                'balance_after' => $balance,
                'user_id' => $user?->id,
                'work_order_id' => $workOrderId,
                'work_order_material_id' => $workOrderMaterialId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            $movement->company_id = $locked->company_id;
            $movement->save();

            return $movement;
        });
    }

    private function assertPositive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad tiene que ser mayor a cero.']);
        }
    }
}
