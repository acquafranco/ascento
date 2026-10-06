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
