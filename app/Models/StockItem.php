<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Material / repuesto del stock de una empresa. El stock actual SOLO lo
 * modifica StockService (no es fillable).
 */
class StockItem extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const UNITS = [
        'unidad' => 'Unidad',
        'metro' => 'Metro',
        'litro' => 'Litro',
        'kg' => 'Kilogramo',
        'par' => 'Par',
        'juego' => 'Juego',
        'rollo' => 'Rollo',
        'caja' => 'Caja',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'unit',
        'cost',
        'min_stock',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'current_stock' => 'decimal:2',
        'min_stock' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('occurred_at')->latest('id');
    }

    public function workOrderMaterials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    /** Stock actual igual o menor al mínimo (incluye negativo). */
    public function isLow(): bool
    {
        return (float) $this->current_stock <= (float) $this->min_stock;
    }

    public function scopeLow(Builder $query): Builder
    {
        return $query->whereColumn('current_stock', '<=', 'min_stock');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function unitLabel(): string
    {
        return self::UNITS[$this->unit] ?? $this->unit;
    }

    /** "5 metros", "1 unidad", "2,5 kg". */
    public function formatQuantity(float|string|null $quantity): string
    {
        $value = (float) $quantity;
        $number = fmod($value, 1.0) === 0.0 ? number_format($value, 0, ',', '.') : number_format($value, 2, ',', '.');

        return $number.' '.$this->unit;
    }
}
