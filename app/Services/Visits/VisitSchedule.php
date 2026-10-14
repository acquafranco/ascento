<?php

namespace App\Services\Visits;

use App\Models\BuildingVisit;
use App\Models\BuildingVisitPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ÚNICA fuente de verdad para las visitas periódicas (mantenimiento e
 * inspección): qué período corresponde a un mes, cuántas visitas se esperan,
 * cuántas se hicieron y qué número de visita le toca al próximo remito.
 * La usan la pantalla de Visitas del técnico, su inicio, la Agenda, el mapa,
 * los indicadores, los recordatorios, el portal y el alta del remito.
 *
 * Reglas:
 * - Frecuencia: la del plan del edificio y tipo (BuildingVisitPlan) vigente
 *   en ese mes; sin plan, mensual (el comportamiento de siempre).
 * - Una visita cuenta en el período que contiene su mes (month/year).
 * - Realizada: tiene remito y NO está marcado "no realizado" (o es una
 *   visita sin remito, como siempre contó). Un remito "no realizado" ocupa
 *   su lugar pero no cuenta como realizada. Una visita "en curso" (abierta
 *   para cargar fotos, todavía sin remito) ocupa su lugar y está pendiente.
 * - Solo cuentan las visitas con identidad (period_key): los duplicados
 *   históricos sin clave nunca se cuentan dos veces.
 * - Frecuencias de varios meses: si la visita del período ya se hizo en otro
 *   mes, este mes no tiene nada previsto ("hecho en el período").
 * - Antes de usar Ascento: un período que empieza antes del mes de alta de la
 *   empresa (companies.created_at) no espera visitas, porque no tener registros
 *   de esa época no significa que el trabajo quedó pendiente. Las visitas
 *   reales que se hayan cargado de esos meses se siguen contando y viendo.
 *
 * No usa el scope de empresa: recibe ids de edificios ya filtrados por
 * empresa (y opcionalmente la empresa), así sirve también en consola.
 */
final class VisitSchedule
{
    public const TYPES = ['maintenance', 'inspection'];

    /** Un período dura como mucho 12 meses: alcanza con mirar 11 hacia cada lado. */
    private const WINDOW = 11;

    /**
     * @param  Collection<string, Collection<int, BuildingVisitPlan>>  $plans  por "edificio|tipo", por inicio
     * @param  Collection<string, Collection<int, BuildingVisit>>  $visits  por "edificio|tipo"
     * @param  Collection<int, int>  $starts  por edificio: mes (año * 12 + mes) en que su empresa empezó a usar Ascento
     */
    private function __construct(
        private Collection $plans,
        private Collection $visits,
        private Collection $starts,
    ) {}

    /** @param  iterable<int>  $buildingIds */
    public static function load(iterable $buildingIds, int $month, int $year, ?int $companyId = null): self
    {
        $ids = collect($buildingIds)->map(fn ($id) => (int) $id)->unique()->values()->all() ?: [0];
        $center = $year * 12 + $month;

        $plans = BuildingVisitPlan::withoutGlobalScopes()
            ->whereIn('building_id', $ids)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('starts_on')
            ->get();

        // Solo los meses que pueden caer en el período: con todo mensual (lo
        // habitual) se lee únicamente el mes pedido, como antes de la etapa.
        $window = min(self::WINDOW, max(0, (int) $plans->max('every_months') - 1));

        $visits = BuildingVisit::withoutGlobalScopes()
            ->whereIn('building_id', $ids)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('visit_type', 'fixed')
            ->whereIn('assignment_type', self::TYPES)
            ->whereNotNull('period_key')
            ->where(fn ($q) => self::monthRange($q, $center - $window, $center + $window))
            ->with(['user:id,name', 'deliveryNote' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'building_visit_id', 'performed', 'number')])
            ->orderBy('year')->orderBy('month')->orderBy('slot')->orderBy('id')
            ->get()
            ->groupBy(fn (BuildingVisit $v) => $v->building_id.'|'.$v->assignment_type);

        // Desde cuándo usa Ascento la empresa de cada edificio: su alta en la plataforma
        // (no el alta del edificio, ni el «inicio de actividades» fiscal de la empresa).
        $starts = DB::table('buildings')
            ->join('companies', 'companies.id', '=', 'buildings.company_id')
            ->whereIn('buildings.id', $ids)
            ->pluck('companies.created_at', 'buildings.id')
            ->filter()
            ->map(fn ($createdAt) => VisitPeriod::index(CarbonImmutable::parse($createdAt)->startOfMonth()));

