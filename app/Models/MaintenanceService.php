<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Billing\ServiceBillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Servicio / contrato de mantenimiento de un cliente (y edificio). Las
 * inspecciones y mantenimientos periódicos son parte del servicio: se cobra
 * el servicio por período, no cada visita.
 */
class MaintenanceService extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const FINISHED = 'finished';

    public const STATUSES = [
        self::ACTIVE => 'Activo',
        self::PAUSED => 'Pausado',
        self::FINISHED => 'Finalizado',
    ];

    /** Frecuencia => meses entre cobros. */
    public const FREQUENCIES = [
        'monthly' => ['Mensual', 1],
        'bimonthly' => ['Bimestral', 2],
        'quarterly' => ['Trimestral', 3],
        'semiannual' => ['Semestral', 6],
        'annual' => ['Anual', 12],
    ];

    protected $fillable = [
        'company_id',
        'client_id',
        'building_id',
        'description',
        'amount',
        'frequency',
        'start_date',
        'end_date',
        'status',
        'payment_due_day',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'billing_from' => 'date',
        'payment_due_day' => 'integer',
    ];

    protected static function booted(): void
    {
        // Se cobra desde el mes de inicio, pero nunca antes del mes en que se
        // cargó: un inicio viejo no genera años de deuda retroactiva.
        static::creating(function (MaintenanceService $service) {
            $start = $service->start_date?->copy()->startOfMonth() ?? now()->startOfMonth();
            $service->billing_from = $start->max(now()->startOfMonth());
        });

        // Al crearlo o reactivarlo se generan los cobros que ya correspondan.
        static::saved(function (MaintenanceService $service) {
            if ($service->status === self::ACTIVE && ($service->wasRecentlyCreated || $service->wasChanged(['status', 'amount', 'frequency', 'start_date', 'end_date']))) {
                app(ServiceBillingService::class)->generate($service);
            }
        });

        static::saving(function (MaintenanceService $service) {
            $service->payment_due_day = max(1, min(28, (int) ($service->payment_due_day ?: 10)));
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function monthsPerPeriod(): int
    {
        return self::FREQUENCIES[$this->frequency][1] ?? 1;
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency][0] ?? $this->frequency;
    }

    /** "$120.000 / mes". */
    public function amountLabel(): string
    {
        $per = match ($this->frequency) {
            'monthly' => 'mes',
            'bimonthly' => '2 meses',
            'quarterly' => 'trimestre',
            'semiannual' => 'semestre',
            'annual' => 'año',
            default => $this->frequency,
        };

        return '$'.number_format((float) $this->amount, 0, ',', '.').' / '.$per;
    }
}
