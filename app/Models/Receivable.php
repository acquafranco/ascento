<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Obligación de cobro (cuenta corriente). Importes y estado SOLO los cambia
 * ReceivableService. "Vencida" no se guarda: se calcula con la fecha.
 */
class Receivable extends Model
{
    use BelongsToCompany, HasFactory;

    public const PENDING = 'pending';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** Estado mostrado (incluye "vencida", que se calcula). */
    public const STATUSES = [
        self::PENDING => 'Pendiente',
        'overdue' => 'Vencida',
        self::PARTIAL => 'Parcialmente pagada',
        self::PAID => 'Pagada',
        self::VOID => 'Anulada',
    ];

    public const SOURCES = [
        'service' => 'Servicio',
        'quote' => 'Presupuesto',
        'manual' => 'Manual',
    ];

    protected $fillable = [
        'company_id',
        'client_id',
        'building_id',
        'concept',
        'amount',
        'due_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
        'period_start' => 'date',
        'voided_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(MaintenanceService::class, 'maintenance_service_id')->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class)->latest('paid_at')->latest('id');
    }

    public function balance(): float
    {
        return $this->status === self::VOID ? 0.0 : round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::PARTIAL], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date?->lt(today());
    }

    /** pending | overdue | partial | paid | void */
    public function displayStatus(): string
    {
        return $this->isOverdue() && $this->status === self::PENDING ? 'overdue' : $this->status;
    }

    public function displayStatusLabel(): string
    {
        $label = self::STATUSES[$this->displayStatus()];

        return $this->status === self::PARTIAL && $this->isOverdue() ? $label.' (vencida)' : $label;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::PARTIAL]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', today());
    }
}