        return new self($plans->groupBy(fn (BuildingVisitPlan $p) => $p->building_id.'|'.$p->type), $visits, $starts);
    }

    /** Mes (año * 12 + mes) desde el que la empresa del edificio usa Ascento, si se conoce. */
    public function startIndex(int $buildingId): ?int
    {
        return $this->starts->get($buildingId);
    }

    /** Meses entre dos índices (año * 12 + mes) sobre las columnas year/month, usando sus índices. */
    private static function monthRange($query, int $from, int $to): void
    {
        [$y0, $m0] = [intdiv($from - 1, 12), ($from - 1) % 12 + 1];
        [$y1, $m1] = [intdiv($to - 1, 12), ($to - 1) % 12 + 1];

        if ($from === $to) {
            $query->where('year', $y0)->where('month', $m0);

            return;
        }

        $query->where(fn ($q) => $q->where('year', '>', $y0)->orWhere(fn ($w) => $w->where('year', $y0)->where('month', '>=', $m0)))
            ->where(fn ($q) => $q->where('year', '<', $y1)->orWhere(fn ($w) => $w->where('year', $y1)->where('month', '<=', $m1)));
    }

    /** Período que contiene ese mes para el edificio y tipo. */
    public function period(int $buildingId, string $type, int $month, int $year): VisitPeriod
    {
        $target = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $index = VisitPeriod::index($target);
        $plans = $this->plans->get($buildingId.'|'.$type, collect());

        $effective = $plans->last(fn (BuildingVisitPlan $p) => self::planIndex($p) <= $index);
        $next = $plans->first(fn (BuildingVisitPlan $p) => self::planIndex($p) > $index);

        if (! $effective) {
            return new VisitPeriod($type, $target, $target, 1, null);
        }

        $every = max(1, (int) $effective->every_months);
        $origin = CarbonImmutable::create($effective->starts_on->year, $effective->starts_on->month, 1)->startOfDay();
        $start = $origin->addMonthsNoOverflow(intdiv($index - VisitPeriod::index($origin), $every) * $every);
        $end = $start->addMonthsNoOverflow($every - 1);

        // Si cambia la frecuencia en el medio, el período viejo termina antes.
        if ($next && self::planIndex($next) <= VisitPeriod::index($end)) {
            $end = CarbonImmutable::create($next->starts_on->year, $next->starts_on->month, 1)->startOfDay()->subMonthNoOverflow();
        }

        return new VisitPeriod($type, $start, $end, max(1, (int) $effective->visits_per_month), $effective);
    }

    /**
     * Situación de un edificio y tipo en un mes.
     *
     * @return array{period: VisitPeriod, visits: Collection<int, BuildingVisit>, expected: int, done: int, not_done: int, pending: int, covered: bool, before_start: bool, free_slot: ?int}
     */
    public function evaluate(int $buildingId, string $type, int $month, int $year): array
    {
        $period = $this->period($buildingId, $type, $month, $year);
        $visits = $this->visits->get($buildingId.'|'.$type, collect())
            ->filter(fn (BuildingVisit $v) => $period->contains((int) $v->month, (int) $v->year))
            ->values();

        $expected = $period->expected;
        // Una visita "en curso" (abierta para cargar fotos, sin remito) ocupa
        // su lugar pero todavía no está realizada: cuenta como pendiente.
        $closed = $visits->reject(fn (BuildingVisit $v) => $v->in_progress);

        // Período que empezó antes de que la empresa usara Ascento: solo cuenta lo que
        // realmente se registró (nunca queda nada pendiente ni vencido por falta de datos).
        $start = $this->startIndex($buildingId);
        $beforeStart = $start !== null && VisitPeriod::index($period->start) < $start;
        if ($beforeStart) {
            $expected = min($expected, $visits->count());
        }
        $done = min($expected, $closed->filter(fn (BuildingVisit $v) => $v->deliveryNote?->performed !== false)->count());
        $notDone = min($expected - $done, $closed->filter(fn (BuildingVisit $v) => $v->deliveryNote?->performed === false)->count());
        $pending = $expected - $done - $notDone;

        $usedSlots = $visits->pluck('slot')->map(fn ($s) => (int) $s)->all();
        $freeSlot = null;
        // Lugar libre según la frecuencia del período (también antes de usar Ascento:
        // se puede cargar una visita histórica real de esos meses).
        if ($visits->count() < $period->expected) {
            foreach (range(1, $period->expected) as $slot) {
                if (! in_array($slot, $usedSlots, true)) {
                    $freeSlot = $slot;
                    break;
                }
            }
        }

        // Varios meses: la visita del período ya se hizo en otro mes → este mes no tiene nada previsto.
        $covered = ! $beforeStart && $period->isMultiMonth() && $pending === 0
            && $visits->doesntContain(fn (BuildingVisit $v) => (int) $v->month === $month && (int) $v->year === $year);

        return [
            'period' => $period,
            'visits' => $visits,
            'expected' => $covered ? 0 : $expected,
            'done' => $covered ? 0 : $done,
            'not_done' => $covered ? 0 : $notDone,
            'pending' => $covered ? 0 : $pending,
            'covered' => $covered,
            'before_start' => $beforeStart,
            'free_slot' => $freeSlot,
        ];
    }

    private static function planIndex(BuildingVisitPlan $plan): int
    {
        return $plan->starts_on->year * 12 + $plan->starts_on->month;
    }
}
