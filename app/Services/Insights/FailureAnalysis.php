<?php

namespace App\Services\Insights;

use App\Models\Elevator;
use App\Models\Report;
use App\Models\WorkOrder;
use App\Support\ElevatorComponents;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Análisis de fallas por reglas (no IA). Se cuenta, por equipo (edificio +
 * "Ascensor N") y en una ventana de DAYS días:
 *
 * - reportes: problemas que cargan los técnicos (reports);
 * - reclamos: órdenes de trabajo de tipo "reclamo" (work_orders.type=claim);
 * - intervenciones realizadas: reclamos cuya orden está completada.
 *
 * "Avisos de falla" = reportes + reclamos. Un equipo es reincidente con
 * THRESHOLD o más avisos. Un reclamo NO se presenta como intervención hecha:
 * solo cuenta como realizada si la orden se completó.
 *
 * Reporte y reclamo no están vinculados en la base: un mismo problema puede
 * figurar en los dos (se aclara en el resultado; no se deduplica a ciegas).
 * El componente sale del campo `component`; sin él → "sin clasificar".
 * Las órdenes y reportes eliminados no cuentan. Todo pasa por el scope de
 * empresa.
 */
class FailureAnalysis
{
    public const DAYS = 90;

    public const THRESHOLD = 3;

    /** Ventana (desde, hasta]: dos ventanas seguidas no comparten eventos. */
    private function window(int $days, ?CarbonInterface $until): array
    {
        $until = $until ? Carbon::instance($until) : now();

        return [$until->copy()->subDays($days), $until];
    }

    /**
     * @return array{reports: int, claims: int, done: int, signals: int, by_component: array<string, int>, unclassified: int, recurrent: bool, message: ?string, days: int}
     */
    public function forElevator(Elevator $elevator, int $days = self::DAYS, ?CarbonInterface $until = null): array
    {
        [$since, $until] = $this->window($days, $until);

        $reports = Report::where('building_id', $elevator->building_id)->where('elevator_number', $elevator->label)
            ->where('created_at', '>', $since)->where('created_at', '<=', $until)
            ->get(['id', 'component']);
        $claims = WorkOrder::where('building_id', $elevator->building_id)->where('unit', $elevator->label)->where('type', 'claim')
            ->where('created_at', '>', $since)->where('created_at', '<=', $until)
            ->get(['id', 'component', 'status']);

        return $this->summarize($elevator, $reports, $claims, $days);
    }

    /**
     * Equipos de la empresa con THRESHOLD o más avisos de falla.
     *
     * @return Collection<int, array{elevator: ?Elevator, building_id: int, label: string, reports: int, claims: int, done: int, signals: int, by_component: array<string, int>, unclassified: int, recurrent: bool, message: string, days: int}>
     */
    public function recurrent(int $days = self::DAYS, int $threshold = self::THRESHOLD, ?CarbonInterface $until = null): Collection
    {
        [$since, $until] = $this->window($days, $until);

        $reports = Report::where('created_at', '>', $since)->where('created_at', '<=', $until)->whereNotNull('elevator_number')
            ->get(['id', 'building_id', 'elevator_number', 'component'])
            ->groupBy(fn ($r) => $r->building_id.'|'.$r->elevator_number);
        $claims = WorkOrder::where('type', 'claim')->where('created_at', '>', $since)->where('created_at', '<=', $until)->whereNotNull('unit')
            ->get(['id', 'building_id', 'unit', 'component', 'status'])
            ->groupBy(fn ($o) => $o->building_id.'|'.$o->unit);

        $keys = $reports->keys()->merge($claims->keys())->unique()
            ->filter(fn ($key) => ($reports->get($key)?->count() ?? 0) + ($claims->get($key)?->count() ?? 0) >= $threshold);

        if ($keys->isEmpty()) {
            return collect();
        }

        $elevators = Elevator::with('building')
            ->whereIn('building_id', $keys->map(fn ($k) => (int) explode('|', $k)[0])->unique())
            ->get()
            ->keyBy(fn (Elevator $e) => $e->building_id.'|'.$e->label);

        return $keys->map(function (string $key) use ($elevators, $reports, $claims, $days) {
            [$buildingId, $label] = explode('|', $key, 2);

            return [
                'elevator' => $elevators->get($key),
                'building_id' => (int) $buildingId,
                'label' => $label,
            ] + $this->summarize($elevators->get($key), $reports->get($key, collect()), $claims->get($key, collect()), $days, $label);
        })->sortByDesc('signals')->values();
    }

    private function summarize(?Elevator $elevator, Collection $reports, Collection $claims, int $days, ?string $label = null): array
    {
        $signals = $reports->count() + $claims->count();
        $components = $reports->pluck('component')->merge($claims->pluck('component'));
        $byComponent = $components->filter()->countBy()->sortDesc()
            ->mapWithKeys(fn ($count, $key) => [ElevatorComponents::label($key) => $count])->all();
        $done = $claims->where('status', 'completed')->count();

        $result = [
            'reports' => $reports->count(),
            'claims' => $claims->count(),
            'done' => $done,
            'signals' => $signals,
            'by_component' => $byComponent,
            'unclassified' => $components->filter(fn ($c) => blank($c))->count(),
            'recurrent' => $signals >= self::THRESHOLD,
            'days' => $days,
        ];

        $result['message'] = $signals > 0 ? $this->message($elevator, $label, $result) : null;

        return $result;
    }

    /**
     * "Ascensor 1 (Torre Sur): 7 avisos de falla en los últimos 90 días
     * (4 reportes y 3 reclamos; 2 reclamos resueltos). Por componente: 4 de
     * puertas y operador, 3 de maniobra / controlador."
     */
    private function message(?Elevator $elevator, ?string $label, array $r): string
    {
        $name = ($elevator?->label ?? $label).($elevator?->building ? " ({$elevator->building->name})" : '');
        $parts = array_filter([
            $r['reports'] ? $r['reports'].' '.($r['reports'] === 1 ? 'reporte' : 'reportes') : null,
            $r['claims'] ? $r['claims'].' '.($r['claims'] === 1 ? 'reclamo' : 'reclamos') : null,
        ]);

        $text = "{$name}: {$r['signals']} ".($r['signals'] === 1 ? 'aviso' : 'avisos')." de falla en los últimos {$r['days']} días (".implode(' y ', $parts);

        if ($r['claims']) {
            $text .= '; '.$r['done'].' '.($r['done'] === 1 ? 'reclamo resuelto' : 'reclamos resueltos');
        }

        $text .= ').';

        if ($r['by_component']) {
            $text .= ' Por componente: '.collect($r['by_component'])->map(fn ($count, $c) => $count.' de '.mb_strtolower($c))->take(3)->implode(', ').'.';
        }

        return $text;
    }
}
