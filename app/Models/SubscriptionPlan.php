<?php

namespace App\Models;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Plan comercial: precio, límites (NULL = sin límite) y funcionalidades.
 * Es la ÚNICA fuente de verdad de lo que incluye cada plan.
 */
class SubscriptionPlan extends Model
{
    use HasFactory;

    public const INICIAL = 'inicial';

    public const PROFESIONAL = 'profesional';

    public const EMPRESA = 'empresa';

    /** Plan con el que se usa Ascento durante la prueba gratis. */
    public const TRIAL_PLAN = self::PROFESIONAL;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'mercadopago_plan_id',
        'features',
        'feature_keys',
        'max_buildings',
        'max_clients',
        'max_technicians',
        'max_reports_per_month',
        'is_recommended',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'features' => 'array',
        'feature_keys' => 'array',
        'max_buildings' => 'integer',
        'max_clients' => 'integer',
        'max_technicians' => 'integer',
        'max_reports_per_month' => 'integer',
        'is_recommended' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan', 'slug');
    }

    public function scopeOffered(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('price');
    }

    /** @return Collection<int, self> Los planes que se venden hoy, en orden. */
    public static function offered(): Collection
    {
        return static::query()->offered()->get();
    }

    /** Una consulta por request para todos los planes (se usan en listados). */
    public static function findBySlug(?string $slug): ?self
    {
        return $slug ? once(fn () => static::all()->keyBy('slug'))->get($slug) : null;
    }

    /** Tope de un recurso (null = sin límite). */
    public function limit(PlanLimit $limit): ?int
    {
        return $this->{$limit->column()};
    }

    public function maxBuildings(): ?int
    {
        return $this->limit(PlanLimit::Buildings);
    }

    public function maxClients(): ?int
    {
        return $this->limit(PlanLimit::Clients);
    }

    public function maxTechnicians(): ?int
    {
        return $this->limit(PlanLimit::Technicians);
    }

    public function maxReportsPerMonth(): ?int
    {
        return $this->limit(PlanLimit::ReportsPerMonth);
    }

    public function allows(PlanFeature $feature): bool
    {
        return in_array($feature->value, (array) $this->feature_keys, true);
    }

    /** El plan siguiente (para "actualizá tu plan"). */
    public function next(): ?self
    {
        return static::query()->offered()->get()
            ->first(fn (self $plan) => $plan->sort_order > $this->sort_order);
    }

    /** El primer plan ofrecido que incluye la funcionalidad. */
    public static function cheapestWith(PlanFeature $feature): ?self
    {
        return static::offered()->first(fn (self $plan) => $plan->allows($feature));
    }

    /** El primer plan ofrecido con lugar para $needed unidades del recurso. */
    public static function cheapestFor(PlanLimit $limit, int $needed): ?self
    {
        return static::offered()->first(fn (self $plan) => $plan->limit($limit) === null || $plan->limit($limit) >= $needed);
    }

    public function shortName(): string
    {
        return trim(str_ireplace('Ascento', '', $this->name)) ?: $this->name;
    }

    public function formattedPrice(): string
    {
        return '$'.number_format((float) $this->price, 0, ',', '.');
    }

    /**
     * Comparativa de planes para la página pública: sale de los límites y las
     * funciones que el sistema realmente valida (feature_keys), no de un
     * texto aparte. true/false = incluido o no; string = límite.
     *
     * @param  iterable<SubscriptionPlan>  $plans
     * @return list<array{0: string, 1: array<string, bool|string>}>
     */
    public static function comparisonRows(iterable $plans): array
    {
        $plans = collect($plans);
        $limit = fn (PlanLimit $l) => $plans->mapWithKeys(fn (self $p) => [$p->slug => $p->limit($l) === null ? 'Sin límite' : 'Hasta '.$p->limit($l)])->all();
        $feature = fn (PlanFeature $f) => $plans->mapWithKeys(fn (self $p) => [$p->slug => $p->allows($f)])->all();
        $all = $plans->mapWithKeys(fn (self $p) => [$p->slug => true])->all();

        return [
            ['Edificios', $limit(PlanLimit::Buildings)],
            ['Clientes', $limit(PlanLimit::Clients)],
            ['Usuarios técnicos u operativos', $limit(PlanLimit::Technicians)],
            ['Informes por mes', $limit(PlanLimit::ReportsPerMonth)],
            ['Mantenimientos, inspecciones y agenda mensual', $feature(PlanFeature::Agenda)],
            ['Órdenes de trabajo y remitos firmados en el celular', $feature(PlanFeature::WorkOrders)],
            ['Informes con fotos', $feature(PlanFeature::Reports)],
            ['Mapa de edificios', $feature(PlanFeature::Map)],
            ['Legajo e historial de cada ascensor', $feature(PlanFeature::ElevatorFile)],
            ['Centro de atención', $feature(PlanFeature::AttentionCenter)],
            ['Indicadores básicos', $feature(PlanFeature::Indicators)],
            ['Stock, servicios y cobranzas', $all],
            ['Avisos en la app en tiempo real', $all],
            ['Notificaciones push en el celular', $all],
            ['Exportación de los datos de tu empresa', $all],
            ['Presupuestos con PDF y envío por correo', $feature(PlanFeature::Quotes)],
            ['Remitos digitales para el cliente (PDF y enlace)', $feature(PlanFeature::DigitalDeliveryNotes)],
            ['Portal para clientes', $feature(PlanFeature::ClientPortal)],
            ['Video en los informes', $feature(PlanFeature::ReportVideos)],
            ['Análisis de fallas e historial avanzado', $feature(PlanFeature::FailureAnalysis)],
            ['Centro de atención avanzado e indicadores de empresa', $feature(PlanFeature::CompanyIndicators)],
            ['Indicadores avanzados y alertas de tendencias', $feature(PlanFeature::AdvancedIndicators)],
        ];
    }

    /** Lo que incluye, en palabras, para tarjetas de planes. */
    public function highlights(): array
    {
        $lines = array_map(fn (PlanLimit $limit) => $limit->planLabel($this->limit($limit)), PlanLimit::cases());

        foreach ([PlanFeature::Quotes, PlanFeature::DigitalDeliveryNotes, PlanFeature::ClientPortal, PlanFeature::ReportVideos, PlanFeature::CompanyIndicators, PlanFeature::FailureAnalysis, PlanFeature::AdvancedIndicators, PlanFeature::AdvancedAlerts] as $feature) {
            if ($this->allows($feature)) {
                $lines[] = $feature->label();
            }
        }

        return $lines;
    }
}
