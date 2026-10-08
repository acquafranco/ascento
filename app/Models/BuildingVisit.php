<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

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

        'visited_at',

        'unit',
        'notes',

        'started_at',
        'finished_at',

        'work_type',
        'source',
        'assignment_type',
    ];


    protected $casts = [
        'visited_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];


    protected static function booted(): void
    {
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
