<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Billing\ServiceBillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Servicio / contrato de mantenimiento de un cliente (y edificio). Las
 * inspecciones y mantenimientos periódicos son parte del servicio: se cobra
 * el servicio por período, no cada visita.
 *
 * Contratación de un servicio del catálogo (opcional): guarda una copia de
 * cómo se calculó el importe (modalidad, precio unitario, cantidad y detalle),
 * así un cambio en el catálogo nunca altera lo ya acordado. Sin catálogo
 * (contratos anteriores) = precio acordado, como siempre.
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
        // Servicio puntual (extraordinario, una prueba): un solo cobro, en el mes de inicio.
        'once' => ['Pago único', 1],
    ];

    protected $fillable = [
        'company_id',
        'client_id',
        'building_id',
        'units',
        'description',
        'amount',
        'frequency',
        'start_date',
        'end_date',
        'status',
        'payment_due_day',
        'notes',
        'catalog_item_id',
        'category',
        'pricing_mode',
        'unit_price',
        'quantity',
        'pricing_detail',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'billing_from' => 'date',
        'payment_due_day' => 'integer',
        'units' => 'array',
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'pricing_detail' => 'array',
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

            // Al reactivarlo (desde pausado o finalizado) no se cobran los meses
            // en que no estuvo activo: se cobra desde el mes en que vuelve.
            if ($service->exists && $service->isDirty('status') && $service->status === self::ACTIVE && $service->getOriginal('status') !== self::ACTIVE) {
                $from = ($service->billing_from ?? $service->start_date ?? now())->copy()->startOfMonth();
                $service->billing_from = $from->max(now()->startOfMonth());
            }

            // Equipos: solo los del edificio del contrato (nunca texto libre del request).
            $building = $service->building_id ? Building::withoutGlobalScopes()->withTrashed()->find($service->building_id) : null;
            $units = array_values(array_intersect((array) ($service->units ?? []), $building?->unitLabels() ?? []));
            $service->units = $units ?: null;
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

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'catalog_item_id')->withTrashed();
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    /** Primer cobro del servicio (para el pago único, el único que genera). */
    public function oneTimeReceivable(): HasOne
    {
        return $this->hasOne(Receivable::class)->oldestOfMany();
    }

    /** Cobro de un servicio de pago único, solo informativo; null si no es de pago único o aún no se generó. */
    public function oneTimeCharge(): ?Receivable
    {
        return $this->frequency === 'once' ? $this->oneTimeReceivable : null;
    }

    /** Visitas realizadas bajo este contrato (mantenimientos e inspecciones). */
    public function visits(): HasMany
    {
        return $this->hasMany(BuildingVisit::class)->latest('visited_at');
    }

    public function maintenanceVisits(): HasMany
    {
        return $this->visits()->where('assignment_type', 'maintenance');
    }

    public function inspectionVisits(): HasMany
    {
        return $this->visits()->where('assignment_type', 'inspection');
    }

    /**
     * Servicio que cubre una visita: activo, de la misma empresa, del
     * edificio (o de todo el cliente si no tiene edificio) y vigente en la
     * fecha. Primero el del edificio; entre varios, el de inicio más reciente.
     */
    public static function covering(int $companyId, int $buildingId, \DateTimeInterface $date): ?self
    {
        $building = Building::withoutGlobalScopes()->withTrashed()->find($buildingId);

        if (! $building || (int) $building->company_id !== $companyId) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('status', self::ACTIVE)
            // Solo servicios de mantenimiento o inspección (una guardia o una limpieza no cubren visitas).
            ->where(fn ($q) => $q->whereNull('category')->orWhereIn('category', self::visitCategories()))
            ->where(fn ($q) => $q->where('building_id', $buildingId)
                ->orWhere(fn ($q) => $q->whereNull('building_id')->where('client_id', $building->client_id)))
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->orderByRaw('building_id is null')
            ->orderByDesc('start_date')
            ->first();
    }

    /** ¿Este contrato puede tener visitas de ese edificio? (misma empresa y edificio/cliente). */
    public function coversBuilding(Building $building): bool
    {
        return (int) $building->company_id === (int) $this->company_id
            && ($this->building_id
                ? (int) $this->building_id === (int) $building->id
                : (int) $this->client_id === (int) $building->client_id);
    }

    /**
     * Situación del período para un tipo de visita, con la frecuencia de
     * visitas del edificio (VisitSchedule; mensual si no tiene una propia o
     * si el contrato cubre a todo el cliente). La frecuencia de cobro del
     * contrato no interviene. Si la visita del período ya se hizo, la próxima
     * es el período siguiente; si no, está pendiente en este período.
     *
     * @return array{last: ?BuildingVisit, done_this_month: bool, next: ?\Carbon\CarbonInterface}
     */
    public function visitStatus(string $type): array
    {
        $last = $this->visits()->where('assignment_type', $type)->first();

        if ($this->building_id) {
            $eval = \App\Services\Visits\VisitSchedule::load([$this->building_id], now()->month, now()->year, (int) $this->company_id)
                ->evaluate((int) $this->building_id, $type, now()->month, now()->year);
            $period = $eval['period'];
            // Hecho = todas las visitas del período con remito y ninguna "no realizado".
            $doneThisMonth = $eval['covered'] || ($eval['pending'] === 0 && $eval['not_done'] === 0);
            $nextIfDone = \Carbon\Carbon::create($period->end->year, $period->end->month, 1)->addMonthNoOverflow();
        } else {
            // Hecho = remito firmado y NO marcado como "no realizado".
            $doneThisMonth = $this->visits()->where('assignment_type', $type)
                ->where('month', now()->month)->where('year', now()->year)
                ->whereDoesntHave('deliveryNote', fn ($q) => $q->where('performed', false))
                ->exists();
            $nextIfDone = now()->addMonthsNoOverflow(\App\Services\Insights\MaintenanceAgenda::VISIT_EVERY_MONTHS)->startOfMonth();
        }

        $next = null;

        if ($this->status === self::ACTIVE) {
            $next = $doneThisMonth ? $nextIfDone : now()->startOfMonth();

            if ($this->end_date && $next->gt($this->end_date)) {
                $next = null;
            }
        }

        return ['last' => $last, 'done_this_month' => $doneThisMonth, 'next' => $next];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** Contrataciones que implican visitas periódicas (las anteriores al catálogo, sin categoría, también). */
    public function scopeProvidesVisits(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('category')->orWhereIn('category', self::visitCategories()));
    }

    /** @return list<string> */
    public static function visitCategories(): array
    {
        return array_keys(array_filter(ServiceCatalogItem::CATEGORIES, fn ($c) => $c[1]));
    }

    /** «Todo el cliente», «Torre Libertador» o «Torre Libertador: Ascensor 1, Ascensor 2». */
    public function scopeLabel(): string
    {
        if (! $this->building_id) {
            return 'Todo el cliente';
        }

        $name = (string) $this->building?->name;

        return $this->units ? $name.': '.implode(', ', $this->units) : $name;
    }

    /** Cómo se obtuvo el importe («3 equipos × $50.000 = $150.000»), o «Precio acordado». */
    public function pricingExplanation(): string
    {
        return (string) ($this->pricing_detail['explanation'] ?? ServiceCatalogItem::pricingModeLabel($this->pricing_mode));
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

        if ($this->frequency === 'once') {
            return '$'.number_format((float) $this->amount, 0, ',', '.').' (pago único)';
        }

        return '$'.number_format((float) $this->amount, 0, ',', '.').' / '.$per;
    }
}
