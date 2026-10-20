<?php

namespace App\Support\Portal;

use App\Http\Controllers\Portal\PortalClaimController;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\BuildingVisitPlan;
use App\Models\ClientClaim;
use App\Models\Quote;
use App\Models\WorkOrder;
use App\Services\Visits\VisitSchedule;
use App\Support\WorkOrderLabels;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Portal de transparencia de la administración (Ascento Conecta): lo que se
 * puede contar de un edificio autorizado, SOLO con registros reales de la
 * empresa activa del portal.
 *
 * - period(): lo que corresponde en un mes según la frecuencia de visitas
 *   cargada para el edificio. Sin frecuencia cargada, no se inventa nada.
 * - summary(): el resumen de un mes, bajo demanda (no se guarda). Cada número
 *   sale de una consulta a la base; si no hay registros, se dice.
 *
 * No es un certificado de cumplimiento ni una evaluación técnica: es un
 * resumen de lo que la empresa registró y compartió.
 */
final class BuildingTransparency
{
    /**
     * @return list<array{type: string, period: string, frequency: string, expected: int, done: int, not_done: int, pending: int}>
     */
    public static function period(int $companyId, Building $building, int $month, int $year): array
    {
        $types = BuildingVisitPlan::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $building->id)
            ->where('starts_on', '<=', CarbonImmutable::create($year, $month, 1)->endOfMonth())
            ->distinct()->pluck('type')->intersect(['maintenance', 'inspection'])->values();

        if ($types->isEmpty()) {
            return [];
        }

        $schedule = VisitSchedule::load([(int) $building->id], $month, $year, $companyId);

        return $types->map(function (string $type) use ($schedule, $building, $month, $year) {
            $e = $schedule->evaluate((int) $building->id, $type, $month, $year);

            return [
                'type' => $type === 'inspection' ? 'Inspección' : 'Mantenimiento',
                'period' => ucfirst($e['period']->label()),
                'frequency' => $e['period']->frequencyLabel(),
                'expected' => $e['expected'],
                'done' => $e['done'],
                'not_done' => $e['not_done'],
                'pending' => $e['pending'],
            ];
        })->filter(fn ($row) => $row['expected'] > 0)->values()->all();
    }

    /** Reclamos e incidencias del edificio que esta persona ve (los suyos y los del QR compartidos). */
    public static function incidents(Request $request, int $companyId, Building $building): Collection
    {
        return PortalClaimController::visibleQuery($request, $companyId)->where('building_id', $building->id)
            ->with('elevator')->latest('created_at')->get();
    }

    /** El resumen de un mes, calculado ahora con registros reales. */
    public static function summary(Request $request, int $companyId, Building $building, int $month, int $year): array
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $to = $from->endOfMonth();

        $visits = BuildingVisit::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $building->id)
            ->where('in_progress', false)->where('visit_type', 'fixed')->where('month', $month)->where('year', $year)
            ->with('deliveryNote:id,building_visit_id,performed')->get();

        $documents = PortalDocuments::counts($companyId, [(int) $building->id], ['from' => $from->toDateString(), 'to' => $to->toDateString()]);

        $incidents = self::incidents($request, $companyId, $building)->filter(fn (ClientClaim $c) => $c->created_at->between($from, $to))->values();

        $orders = WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('id', $incidents->pluck('work_order_id')->filter()->all() ?: [0])->get(['id', 'type', 'status']);

        $quotes = Quote::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $building->id)->whereNull('deleted_at')
            ->where('shared_with_client', true)->whereBetween('created_at', [$from, $to])->get();

        $now = CarbonImmutable::now();

        return [
            'month' => $month,
            'year' => $year,
            'label' => ucfirst($from->locale('es')->translatedFormat('F Y')),
            'visits' => [
                'maintenance' => self::visitCounts($visits->where('assignment_type', 'maintenance')),
                'inspection' => self::visitCounts($visits->where('assignment_type', 'inspection')),
            ],
            'documents' => $documents,
            'incidents' => [
                'total' => $incidents->count(),
                'by_status' => $incidents->groupBy(fn (ClientClaim $c) => $c->statusLabel())->map->count()->all(),
            ],
            'orders' => $orders->map(fn ($o) => WorkOrderLabels::type((string) $o->type).' · '.WorkOrderLabels::status((string) $o->status))->countBy()->all(),
            'quotes' => $quotes->groupBy(fn (Quote $q) => $q->displayStatusLabel())->map->count()->all(),
            'period' => self::period($companyId, $building, $month, $year),
            // El mes en curso todavía no terminó: los números pueden cambiar.
            'partial' => $from->isSameMonth($now),
            'future' => $from->greaterThan($now->startOfMonth()),
        ];
    }

    private static function visitCounts(Collection $visits): array
    {
        return [
            'total' => $visits->count(),
            'done' => $visits->filter(fn ($v) => $v->deliveryNote?->performed !== false)->count(),
            'not_done' => $visits->filter(fn ($v) => $v->deliveryNote?->performed === false)->count(),
        ];
    }
}
