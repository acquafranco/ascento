<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Publicación destacada de un producto: comprada por el proveedor (se activa
 * solo con el pago aprobado) o asignada por el SuperAdmin. Destacar no
 * saltea la moderación ni la relevancia de la búsqueda (ver CatalogRanking).
 */
class FeaturedPlacement extends Model
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** Se cobró, pero el producto ya no era elegible: lo resuelve el SuperAdmin (sin activarlo). */
    public const NEEDS_REVIEW = 'needs_review';

    public const STATUSES = [
        self::PENDING_PAYMENT => 'Pendiente de pago',
        self::ACTIVE => 'Activo',
        self::EXPIRED => 'Vencido',
        self::CANCELLED => 'Cancelado',
        self::NEEDS_REVIEW => 'Pagado: requiere revisión',
    ];

    /** Un checkout sin pagar se abandona después de este tiempo. */
    public const CHECKOUT_HOURS = 24;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'ending_notified_at' => 'datetime',
        'ended_notified_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'supplier_product_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->latest('id');
    }

    /** Vigentes hoy (activas y dentro de sus fechas). */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('featured_placements.status', self::ACTIVE)
            ->where('featured_placements.starts_at', '<=', now())
            ->where('featured_placements.ends_at', '>', now());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function sourceLabel(): string
    {
        return $this->source === 'admin' ? 'Asignado por Ascento' : 'Contratado';
    }
}
