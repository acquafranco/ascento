<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeliveryNote extends Model
{

        use BelongsToCompany;

        protected $fillable = [

        'number',
        'company_id',
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

        if (!$deliveryNote->public_token) {

            $deliveryNote->public_token = Str::uuid();

        }

            $lastNumber = static::where(
                'company_id',
                $deliveryNote->company_id
            )->max('number');

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

    public function getRouteKeyName()
    {
        return 'number';
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

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

}
