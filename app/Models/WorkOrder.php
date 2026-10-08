<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Stock\StockService;

class WorkOrder extends Model
{
    use HasFactory, SoftDeletes;
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
        // Solo se pueden eliminar (soft delete) órdenes que todavía no
        // tienen historial: pendientes o fallidas y sin remito.
        static::deleting(fn (WorkOrder $workOrder) => $workOrder->canBeDeleted());

        // Al completarse (por remito, por el servicio o desde el panel) se
        // descuentan los materiales usados. Idempotente: ver StockService.
        static::saved(function (WorkOrder $workOrder) {
            if ($workOrder->status === 'completed' && ($workOrder->wasRecentlyCreated || $workOrder->wasChanged('status'))) {
                app(StockService::class)->consumeWorkOrder($workOrder);
            }
        });

        static::creating(function (WorkOrder $workOrder) {

            if (Auth::check() && empty($workOrder->company_id)) {
                $user = Auth::user();

                $workOrder->company_id = $user->isSuperAdmin()
                    ? session('selected_company_id')
                    : $user->company_id;
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
        return $this->belongsTo(Building::class)->withTrashed();
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
        ->withTrashed()
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

    public function canBeDeleted(): bool
    {
        return in_array($this->status, ['pending', 'failed'], true)
            && ! $this->deliveryNote()->exists();
    }

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
        ->withTrashed()
        ->withPivot('role')
        ->withTimestamps();
    }

    public function materials()
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }
}
