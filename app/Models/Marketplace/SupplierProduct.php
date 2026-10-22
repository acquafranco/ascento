<?php

namespace App\Models\Marketplace;

use App\Support\Marketplace\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del catálogo de un proveedor. La compatibilidad es la que DECLARA
 * el proveedor: Ascento no la verifica y así se muestra. Lo ve un comprador
 * solo si está publicado, no lo ocultó la moderación y el proveedor está
 * aprobado (scopeVisible). El destacado no saltea nada de eso.
 */
class SupplierProduct extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const PAUSED = 'paused';

    public const RETIRED = 'retired';

    public const STATUSES = [
        self::DRAFT => 'Borrador',
        self::PUBLISHED => 'Publicado',
        self::PAUSED => 'Pausado',
        self::RETIRED => 'Retirado',
    ];

    /** Lo que carga el proveedor. Proveedor, estado, moderación y destacado: nunca desde un formulario. */
    protected $fillable = [
        'name', 'category', 'brand', 'model', 'part_number', 'description', 'compatibility', 'price_mode', 'price', 'availability',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'hidden_by_admin' => 'boolean',
        'featured_until' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SupplierProductPhoto::class)->orderBy('position')->orderBy('id');
    }

    /** Lo que puede ver un comprador. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('supplier_products.status', self::PUBLISHED)
            ->where('supplier_products.hidden_by_admin', false)
            ->whereHas('supplier', fn (Builder $s) => $s->approved());
    }

    public function isVisible(): bool
    {
        return $this->status === self::PUBLISHED && ! $this->hidden_by_admin && $this->supplier?->isApproved();
    }

    public function isFeatured(): bool
    {
        return $this->featured_until !== null && $this->featured_until->isFuture();
    }

    public function hasPrice(): bool
    {
        return $this->price_mode === 'fixed' && $this->price !== null;
    }

    public function priceLabel(): string
    {
        return $this->hasPrice() ? '$ '.number_format((float) $this->price, 0, ',', '.').($this->currency !== 'ARS' ? ' '.$this->currency : '') : 'A cotizar';
    }

    public function categoryLabel(): string
    {
        return Catalog::category($this->category);
    }

    public function availabilityLabel(): string
    {
        return Catalog::availability($this->availability);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
