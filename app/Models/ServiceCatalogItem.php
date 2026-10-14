<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Servicio que la empresa ofrece (catálogo). No se asigna solo: se contrata
 * para un cliente, sus edificios o sus equipos (MaintenanceService). Su precio
 * es de referencia y se copia al contratar: cambiarlo no toca lo ya acordado.
 */
class ServiceCatalogItem extends Model
{
    use BelongsToCompany, SoftDeletes;

    /** Categoría => [etiqueta, ¿implica visitas periódicas?]. Solo mantenimiento e inspección cubren visitas. */
    public const CATEGORIES = [
        'maintenance' => ['Mantenimiento', true],
        'inspection' => ['Inspección', true],
        'guard' => ['Guardia técnica', false],
        'parachute' => ['Prueba de paracaídas', false],
        'cleaning' => ['Limpieza técnica', false],
        'extra' => ['Servicio extraordinario', false],
        'other' => ['Otro', false],
    ];

    public const FIXED = 'fixed';

    public const PER_EQUIPMENT = 'per_equipment';

    public const PER_STOP = 'per_stop';

    public const CUSTOM = 'custom';

    /** Modalidad => [etiqueta, nombre del precio, explicación]. */
    public const PRICING_MODES = [
        self::FIXED => ['Importe fijo', 'Importe por período', 'Un importe por cada contratación (por ejemplo, por edificio), sin importar cuántos equipos tenga.'],
        self::PER_EQUIPMENT => ['Por equipo', 'Precio por equipo', 'Precio por cada ascensor o montacargas alcanzado. El importe sale de los equipos cargados.'],
        self::PER_STOP => ['Por parada', 'Precio por parada', 'Precio por cada parada de los equipos alcanzados. Usa las paradas cargadas en la ficha de cada equipo.'],
        self::CUSTOM => ['Precio acordado', 'Importe acordado', 'Se acuerda un importe con cada cliente.'],
    ];

    /** Equipos que cuenta la modalidad «por equipo» o «por parada». */
    public const EQUIPMENT_KINDS = [
        'all' => 'Ascensores y montacargas',
        'elevator' => 'Solo ascensores',
        'freight' => 'Solo montacargas',
    ];

    protected $fillable = [
        'name', 'description', 'category', 'pricing_mode', 'base_price', 'billing_frequency',
        'equipment_kind', 'includes', 'notes', 'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'includes' => 'array',
        'is_active' => 'boolean',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(MaintenanceService::class, 'catalog_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category][0] ?? 'Mantenimiento';
    }

    public static function pricingModeLabel(?string $mode): string
    {
        return self::PRICING_MODES[$mode][0] ?? self::PRICING_MODES[self::CUSTOM][0];
    }

    /** ¿Las contrataciones de esta categoría cubren visitas de mantenimiento o inspección? (null = contratos anteriores al catálogo). */
    public static function providesVisits(?string $category): bool
    {
        return $category === null || (self::CATEGORIES[$category][1] ?? false);
    }

    public function countsEquipment(): bool
    {
        return in_array($this->pricing_mode, [self::PER_EQUIPMENT, self::PER_STOP], true);
    }
}
