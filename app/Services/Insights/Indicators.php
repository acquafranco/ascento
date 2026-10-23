<?php

namespace App\Services\Insights;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Access\AreaGate;
use App\Support\Plans\PlanGuard;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Indicadores de la empresa. Cada uno responde una pregunta concreta. Solo
 * se calculan las secciones que el plan incluye. Las consultas pasan por el
 * scope de empresa.
 *
 * Permisos (Etapa 6, PR 5): cada número declara las áreas de las que sale.
 * Para un administrativo, un número se calcula SOLO si tiene «ver» en todas
 * esas áreas; si no, ni se consulta ni se devuelve. El dueño y el SuperAdmin
 * ven todo, como antes.
 */
class Indicators
{
    /** Primer mes elegible en el selector de período. */
    public const FIRST_PERIOD = '2024-01';

    /** Quién mira (ver AreaGate). Por defecto, el usuario autenticado. */
    private ?AreaGate $gate = null;

    public function __construct(
        private MaintenanceAgenda $agenda,
        private FailureAnalysis $failures,
    ) {}

    /**
     * @param  User|null  $user  quién mira (por defecto, el usuario autenticado)
     * @param  string|null  $period  mes «AAAA-MM» de los indicadores del período (por defecto, el actual)
     */
    public function sections(Company $company, ?User $user = null, ?string $period = null): array
    {
        $guard = PlanGuard::for($company);
        $this->for($user ?? auth()->user());

        return [
            'basic' => $this->basic(),
            'period' => $guard->allows(PlanFeature::CompanyIndicators) ? $this->period($company, self::month($period), $guard->allows(PlanFeature::Quotes)) : null,
            'company' => $guard->allows(PlanFeature::CompanyIndicators) ? $this->company($company, $guard->allows(PlanFeature::Quotes)) : null,
            'advanced' => $guard->allows(PlanFeature::AdvancedIndicators) ? $this->advanced() : null,
        ];
    }

    /** Calcula para este usuario (sus áreas). */
    public function for(?User $user): static
    {
        $this->gate = AreaGate::for($user);

        return $this;
    }

    /** ¿Puede ver un número que sale de estas áreas? */
    public function allows(Area ...$areas): bool
    {
        return ($this->gate ??= AreaGate::for(auth()->user()))->allows(...$areas);
    }

    /**
     * Mes calendario elegido («AAAA-MM»), en la zona horaria de la
     * aplicación. Un valor inválido, futuro o anterior a FIRST_PERIOD vuelve
     * al mes actual.
     */
    public static function month(?string $period): Carbon
    {
        $current = now()->startOfMonth();

        if (! is_string($period) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return $current;
        }

        $month = Carbon::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00');

        return $month->gt($current) || $month->lt(Carbon::createFromFormat('Y-m-d H:i:s', self::FIRST_PERIOD.'-01 00:00:00')) ? $current : $month;
    }

    /** Meses del selector, del actual hacia atrás (máximo 24). */
    public static function periodOptions(): array
    {
        $first = Carbon::createFromFormat('Y-m-d H:i:s', self::FIRST_PERIOD.'-01 00:00:00');
        $options = [];

        for ($m = now()->startOfMonth(); $m->gte($first) && count($options) < 24; $m = $m->copy()->subMonthNoOverflow()) {
            $options[$m->format('Y-m')] = ucfirst($m->locale('es')->translatedFormat('F Y'));
        }

        return $options;
    }

    private function pct(int|float $part, int|float $total): ?int
    {
        return $total > 0 ? (int) round($part * 100 / $total) : null;
    }

