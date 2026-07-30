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

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * Técnicos asignados a la orden.
     */
    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'work_order_user'
        )
        ->withTimestamps();
    }

    /**
     * Compatibilidad con código viejo.
     * Devuelve el primer técnico asignado.
     */


    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function getTechniciansNamesAttribute(): string
    {
        return $this->users
            ->pluck('name')
            ->implode(', ');
    }

    public function getIsSharedAttribute(): bool
    {
        return $this->users()->count() > 1;
    }

    public function participants()
    {
        return $this->belongsToMany(
            User::class,
            'work_order_participants'
        )
        ->withPivot('role')
        ->withTimestamps();
    }
}
