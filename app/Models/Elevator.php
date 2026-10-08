<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legajo técnico de un equipo (ascensor o montacargas) de un edificio.
 *
 * Se identifica por (edificio, label), el mismo valor que ya guardan
 * reportes, órdenes y presupuestos ("Ascensor 1"): el historial se arma con
 * esos registros, no se copia.
 */
class Elevator extends Model
{
    use BelongsToCompany;

    public const MACHINE_TYPES = [
        'traction' => 'Tracción con sala de máquinas',
        'traction_mrl' => 'Tracción sin sala de máquinas',
        'hydraulic' => 'Hidráulico',
        'other' => 'Otro',
    ];

    /** Datos mínimos para considerar la ficha "completa". */
    public const ESSENTIAL_FIELDS = ['manufacturer', 'model', 'serial_number', 'year', 'capacity_kg', 'stops'];

    protected $fillable = [
        'manufacturer', 'model', 'serial_number', 'year', 'capacity_kg', 'capacity_people', 'speed_ms', 'stops',
        'installed_at', 'installer', 'machine_type', 'controller', 'motor', 'doors', 'door_operator', 'components', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'year' => 'integer',
        'capacity_kg' => 'integer',
        'capacity_people' => 'integer',
        'speed_ms' => 'decimal:2',
        'stops' => 'integer',
        'installed_at' => 'date',
    ];

    protected static function booted(): void
    {
        // Empresa, edificio, label y tipo los define el sistema (nunca el form).
        static::creating(function (Elevator $elevator) {
            $building = Building::withoutGlobalScopes()->withTrashed()->find($elevator->building_id);

            abort_unless($building, 422);

            $elevator->company_id = $building->company_id;
        });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ElevatorDocument::class)->latest();
    }

    /** Reportes de este equipo (mismo edificio y label). */
    public function reports()
    {
        return Report::where('building_id', $this->building_id)->where('elevator_number', $this->label);
    }

    /** Órdenes de trabajo de este equipo. */
    public function workOrders()
    {
        return WorkOrder::where('building_id', $this->building_id)->where('unit', $this->label);
    }

    /** Presupuestos de este equipo. */
    public function quotes()
    {
        return Quote::where('building_id', $this->building_id)->where('unit', $this->label);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return trim($this->label.' · '.$this->building?->name);
    }

    /** ¿Faltan datos esenciales de la ficha? */
    public function missingEssentials(): array
    {
        return array_values(array_filter(self::ESSENTIAL_FIELDS, fn ($field) => blank($this->{$field})));
    }

    /**
     * Crea los legajos que falten para los equipos del edificio. Nunca borra:
     * si el edificio tiene menos equipos, el legajo queda inactivo (con su historia).
     */
    public static function syncForBuilding(Building $building): void
    {
        $labels = $building->unitLabels();

        foreach ($labels as $label) {
            // building_id / label no son asignables desde formularios: se fijan acá.
            $elevator = static::withoutGlobalScopes()->where('building_id', $building->id)->where('label', $label)->first()
                ?? (new static)->forceFill(['building_id' => $building->id, 'label' => $label]);
            $elevator->kind = str_starts_with($label, 'Montacargas') ? 'freight' : 'elevator';
            $elevator->is_active = true;
            $elevator->company_id = $building->company_id;
            $elevator->save();
        }

        static::withoutGlobalScopes()
            ->where('building_id', $building->id)
            ->whereNotIn('label', $labels ?: [''])
            ->update(['is_active' => false]);
    }
}
