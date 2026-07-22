<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    protected $fillable = [
        'building_id',
        'user_id',
        'type',
        'status',
        'priority',
        'unit',
        'started_at',
        'finished_at',
        'notes',
        'delivery_note',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function technician()
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function deliveryNote()
{
    return $this->hasOne(
        DeliveryNote::class
    );
}


}
