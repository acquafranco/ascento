<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Notifications\AssignmentNotifier;
use App\Services\Stock\StockService;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WorkOrder extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'building_id',
        'on_call_shift_id',
        'type',
        'status',
        'priority',
        'unit',
        'component',
        'started_at',
        'finished_at',
        'notes',
        'delivery_note',
    ];

    /** Abierta por el técnico desde Reportes (urgencia o trabajo no programado). null = la creó la oficina. */
    public const ORIGIN_TECHNICIAN = 'technician';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Solo se pueden eliminar (soft delete) órdenes que todavía no
        // tienen historial: pendientes o fallidas y sin remito.
        static::deleting(fn (WorkOrder $workOrder) => $workOrder->canBeDeleted());

        // Cerrada desde el panel sin hora de finalización (el remito ya la
        // pone): se registra ahora, para que cuente en "completados hoy" y en
        // los indicadores.
        static::saving(function (WorkOrder $workOrder) {
            if (in_array($workOrder->status, ['completed', 'failed'], true) && $workOrder->isDirty('status') && ! $workOrder->finished_at) {
                $workOrder->finished_at = now();
            }
        });

        // Al completarse (por remito, por el servicio o desde el panel) se
        // descuentan los materiales usados. Idempotente: ver StockService.
        static::saved(function (WorkOrder $workOrder) {
            if ($workOrder->status === 'completed' && ($workOrder->wasRecentlyCreated || $workOrder->wasChanged('status'))) {
                app(StockService::class)->consumeWorkOrder($workOrder);
            }
        });

        // Eliminar una orden abierta es cancelarla: se avisa a sus técnicos.
        static::deleted(fn (WorkOrder $workOrder) => app(AssignmentNotifier::class)->workOrderCancelled($workOrder));

        // Guardia de origen: solo un turno de la misma empresa, y una vez que la
        // orden tiene remito no se cambia (el remito firmado no se reescribe).
        static::saving(function (WorkOrder $workOrder) {
            if (! $workOrder->isDirty('on_call_shift_id')) {
                return;
            }
            if ($workOrder->exists && $workOrder->deliveryNote()->exists()) {
                $workOrder->on_call_shift_id = $workOrder->getOriginal('on_call_shift_id');

                return;
            }
            if ($workOrder->on_call_shift_id) {
                $companyId = $workOrder->company_id ?? (CompanyContext::currentId());
                $ok = OnCallShift::withoutGlobalScopes()->whereKey($workOrder->on_call_shift_id)->where('company_id', $companyId)->exists();
                if (! $ok) {
                    throw ValidationException::withMessages(['on_call_shift_id' => 'Esa guardia no es de tu empresa.']);
                }
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

    /** Turno de guardia en el que se originó (si lo indicó quien la cargó). */
    public function onCallShift()
    {
        return $this->belongsTo(OnCallShift::class, 'on_call_shift_id');
    }

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

    /** Trabajos de esta visita (intervenciones registradas por el técnico), en orden. */
    public function jobs()
    {
        return $this->hasMany(Report::class)->where('kind', Report::KIND_INTERVENTION)->orderBy('id');
    }

    /**
     * Reclamos que cuentan como aviso de falla en indicadores y análisis: los
     * de la oficina. La orden que abre el técnico no cuenta aparte: cada
     * problema que atendió es un trabajo (Report::scopeInterventionJobs) con
     * su propio equipo, así nada se cuenta dos veces ni se pierde.
     */
    public function scopeClaimSignals(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('type'), 'claim')
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('origin'))->orWhere($q->qualifyColumn('origin'), '!=', self::ORIGIN_TECHNICIAN));
    }

    /** Quién la creó (solo se registra desde las intervenciones del técnico). */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isTechnicianInitiated(): bool
    {
        return $this->origin === self::ORIGIN_TECHNICIAN;
    }

    /**
     * ¿Este técnico puede sumarle trabajos de la visita? Asignado a la orden,
     * en curso y sin remito (el remito firmado no se reescribe).
     */
    public function acceptsJobsFrom(User $user): bool
    {
        return (int) $this->company_id === (int) $user->company_id
            && $this->status === 'in_progress'
            && ! $this->trashed()
            && $this->users()->whereKey($user->id)->exists()
            && ! $this->deliveryNote()->exists();
    }
}