    private function change(int|float $now, int|float $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) * 100 / $before) : null;
    }

    /** Días promedio entre que se crea una orden y se termina. */
    private function avgCloseDays(CarbonInterface $from, CarbonInterface $to, ?int $technicianId = null): ?float
    {
        $orders = WorkOrder::where('status', 'completed')->whereNotNull('finished_at')
            ->whereBetween('finished_at', [$from, $to])
            ->when($technicianId, fn ($q) => $q->whereHas('participants', fn ($p) => $p->where('users.id', $technicianId)))
            ->get(['created_at', 'finished_at']);

        return $orders->isEmpty() ? null : round($orders->avg(fn ($o) => $o->created_at->diffInHours($o->finished_at) / 24), 1);
    }

    private function claims(CarbonInterface $from, CarbonInterface $to): int
    {
        // Fallas reportadas + reclamos de la oficina + cada problema atendido en una intervención de tipo reclamo.
        return Report::incidents()->whereBetween('created_at', [$from, $to])->count()
            + WorkOrder::claimSignals()->whereBetween('created_at', [$from, $to])->count()
            + Report::claimJobs()->whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * Actividad de cada técnico en los últimos 90 días: visitas, órdenes
     * completadas y días promedio de cierre. Tres consultas en total (antes,
     * tres por técnico), con las mismas reglas.
     *
     * @return Collection<int, array{name: string, visits: int, orders: int, close_days: ?float}>
     */
    private function technicianActivity(Company $company, CarbonInterface $from): Collection
    {
        $now = now();
        // User no tiene scope global de empresa: el filtro va explícito.
        $technicians = User::where('company_id', $company->id)->where('role', 'technician')->orderBy('name')->get(['id', 'name']);
        $ids = $technicians->pluck('id');

        $visits = BuildingVisit::whereIn('user_id', $ids)->where('visit_type', 'fixed')->where('visited_at', '>=', $from)
            ->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        $orders = WorkOrder::where('status', 'completed')->where('finished_at', '>=', $from)
            ->whereHas('participants', fn ($p) => $p->whereIn('users.id', $ids))
            ->with(['participants' => fn ($p) => $p->select('users.id')])
            ->get(['work_orders.id', 'work_orders.created_at', 'work_orders.finished_at']);

        return $technicians->map(function (User $t) use ($visits, $orders, $from, $now) {
            $own = $orders->filter(fn (WorkOrder $o) => $o->participants->contains('id', $t->id));
            $closed = $own->filter(fn (WorkOrder $o) => $o->finished_at !== null && $o->finished_at->between($from, $now));

            return [
                'name' => $t->name,
                'visits' => (int) ($visits[$t->id] ?? 0),
                'orders' => $own->count(),
                'close_days' => $closed->isEmpty() ? null : round($closed->avg(fn ($o) => $o->created_at->diffInHours($o->finished_at) / 24), 1),
            ];
        })->sortByDesc(fn ($t) => $t['visits'] + $t['orders'])->values();
    }

    /* -------------------------------------------------------------- BÁSICO */

    public function basic(): array
    {
        $now = now();
        $items = [];

        if ($this->allows(Area::Visits)) {
            $summary = $this->agenda->summary($now->month, $now->year, 'maintenance');
            // VISITAS previstas y realizadas, con la misma cuenta que la Agenda y
            // la app del técnico (MaintenanceAgenda::progress): un edificio
            // quincenal suma 2, uno trimestral solo en el mes que le toca.
            $visits = MaintenanceAgenda::progress($this->agenda->rows($now->month, $now->year, 'maintenance'))['maintenance'];
            $items[] = [
                'question' => '¿Estamos cumpliendo los mantenimientos de este mes?',
                'value' => $visits['percent'] !== null ? $visits['percent'].'%' : '—',
                'context' => "{$visits['done']} de {$visits['expected']} visitas realizadas".($visits['not_done'] ? " · {$visits['not_done']} no realizadas" : '').($summary['unassigned'] ? " · {$summary['unassigned']} sin técnico" : ''),
            ];
        }

        if ($this->allows(Area::WorkOrders)) {
            $open = WorkOrder::whereIn('status', ['pending', 'in_progress'])->get(['created_at']);
            $items[] = [
                'question' => '¿Cuánto trabajo tenemos abierto?',
                'value' => (string) $open->count(),
                'context' => $open->isEmpty() ? 'No hay órdenes abiertas' : 'Antigüedad promedio: '.round($open->avg(fn ($o) => $o->created_at->diffInDays($now)), 1).' días',
            ];
        }

        // Reportes (Inspecciones y reportes) + reclamos (Órdenes de trabajo).
        if ($this->allows(Area::Inspections, Area::WorkOrders)) {
            $thisMonth = $this->claims($now->copy()->startOfMonth(), $now);
            $lastMonthSamePoint = $this->claims($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow());
            $items[] = [
                'question' => '¿Entran más reclamos que antes?',
                'value' => (string) $thisMonth,
                'context' => 'Reportes y reclamos este mes · '.$lastMonthSamePoint.' a esta altura del mes pasado',
            ];
        }

        return $items;
    }

    /* ------------------------------------- PROFESIONAL (período, Etapa 6) */

    /**
     * Inspecciones realizadas, edificios atendidos y presupuestos pendientes
     * (definiciones en docs/etapa-6-diseno.md, sección 8). Un remito es
     * siempre un documento firmado; «realizado» es performed = true.
     *
     * @return array{label: string, items: list<array>}
     */
    public function period(Company $company, Carbon $month, bool $withQuotes): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $label = ucfirst($start->locale('es')->translatedFormat('F Y'));
        $items = [];

        // Remitos (Documentos y remitos) firmados en el mes. Empresa explícita
        // (además del scope global), para que valga igual fuera de un request.
        $notes = fn () => DeliveryNote::withoutGlobalScopes()->where('company_id', $company->id)->whereBetween('created_at', [$start, $end]);

        if ($this->allows(Area::Documents)) {
            // Inspección: visita periódica de inspección, u orden de tipo
            // inspección (también si la orden se borró después: el trabajo se hizo).
            $inspection = fn ($q) => $q->where('assignment_type', 'inspection')
                ->orWhere(fn ($o) => $o->where('assignment_type', 'work_order')
                    ->whereIn('work_order_id', WorkOrder::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'inspection')->select('id')));

            $done = $notes()->where($inspection)->where('performed', true)->count();
            $notDone = $notes()->where($inspection)->where('performed', false)->count();
            $items[] = [
                'key' => 'inspections_done',
                'question' => 'Inspecciones realizadas',
                'value' => (string) $done,
                'context' => $notDone ? "{$notDone} firmadas como no realizadas (no se cuentan)" : 'en '.$label,
                'definition' => 'Remitos firmados en el mes de inspecciones periódicas u órdenes de tipo inspección, marcados como realizados. No cuenta visitas previstas, visitas en curso sin remito ni remitos «no realizado».',
            ];

            $served = $notes()->where('performed', true)->distinct()->count('building_id');
            $items[] = [
                'key' => 'buildings_served',
                'question' => 'Edificios atendidos',
                'value' => (string) $served,
                'context' => 'en '.$label,
                'definition' => 'Edificios distintos con al menos un remito firmado como realizado en el mes (mantenimiento, inspección u orden). No cuenta trabajos solo previstos o no realizados. Incluye edificios desactivados después.',
            ];
        }

        if ($withQuotes && $this->allows(Area::Quotes)) {
            $pending = Quote::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->awaitingResponse();
            $items[] = [
                'key' => 'quotes_pending',
                'question' => 'Presupuestos pendientes',
                'value' => (string) (clone $pending)->count(),
                'context' => '$'.number_format((float) (clone $pending)->sum('amount'), 0, ',', '.').' esperando respuesta · hoy',
                'definition' => 'Presupuestos enviados, no borrados y vigentes (sin fecha de validez, o con validez hasta hoy o después). Es una foto de hoy: no depende del mes elegido. No cuenta borradores, aprobados, rechazados, anulados ni vencidos.',
            ];
        }

        return ['label' => $label, 'items' => $items];
    }

    /* ------------------------------------------------- PROFESIONAL (empresa) */

    public function company(Company $company, bool $withQuotes): array
    {
        // Evolución de los últimos 6 meses (mantenimientos como texto: «58%» o «—»).
        $months = $this->evolution()?->map(fn (array $m) => array_key_exists('maintenance', $m)
            ? [...$m, 'maintenance' => $m['maintenance'] !== null ? $m['maintenance'].'%' : '—']
            : $m);

        // Técnicos: últimos 90 días (nombres: «Técnicos»; visitas: «Visitas»; órdenes: «Órdenes»).
        $from = now()->subDays(90);
        $techColumns = ['visits' => $this->allows(Area::Visits), 'orders' => $this->allows(Area::WorkOrders), 'close_days' => $this->allows(Area::WorkOrders)];
        $technicians = $this->allows(Area::Technicians) && in_array(true, $techColumns, true)
            ? $this->technicianActivity($company, $from)->map(fn (array $t) => array_intersect_key($t, ['name' => true] + array_filter($techColumns)))
            : null;

        $quotes = null;
        if ($withQuotes && $this->allows(Area::Quotes)) {
            $issued = Quote::where('created_at', '>=', $from)->whereIn('status', [Quote::SENT, Quote::APPROVED, Quote::REJECTED])->get(['status', 'amount']);
            $decided = $issued->whereIn('status', [Quote::APPROVED, Quote::REJECTED]);
            $quotes = [
                'question' => '¿Cuántos presupuestos nos aprueban?',
                'value' => $decided->isNotEmpty() ? $this->pct($decided->where('status', Quote::APPROVED)->count(), $decided->count()).'%' : '—',
                'context' => $issued->count().' enviados en 90 días · aprobados por $'.number_format((float) $issued->where('status', Quote::APPROVED)->sum('amount'), 0, ',', '.'),
            ];
        }

        return [
            'evolution' => $months,
            'technicians' => $technicians,
            'technician_columns' => array_keys(array_filter($techColumns)),
            // Reportes y reclamos por ascensor.
            'top_failures' => $this->allows(Area::Inspections, Area::WorkOrders) ? $this->failures->recurrent(90, 1)->take(5) : null,
            'quotes' => $quotes,
        ];
    }

    /**
     * Evolución mensual de los últimos meses: cada columna solo con sus
     * áreas (sin ninguna, null). La usan los indicadores y el gráfico del
     * inicio. Mantenimientos: porcentaje cumplido (null si no había previstos).
     *
     * @return Collection<int, array{month: string, maintenance?: ?int, claims?: int, completed?: int, close_days?: ?float}>|null
     */
    public function evolution(int $count = 6): ?Collection
    {
        $columns = array_filter([
            'maintenance' => $this->allows(Area::Visits),
            'claims' => $this->allows(Area::Inspections, Area::WorkOrders),
            'completed' => $this->allows(Area::WorkOrders),
            'close_days' => $this->allows(Area::WorkOrders),
        ]);

        if ($columns === []) {
            return null;
        }

        return collect(range($count - 1, 0))->map(function (int $back) use ($columns) {
            $start = now()->subMonthsNoOverflow($back)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $row = ['month' => ucfirst($start->locale('es')->translatedFormat('M Y'))];

            if (isset($columns['maintenance'])) {
                // Visitas realizadas sobre previstas (misma cuenta que la Agenda).
                $row['maintenance'] = MaintenanceAgenda::progress($this->agenda->rows($start->month, $start->year, 'maintenance'))['maintenance']['percent'];
            }
            if (isset($columns['claims'])) {
                $row['claims'] = $this->claims($start, $end);
            }
            if (isset($columns['completed'])) {
                $row['completed'] = WorkOrder::where('status', 'completed')->whereBetween('finished_at', [$start, $end])->count();
                $row['close_days'] = $this->avgCloseDays($start, $end);
            }

            return $row;
        });
    }

    /* ---------------------------------------------------- EMPRESA (avanzado) */

    public function advanced(): array
    {
        $now = now();
        $lastYear = [$now->copy()->subYear(), $now];
        $yearBefore = [$now->copy()->subYears(2), $now->copy()->subYear()];

        $metric = fn (string $label, callable $count) => [
            'label' => $label,
            'now' => $count(...$lastYear),
            'before' => $count(...$yearBefore),
        ];

        $comparison = collect([
            $this->allows(Area::Inspections, Area::WorkOrders) ? $metric('Reportes y reclamos', fn ($a, $b) => $this->claims($a, $b)) : null,
            $this->allows(Area::WorkOrders) ? $metric('Órdenes completadas', fn ($a, $b) => WorkOrder::where('status', 'completed')->whereBetween('finished_at', [$a, $b])->count()) : null,
            $this->allows(Area::Visits) ? $metric('Mantenimientos hechos', fn ($a, $b) => BuildingVisit::where('visit_type', 'fixed')->where('assignment_type', 'maintenance')->whereBetween('visited_at', [$a, $b])->count()) : null,
            $this->allows(Area::Quotes) ? $metric('Presupuestos aprobados ($)', fn ($a, $b) => (int) Quote::where('status', Quote::APPROVED)->whereBetween('created_at', [$a, $b])->sum('amount')) : null,
        ])->filter()->map(fn ($m) => $m + ['change' => $this->change($m['now'], $m['before'])])->values();

        // Cartera: ingresos mensualizados de los contratos activos (contratos = «Cobranzas»).
        $portfolio = [];
        $byClient = collect();
        $total = 0.0;
        if ($this->allows(Area::Receivables)) {
            // Solo lo recurrente: un servicio de pago único no es ingreso mensual de la cartera.
            $services = MaintenanceService::active()->where('frequency', '!=', 'once')->with('client:id,name')->get();
            $monthly = $services->map(fn ($s) => ['client' => $s->client?->name, 'monthly' => (float) $s->amount / max(1, $s->monthsPerPeriod())]);
            $total = $monthly->sum('monthly');
            $byClient = $monthly->groupBy('client')->map(fn ($g) => $g->sum('monthly'))->sortDesc();
            $portfolio[] = ['question' => '¿Cuánto factura la cartera por mes?', 'value' => '$'.number_format($total, 0, ',', '.'), 'context' => $services->count().' contratos activos (mensualizados)'];
            $portfolio[] = ['question' => '¿Dependemos de pocos clientes?', 'value' => $total > 0 ? $this->pct($byClient->take(5)->sum(), $total).'%' : '—', 'context' => 'de los ingresos vienen de los 5 clientes principales'];
        }

        if ($this->allows(Area::Buildings, Area::Receivables, Area::Clients)) {
            $buildings = Building::count();
            $withContract = Building::whereHas('maintenanceServices', fn ($q) => $q->where('status', MaintenanceService::ACTIVE))->count();
            $portfolio[] = ['question' => '¿Cuántos edificios no tienen contrato?', 'value' => (string) max(0, $buildings - $withContract), 'context' => "de {$buildings} edificios · ".Client::count().' clientes'];
        }

        if ($this->allows(Area::Buildings, Area::Inspections, Area::WorkOrders)) {
            $elevators = Elevator::active()->count();
            $recurrent = $this->failures->recurrent()->count();
            $portfolio[] = ['question' => '¿Qué parte de los ascensores falla seguido?', 'value' => $elevators > 0 ? $this->pct($recurrent, $elevators).'%' : '—', 'context' => "{$recurrent} de {$elevators} con ".FailureAnalysis::THRESHOLD.'+ avisos de falla en '.FailureAnalysis::DAYS.' días'];
        }

        // Tendencia de fallas por trimestre (último año).
        $quarters = ! $this->allows(Area::Inspections, Area::WorkOrders) ? null : collect(range(3, 0))->map(function (int $back) use ($now) {
            $end = $now->copy()->subMonthsNoOverflow($back * 3);
            $start = $end->copy()->subMonthsNoOverflow(3);

            return ['label' => $start->format('m/y').'–'.$end->format('m/y'), 'claims' => $this->claims($start, $end)];
        });

        return [
            'comparison' => $comparison,
            'portfolio' => $portfolio,
            'top_clients' => $this->allows(Area::Receivables) ? $byClient->take(5)->map(fn ($amount, $client) => ['client' => $client, 'monthly' => $amount, 'share' => $total > 0 ? $this->pct($amount, $total) : null])->values() : null,
            'quarters' => $quarters,
        ];
    }
}
