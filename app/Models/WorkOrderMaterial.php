<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material usado en una orden de trabajo. Se descuenta del stock cuando la
 * orden se completa (o enseguida, si se agrega a una orden ya completada).
 * Ya descontado, no se modifica ni se borra: se corrige con un ajuste.
 */
class WorkOrderMaterial extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'work_order_id',
        'stock_item_id',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (WorkOrderMaterial $material) {
            $workOrder = WorkOrder::withoutGlobalScopes()->find($material->work_order_id);
            $item = StockItem::withoutGlobalScopes()->find($material->stock_item_id);

            // Orden y material tienen que ser de la misma empresa (y la del renglón).
            abort_unless($workOrder && $item && (int) $workOrder->company_id === (int) $item->company_id, 422, 'El material no pertenece a la empresa de la orden.');

            $material->company_id = $workOrder->company_id;
            $material->unit_cost = $item->cost;
        });

        // Agregado a una orden ya completada: se descuenta en el momento.
        static::created(function (WorkOrderMaterial $material) {
            if (WorkOrder::withoutGlobalScopes()->whereKey($material->work_order_id)->value('status') === 'completed') {
                app(StockService::class)->consumeMaterial($material);
            }
        });

        static::updating(function (WorkOrderMaterial $material) {
            // Ya descontado: no se cambia material ni cantidad.
            if ($material->getOriginal('stock_movement_id') && $material->isDirty(['stock_item_id', 'quantity'])) {
                return false;
            }
        });

        static::deleting(fn (WorkOrderMaterial $material) => $material->stock_movement_id === null);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class)->withTrashed();
    }

    /** Quién lo declaró: la oficina o el técnico al firmar el remito. */
    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by')->withTrashed();
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function isConsumed(): bool
    {
        return $this->stock_movement_id !== null;
    }
}
