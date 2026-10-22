<?php

namespace App\Models\Marketplace;

use App\Services\Marketplace\PublicationLimits;
use App\Support\Marketplace\Catalog;
use App\Support\Marketplace\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del catálogo de un proveedor. La compatibilidad es la que DECLARA
 * el proveedor: Ascento no la verifica y así se muestra. Lo ve un comprador
 * solo si está publicado, no lo ocultó la moderación y el proveedor está
 * aprobado, y si entra en el límite de publicaciones de su plan HOY
 * (scopeVisible). El destacado no saltea nada de eso. Si el proveedor deja de estar habilitado, nada se borra: sus
 * productos publicados vuelven a verse cuando se habilita otra vez.
 */
class SupplierProduct extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const PAUSED = 'paused';

    public const RETIRED = 'retired';

    /** No entra en el límite de publicaciones del plan (lo puso el sistema; no es moderación). */
    public const OVER_LIMIT = 'over_limit';

    public const STATUSES = [
        self::DRAFT => 'Borrador',
        self::PUBLISHED => 'Publicado',
        self::PAUSED => 'Pausado',
        self::OVER_LIMIT => 'Fuera del límite del plan',
        self::RETIRED => 'Retirado',
    ];

    /** Lo que carga el proveedor. Proveedor, estado, moderación y destacado: nunca desde un formulario. */
    protected $fillable = [
        'name', 'category', 'brand', 'model', 'part_number', 'description', 'compatibility', 'price_mode', 'price', 'availability',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'hidden_by_admin' => 'boolean',
        'keep_on_free' => 'boolean',
        'limit_hidden_at' => 'datetime',
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
            ->whereHas('supplier', fn (Builder $s) => $s->operating())
            // Dentro del límite del plan HOY (también antes de que corra marketplace:tick).
            ->whereIn('supplier_products.id', PublicationLimits::withinLimit());
    }

    /** La misma regla que scopeVisible (una sola fuente: la consulta). */
    public function isVisible(): bool
    {
        return $this->exists && $this->status === self::PUBLISHED && ! $this->hidden_by_admin
            && static::visible()->whereKey($this->id)->exists();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MarketplaceReport::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(FeaturedPlacement::class)->latest('id');
    }

    /** ¿Destacado vigente ahora? (el catálogo lo trae calculado en featured_now; si no, se consulta el historial). */
    public function isFeatured(): bool
    {
        if (array_key_exists('featured_now', $this->attributes)) {
            return (bool) $this->attributes['featured_now'];
        }

        return FeaturedPlacement::running()->where('supplier_product_id', $this->id)->exists();
    }

    public function hasPrice(): bool
    {
        return $this->price_mode === 'fixed' && $this->price !== null;
    }

    public function priceLabel(): string
    {
        return $this->hasPrice() ? Money::format((float) $this->price, $this->currency) : 'A cotizar';
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
