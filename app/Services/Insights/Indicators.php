<?php

namespace App\Services\Insights;

use App\Enums\PlanFeature;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Plans\PlanGuard;
use Carbon\CarbonInterface;

/**
 * Indicadores para el dueño de la empresa. Cada uno responde una pregunta
 * concreta. Solo se calculan las secciones que el plan incluye.
 * Las consultas pasan por el scope de empresa.
 */
class Indicators
{
    public function __construct(
        private MaintenanceAgenda $agenda,
        private FailureAnalysis $failures,
    ) {}

    public function sections(Company $company): array
    {
        $guard = PlanGuard::for($company);

        return [
            'basic' => $this->basic(),
            'company' => $guard->allows(PlanFeature::CompanyIndicators) ? $this->company($company, $guard->allows(PlanFeature::Quotes)) : null,
            'advanced' => $guard->allows(PlanFeature::AdvancedIndicators) ? $this->advanced() : null,
        ];
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
        return Report::whereBetween('created_at', [$from, $to])->count()
            + WorkOrder::where('type', 'claim')->whereBetween('created_at', [$from, $to])->count();
    }

    /* -------------------------------------------------------------- BÁSICO */

    public function basic(): array
    {
        $now = now();
        $summary = $this->agenda->summary($now->month, $now->year, 'maintenance');
        $due = $summary['done'] + $summary['pending'] + $summary['overdue'] + $summary['unassigned'];

        $open = WorkOrder::whereIn('status', ['pending', 'in_progress'])->get(['created_at']);
        $thisMonth = $this->claims($now->copy()->startOfMonth(), $now);
        $lastMonthSamePoint = $this->claims($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow());

        return [
            [
                'question' => '¿Estamos cumpliendo los mantenimientos de este mes?',
                'value' => $due > 0 ? $this->pct($summary['done'], $due).'%' : '—',
                'context' => "{$summary['done']} de {$due} hechos".($summary['unassigned'] ? " · {$summary['unassigned']} sin técnico" : ''),
            ],
            [
                'question' => '¿Cuánto trabajo tenemos abierto?',
                'value' => (string) $open->count(),
                'context' => $open->isEmpty() ? 'No hay órdenes abiertas' : 'Antigüedad promedio: '.round($open->avg(fn ($o) => $o->created_at->diffInDays($now)), 1).' días',
            ],
            [
                'question' => '¿Entran más reclamos que antes?',
                'value' => (string) $thisMonth,
                'context' => 'Reportes y reclamos este mes · '.$lastMonthSamePoint.' a esta altura del mes pasado',
            ],
        ];
    }

    /* ------------------------------------------------- PROFESIONAL (empresa) */

    public function company(Company $company, bool $withQuotes): array
    {
        // Evolución de los últimos 6 meses.
        $months = collect(range(5, 0))->map(function (int $back) {
            $start = now()->subMonthsNoOverflow($back)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $s = $this->agenda->summary($start->month, $start->year, 'maintenance');
            $due = array_sum($s) - $s['upcoming'];

            return [
                'month' => ucfirst($start->locale('es')->translatedFormat('M Y')),
                'maintenance' => $due > 0 ? $this->pct($s['done'], $due).'%' : '—',
                'claims' => $this->claims($start, $end),
                'completed' => WorkOrder::where('status', 'completed')->whereBetween('finished_at', [$start, $end])->count(),
                'close_days' => $this->avgCloseDays($start, $end),
            ];
        });

        // Técnicos: últimos 90 días.
        $from = now()->subDays(90);
        // User no tiene scope global de empresa: el filtro va explícito.
        $technicians = User::where('company_id', $company->id)->where('role', 'technician')->orderBy('name')->get(['id', 'name'])->map(fn (User $t) => [
            'name' => $t->name,
            'visits' => BuildingVisit::where('user_id', $t->id)->where('visit_type', 'fixed')->where('visited_at', '>=', $from)->count(),
            'orders' => WorkOrder::where('status', 'completed')->where('finished_at', '>=', $from)
                ->whereHas('participants', fn ($p) => $p->where('users.id', $t->id))->count(),
            'close_days' => $this->avgCloseDays($from, now(), $t->id),
        ])->sortByDesc(fn ($t) => $t['visits'] + $t['orders'])->values();

        $quotes = null;
        if ($withQuotes) {
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
            'top_failures' => $this->failures->recurrent(90, 1)->take(5),
            'quotes' => $quotes,
        ];
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
            $metric('Reportes y reclamos', fn ($a, $b) => $this->claims($a, $b)),
            $metric('Órdenes completadas', fn ($a, $b) => WorkOrder::where('status', 'completed')->whereBetween('finished_at', [$a, $b])->count()),
            $metric('Mantenimientos hechos', fn ($a, $b) => BuildingVisit::where('visit_type', 'fixed')->where('assignment_type', 'maintenance')->whereBetween('visited_at', [$a, $b])->count()),
            $metric('Presupuestos aprobados ($)', fn ($a, $b) => (int) Quote::where('status', Quote::APPROVED)->whereBetween('created_at', [$a, $b])->sum('amount')),
        ])->map(fn ($m) => $m + ['change' => $this->change($m['now'], $m['before'])]);

        // Cartera: ingresos mensualizados de los contratos activos.
        $services = MaintenanceService::active()->with('client:id,name')->get();
        $monthly = $services->map(fn ($s) => ['client' => $s->client?->name, 'monthly' => (float) $s->amount / max(1, $s->monthsPerPeriod())]);
        $total = $monthly->sum('monthly');
        $byClient = $monthly->groupBy('client')->map(fn ($g) => $g->sum('monthly'))->sortDesc();

        $buildings = Building::count();
        $withContract = Building::whereHas('maintenanceServices', fn ($q) => $q->where('status', MaintenanceService::ACTIVE))->count();
        $elevators = Elevator::active()->count();
        $recurrent = $this->failures->recurrent()->count();

        // Tendencia de fallas por trimestre (último año).
        $quarters = collect(range(3, 0))->map(function (int $back) use ($now) {
            $end = $now->copy()->subMonthsNoOverflow($back * 3);
            $start = $end->copy()->subMonthsNoOverflow(3);

            return ['label' => $start->format('m/y').'–'.$end->format('m/y'), 'claims' => $this->claims($start, $end)];
        });

        return [
            'comparison' => $comparison,
            'portfolio' => [
                ['question' => '¿Cuánto factura la cartera por mes?', 'value' => '$'.number_format($total, 0, ',', '.'), 'context' => $services->count().' contratos activos (mensualizados)'],
                ['question' => '¿Dependemos de pocos clientes?', 'value' => $total > 0 ? $this->pct($byClient->take(5)->sum(), $total).'%' : '—', 'context' => 'de los ingresos vienen de los 5 clientes principales'],
                ['question' => '¿Cuántos edificios no tienen contrato?', 'value' => (string) max(0, $buildings - $withContract), 'context' => "de {$buildings} edificios · ".Client::count().' clientes'],
                ['question' => '¿Qué parte de los ascensores falla seguido?', 'value' => $elevators > 0 ? $this->pct($recurrent, $elevators).'%' : '—', 'context' => "{$recurrent} de {$elevators} con ".FailureAnalysis::THRESHOLD.'+ avisos de falla en '.FailureAnalysis::DAYS.' días'],
            ],
            'top_clients' => $byClient->take(5)->map(fn ($amount, $client) => ['client' => $client, 'monthly' => $amount, 'share' => $total > 0 ? $this->pct($amount, $total) : null])->values(),
            'quarters' => $quarters,
        ];
    }
}
