<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryNote extends Model
{
    protected $fillable = [

    'number',

    'building_id',
    'building_visit_id',
    'work_order_id',
    'assignment_type',
    'user_id',

    'description',
     'elevator_quantity',

    'freight_elevator_quantity',
    'performed',

    'month',
    'year',

    'signature_name',
    'signature',
    'client_signature',
    'client_signature_name',
];

protected $casts = [

    'performed' => 'boolean',

    'month' => 'integer',

    'year' => 'integer',

    'elevator_quantity' => 'integer',

    'freight_elevator_quantity' => 'integer',

];


protected static function booted(): void
{
    static::creating(function ($deliveryNote) {

        $lastNumber = static::max('number');

        $nextNumber = $lastNumber
            ? ((int) $lastNumber) + 1
            : 1;

        $deliveryNote->number = str_pad(
            $nextNumber,
            8,
            '0',
            STR_PAD_LEFT
        );
    });
}

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function buildingVisit()
    {
        return $this->belongsTo(
            BuildingVisit::class,
            'building_visit_id'
        );
    }

}
