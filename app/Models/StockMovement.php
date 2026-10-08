<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de stock. INMUTABLE: no se edita ni se borra; las correcciones
 * se hacen con un ajuste (queda todo en el historial).
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    public const IN = 'in';

    public const OUT = 'out';

    public const ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::IN => 'Entrada',
        self::OUT => 'Salida',
        self::ADJUSTMENT => 'Ajuste',
    ];

    protected $fillable = [
        'company_id',
        'stock_item_id',
        'type',
        'quantity',
        'balance_after',
        'user_id',
        'work_order_id',
        'work_order_material_id',
        'reason',
        'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
