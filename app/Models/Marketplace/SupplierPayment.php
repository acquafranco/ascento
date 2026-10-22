<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro de Ascento a un proveedor (cuota de la suscripción o compra de un
 * destacado) tal como lo informó la pasarela. Solo los «approved» son
 * ingresos. Nunca incluye pagos entre compradores y proveedores.
 */
class SupplierPayment extends Model
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const REFUNDED = 'refunded';

    public const STATUSES = [
        self::APPROVED => 'Aprobado',
        self::PENDING => 'Pendiente',
        self::REJECTED => 'Rechazado',
        self::AMOUNT_MISMATCH => 'Importe distinto (revisar)',
        self::REFUNDED => 'Devuelto',
    ];

    public const KINDS = ['subscription' => 'Suscripción', 'featured' => 'Destacado'];

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SupplierSubscription::class, 'supplier_subscription_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(FeaturedPlacement::class, 'featured_placement_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
