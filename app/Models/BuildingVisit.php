<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Visits\VisitSchedule;

class BuildingVisit extends Model
{
    use HasFactory;
    use BelongsToCompany;

    protected $fillable = [
        'company_id',

        'building_id',
        'user_id',

        'visit_type',
        'work_order_id',

        'status',

        'delivery_note',

        'month',
        'year',
        'slot',

        'visited_at',

        'unit',
        'notes',

        'started_at',
        'finished_at',

        'work_type',
        'source',
        'assignment_type',
        // Identidad del formulario del remito (reenvíos idempotentes).
        'submission_token',
    ];


    protected $casts = [
        'visited_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'slot' => 'integer',
    ];


    protected static function booted(): void
    {
        // Identidad de la visita periódica (edificio + "tipo:período:número",
        // único en la base): dos visitas del mismo lugar nunca se duplican.
        // El período sale de la frecuencia del edificio (VisitSchedule).
        static::creating(function (BuildingVisit $visit) {
            if ($visit->period_key === null && $visit->visit_type === 'fixed'
                && in_array($visit->assignment_type, VisitSchedule::TYPES, true) && $visit->month && $visit->year) {
                $visit->slot = max(1, (int) ($visit->slot ?: 1));
                $visit->period_key = VisitSchedule::load([$visit->building_id], (int) $visit->month, (int) $visit->year)
                    ->period((int) $visit->building_id, $visit->assignment_type, (int) $visit->month, (int) $visit->year)
                    ->key($visit->slot);
            }
        });

        // Mantenimiento / inspección: se vincula solo al contrato que lo cubre.
        static::creating(function (BuildingVisit $visit) {
            if ($visit->maintenance_service_id === null && in_array($visit->assignment_type, ['maintenance', 'inspection'], true)) {
                $visit->maintenance_service_id = MaintenanceService::covering(
                    (int) $visit->company_id,
                    (int) $visit->building_id,
                    $visit->visited_at ?? now(),
                )?->id;
            }
        });

        // Sus fotos se borran con sus archivos (no quedan huérfanos).
        static::deleting(function (BuildingVisit $visit) {
            VisitPhoto::withoutGlobalScopes()->where('building_visit_id', $visit->id)->get()->each->delete();
        });

        // Un contrato solo tiene visitas de su empresa y de su edificio/cliente.
        static::saving(function (BuildingVisit $visit) {
            if ($visit->maintenance_service_id === null || ! $visit->isDirty(['maintenance_service_id', 'building_id', 'company_id'])) {
                return;
            }

            $service = MaintenanceService::withoutGlobalScopes()->withTrashed()->find($visit->maintenance_service_id);
            $building = Building::withoutGlobalScopes()->withTrashed()->find($visit->building_id);

            if (! $service || ! $building
                || (int) $service->company_id !== (int) $visit->company_id
                || ! $service->coversBuilding($building)
                || ! in_array($visit->assignment_type, ['maintenance', 'inspection'], true)) {
                throw new \DomainException('La visita no corresponde a ese servicio.');
            }
        });
    }

    public function maintenanceService()
    {
        return $this->belongsTo(MaintenanceService::class)->withTrashed();
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }


    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }


    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }


    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class);
    }


    public function company()
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * Una visita con remito es historial documentado: no se puede desmarcar.
     */
    public function canBeUnmarked(): bool
    {
        return ! $this->deliveryNote()->exists();
    }

    public function photos()
    {
        return $this->hasMany(VisitPhoto::class)->orderBy('photo_section_id')->orderBy('stage')->orderBy('id');
    }

    public function participants()
    {
        return $this->belongsToMany(
            User::class,
            'building_visit_participants'
        )->withTrashed()
        ->withPivot('role')
        ->withTimestamps();
    }

}
