<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuildingVisit extends Model
{
    protected $fillable = [

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
    ];

    protected $casts = [

        'visited_at' =>
            'datetime',

        'started_at' =>
            'datetime',

        'finished_at' =>
            'datetime',
    ];

    public function building()
    {
        return $this->belongsTo(
            Building::class
        );
    }

    public function user()
    {
        return $this->belongsTo(
            User::class
        );
    }

    public function workOrder()
    {
        return $this->belongsTo(
            WorkOrder::class
        );
    }
    // App\Models\BuildingVisit.php

public function deliveryNote()
{
    return $this->hasOne(
        DeliveryNote::class
    );
}

}
