<?php

namespace App\Services\Insights;

use App\Models\Elevator;
use App\Models\Report;
use App\Models\WorkOrder;
use App\Support\ElevatorComponents;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Análisis de fallas por reglas (no IA).
 *
 * Intervención = un reporte del técnico o una orden de trabajo de tipo
 * "reclamo" sobre un equipo (edificio + "Ascensor N"). Un equipo es
 * reincidente si tiene THRESHOLD o más en los últimos DAYS días.
 * El componente sale del campo `component`; lo que no lo tiene se informa
 * como "sin clasificar" (no se adivina).
 *
 * Todas las consultas pasan por el scope de empresa.
 */
class FailureAnalysis
{
    public const DAYS = 90;

    public const THRESHOLD = 3;

    /** @return array{total: int, reports: int, claims: int, by_component: array<string, int>, unclassified: int, message: ?string, recurrent: bool} */
    public function forElevator(Elevator $elevator, int $days = self::DAYS): array
    {
        $since = now()->subDays($days);

        $reports = Report::where('building_id', $elevator->building_id)->where('elevator_number', $elevator->label)
            ->where('created_at', '>=', $since)->pluck('component');
        $claims = WorkOrder::where('building_id', $elevator->building_id)->where('unit', $elevator->label)
            ->where('type', 'claim')->where('created_at', '>=', $since)->pluck('component');

        $components = $reports->merge($claims);
        $total = $components->count();
        $byComponent = $components->filter()->countBy()
            ->sortDesc()
            ->mapWithKeys(fn ($count, $key) => [ElevatorComponents::label($key) => $count])
            ->all();

        return [
            'total' => $total,
            'reports' => $reports->count(),
            'claims' => $claims->count(),
            'by_component' => $byComponent,
            'unclassified' => $components->filter(fn ($c) => blank($c))->count(),
            'recurrent' => $total >= self::THRESHOLD,
            'message' => $total > 0 ? $this->message($elevator, $total, $byComponent, $days) : null,
        ];
    }

    /**
     * Equipos reincidentes de la empresa (los del usuario actual).
     *
     * @return Collection<int, array{elevator: ?Elevator, building_id: int, label: string, total: int, by_component: array<string, int>, message: string}>
     */
    public function recurrent(int $days = self::DAYS, int $threshold = self::THRESHOLD, ?\DateTimeInterface $until = null): Collection
    {
        $until ??= now();
        $since = (clone Carbon::instance($until))->subDays($days);

        $rows = Report::whereBetween('created_at', [$since, $until])->whereNotNull('elevator_number')
            ->get(['building_id', 'elevator_number as label', 'component'])
            ->concat(WorkOrder::whereBetween('created_at', [$since, $until])->where('type', 'claim')->whereNotNull('unit')
                ->get(['building_id', 'unit as label', 'component']));

        $groups = $rows->groupBy(fn ($row) => $row->building_id.'|'.$row->label)
            ->filter(fn ($group) => $group->count() >= $threshold);

        if ($groups->isEmpty()) {
            return collect();
        }

        $elevators = Elevator::with('building')
            ->whereIn('building_id', $groups->map(fn ($g) => $g->first()->building_id)->unique())
            ->get()
            ->keyBy(fn (Elevator $e) => $e->building_id.'|'.$e->label);

        return $groups->map(function ($group, $key) use ($elevators, $days) {
            [$buildingId, $label] = explode('|', $key, 2);
            $elevator = $elevators->get($key);
            $byComponent = $group->pluck('component')->filter()->countBy()->sortDesc()
                ->mapWithKeys(fn ($count, $c) => [ElevatorComponents::label($c) => $count])->all();

            return [
                'elevator' => $elevator,
                'building_id' => (int) $buildingId,
                'label' => $label,
                'total' => $group->count(),
                'by_component' => $byComponent,
                'message' => $elevator
                    ? $this->message($elevator, $group->count(), $byComponent, $days)
                    : "{$label} tuvo {$group->count()} intervenciones en los últimos {$days} días.",
            ];
        })->sortByDesc('total')->values();
    }

    /** "Ascensor 1 (Torre Sur) tuvo 7 intervenciones en los últimos 90 días: 4 de puertas y operador, 3 de maniobra." */
    private function message(Elevator $elevator, int $total, array $byComponent, int $days): string
    {
        $text = "{$elevator->label}".($elevator->building ? " ({$elevator->building->name})" : '')
            ." tuvo {$total} ".($total === 1 ? 'intervención' : 'intervenciones')." en los últimos {$days} días";

        if ($byComponent) {
            $parts = collect($byComponent)->map(fn ($count, $label) => $count.' de '.mb_strtolower($label))->take(3)->implode(', ');
            $text .= ': '.$parts;
        }

        return $text.'.';
    }
}
