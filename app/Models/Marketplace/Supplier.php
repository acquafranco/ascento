<?php

namespace App\Models\Marketplace;

use App\Models\User;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Proveedor de repuestos (marketplace). Cuenta comercial aparte de las
 * empresas de mantenimiento: sus usuarios (role = supplier) no tienen
 * empresa y no ven nada de ninguna. Su catálogo se ve si el SuperAdmin lo
 * aprobó; publicar es gratis con un límite de productos (Premium lo amplía:
 * SupplierStanding y PublicationLimits).
 */
class Supplier extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const STATUSES = [
        self::PENDING => 'Pendiente de aprobación',
        self::APPROVED => 'Aprobado',
        self::REJECTED => 'Rechazado',
        self::SUSPENDED => 'Suspendido',
    ];

    /** Lo que el proveedor edita desde su perfil. El estado y la revisión: nunca. */
    protected $fillable = [
        'name', 'legal_name', 'description', 'contact_email', 'contact_phone', 'website', 'coverage', 'categories', 'brands',
    ];

    protected $casts = [
        'categories' => 'array',
        'brands' => 'array',
        'approved_at' => 'datetime',
        'commercial_state_at' => 'datetime',
        'closed_at' => 'datetime',
        'sells_products' => 'boolean',
    ];

    /** Moneda de los precios según el país registrado (config/marketplace.php). */
    public static function currencyFor(?string $country): string
    {
        return (string) config('marketplace.currencies.'.strtoupper((string) $country), 'USD');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MarketplaceRequest::class);
    }

    /**
     * ¿Vende productos? Siempre, salvo una cuenta anterior registrada «solo
     * servicios» (función retirada): no tiene catálogo hasta cargar su
     * identificación fiscal desde «Mi perfil».
     */
    public function sellsProducts(): bool
    {
        return $this->sells_products !== false;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(SupplierSubscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->latest('id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(FeaturedPlacement::class)->latest('id');
    }

    /** Estado comercial (prueba, suscripción, moderación…): ver SupplierStanding. */
    public function standing(): string
    {
        return SupplierStanding::of($this);
    }

    /** ¿Su catálogo se ve y recibe pedidos nuevos? (aprobado y sin cerrar) */
    public function isOperating(): bool
    {
        return SupplierStanding::operating($this);
    }

    public function scopeOperating(Builder $query): Builder
    {
        return SupplierStanding::applyOperating($query);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Reputación: solo operaciones que el comprador confirmó como completadas. */
    public function completedOperations(): int
    {
        return $this->requests()->where('status', MarketplaceRequest::COMPLETED)->count();
    }
}
