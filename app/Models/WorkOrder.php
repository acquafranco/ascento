<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Concerns\BelongsToCompany;

class WorkOrder extends Model
{

    use BelongsToCompany;

    protected $fillable = [
        'company_id',
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

    protected static function booted(): void
    {
        static::creating(function (WorkOrder $workOrder) {

            if (Auth::check() && empty($workOrder->company_id)) {
                $workOrder->company_id = Auth::user()->company_id;
            }

        });
    }

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

public function company()
{
    return $this->belongsTo(Company::class);
}

public function scopeForCompany($query, $companyId)
{
    return $query->whereHas('building.client', function ($q) use ($companyId) {

        $q->where('company_id', $companyId);

    });
}

}
