<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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
        'public_status_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Empresa, edificio, label y tipo los define el sistema (nunca el form).
        static::creating(function (Elevator $elevator) {
            $building = Building::withoutGlobalScopes()->withTrashed()->find($elevator->building_id);

            abort_unless($building, 422);

            $elevator->company_id = $building->company_id;
        });

        // Los contadores del edificio salen siempre de sus equipos activos.
        static::saved(fn (Elevator $elevator) => Building::refreshEquipmentCounts((int) $elevator->building_id));
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function parachuteSchedule(): HasOne
    {
        return $this->hasOne(ParachuteSchedule::class);
    }

    public function parachuteTests(): HasMany
    {
        return $this->hasMany(ParachuteTest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ElevatorDocument::class)->latest();
    }

    /** Reportes de este equipo (mismo edificio y label). */
    public function reports()
    {
        return Report::incidents()->where('building_id', $this->building_id)->where('elevator_number', $this->label);
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

    /**
     * Ficha incompleta: falta algún dato básico (vacío o sin cargar). Misma
     * regla que missingEssentials(), resuelta en la base.
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (self::ESSENTIAL_FIELDS as $field) {
                $q->orWhereNull($field);
                if (in_array($field, ['manufacturer', 'model', 'serial_number'], true)) {
                    $q->orWhereRaw("TRIM({$field}) = ''");
                }
            }
        });
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

    public const KINDS = ['elevator' => 'Ascensor', 'freight' => 'Montacargas'];

    /**
     * Estado que la empresa decide informar en el QR público (Etapa 7). Es
     * informativo: no es una certificación de seguridad.
     */
    public const PUBLIC_STATUSES = [
        'in_service' => 'En servicio',
        'out_of_service' => 'Fuera de servicio',
        'under_maintenance' => 'En mantenimiento o reparación',
    ];

    /**
     * Guarda los equipos del formulario del edificio: cada uno con su tipo
     * (ascensor / montacargas) y su tracción. Nunca borra: un equipo que se
     * quita queda inactivo con su historial. Los nuevos reciben el número
     * siguiente de su tipo ("Ascensor 3"), sin renumerar a los existentes
     * (reportes, órdenes y presupuestos los nombran así). El tipo de un equipo
     * existente no cambia (su nombre es parte del historial).
     *
     * @param  array<int, array{id?: mixed, kind?: ?string, machine_type?: ?string}>  $items
     */
    public static function syncFromForm(Building $building, array $items): void
    {
        DB::transaction(function () use ($building, $items) {
            $existing = static::withoutGlobalScopes()->where('building_id', $building->id)->lockForUpdate()->get()->keyBy('id');
            $next = $existing->groupBy('kind')->map(fn ($group, $kind) => $group->map(fn ($e) => (int) preg_replace('/\D+/', '', $e->label))->max());
            $keep = [];

            foreach ($items as $item) {
                $machine = array_key_exists((string) ($item['machine_type'] ?? ''), self::MACHINE_TYPES) ? $item['machine_type'] : null;
                $id = (int) ($item['id'] ?? 0);

                if ($id && $existing->has($id)) {
                    $elevator = $existing[$id];
                    $elevator->is_active = true;
                    if ($machine !== null) {
                        $elevator->machine_type = $machine;
                    }
                    $elevator->save();
                    $keep[] = $id;

                    continue;
                }

                $kind = array_key_exists((string) ($item['kind'] ?? ''), self::KINDS) ? $item['kind'] : 'elevator';
                $number = (int) ($next[$kind] ?? 0) + 1;
                // Nunca repetir una etiqueta del edificio (activa o no): único en la base.
                while ($existing->contains('label', self::KINDS[$kind].' '.$number)) {
                    $number++;
                }
                $next[$kind] = $number;

                $elevator = (new static)->forceFill([
                    'building_id' => $building->id,
                    'company_id' => $building->company_id,
                    'label' => self::KINDS[$kind].' '.$number,
                    'kind' => $kind,
                    'is_active' => true,
                    'machine_type' => $machine,
                ]);
                $elevator->save();
                $keep[] = $elevator->id;
            }

            static::withoutGlobalScopes()->where('building_id', $building->id)->whereNotIn('id', $keep ?: [0])
                ->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);

            Building::refreshEquipmentCounts($building->id);
        });
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
