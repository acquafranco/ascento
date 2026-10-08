<?php

namespace App\Services\Insights;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\MaintenanceService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Agenda automática de mantenimientos e inspecciones.
 *
 * No hay tablas nuevas: sale de lo que ya existe.
 * - Qué edificio se visita y quién: asignaciones edificio–técnico
 *   (building_user, tipo maintenance / inspection) y servicios activos.
 * - Si se hizo: la visita mensual (building_visits, fixed) de ese período,
 *   que se crea cuando el técnico firma el remito.
 *
 * Estado de cada fila: hecho, vencido (mes pasado sin visita), pendiente
 * (mes actual sin visita), próximo (mes futuro) o sin asignar (tiene
 * contrato pero no técnico).
 */
class MaintenanceAgenda
{
    public const TYPES = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección'];

    public const STATUSES = [
        'done' => 'Hecho',
        'overdue' => 'Vencido',
        'pending' => 'Pendiente',
        'upcoming' => 'Próximo',
        'unassigned' => 'Sin técnico',
    ];

    /**
     * @return Collection<int, array{building: Building, type: string, status: string, technicians: Collection, visit: ?BuildingVisit, elevators: int}>
     */
    public function rows(int $month, int $year, ?string $type = null, ?int $technicianId = null, ?string $status = null): Collection
    {
        $period = Carbon::create($year, $month, 1)->startOfMonth();
        $current = now()->startOfMonth();

        $buildings = Building::query()
            ->with(['client:id,name', 'users' => fn ($q) => $q->select('users.id', 'users.name')])
            ->orderBy('name')
            ->get();

        $visits = BuildingVisit::query()
            ->with('user:id,name')
            ->where('visit_type', 'fixed')
            ->where('month', $month)
            ->where('year', $year)
            ->whereIn('assignment_type', array_keys(self::TYPES))
            ->get()
            ->keyBy(fn (BuildingVisit $v) => $v->building_id.'|'.$v->assignment_type);

        // Edificios con un contrato vigente en ese mes (aunque no tengan técnico).
        $underContract = MaintenanceService::query()
            ->where('status', MaintenanceService::ACTIVE)
            ->whereDate('start_date', '<=', $period->copy()->endOfMonth())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $period))
            ->get(['building_id', 'client_id']);
        $contractBuildings = $underContract->pluck('building_id')->filter()->flip();
        $contractClients = $underContract->whereNull('building_id')->pluck('client_id')->flip();

        $rows = collect();

        foreach ($buildings as $building) {
            foreach (array_keys(self::TYPES) as $each) {
                if ($type && $type !== $each) {
                    continue;
                }

                $technicians = $building->users->filter(fn ($u) => $u->pivot->type === $each)->values();
                $visit = $visits->get($building->id.'|'.$each);
                $hasContract = $contractBuildings->has($building->id) || $contractClients->has($building->client_id);

                // Solo lo que corresponde visitar: tiene técnico asignado, o
                // tiene contrato de mantenimiento (y entonces falta asignarlo).
                if ($technicians->isEmpty() && ! $visit && ! ($hasContract && $each === 'maintenance')) {
                    continue;
                }

                $rowStatus = match (true) {
                    $visit !== null => 'done',
                    $technicians->isEmpty() => 'unassigned',
                    $period->lt($current) => 'overdue',
                    $period->eq($current) => 'pending',
                    default => 'upcoming',
                };

                if ($technicianId && ! $technicians->contains('id', $technicianId) && $visit?->user_id !== $technicianId) {
                    continue;
                }

                if ($status && $status !== $rowStatus) {
                    continue;
                }

                $rows->push([
                    'building' => $building,
                    'type' => $each,
                    'status' => $rowStatus,
                    'technicians' => $technicians,
                    'visit' => $visit,
                    'elevators' => (int) $building->elevator_count + (int) $building->freight_elevator_count,
                ]);
            }
        }

        // Primero lo que requiere acción.
        $order = ['overdue' => 0, 'unassigned' => 1, 'pending' => 2, 'upcoming' => 3, 'done' => 4];

        return $rows->sortBy(fn ($row) => $order[$row['status']].'|'.$row['building']->name)->values();
    }

    /** Conteo por estado del mes (para el resumen y el centro de atención). */
    public function summary(int $month, int $year, ?string $type = null): array
    {
        $counts = $this->rows($month, $year, $type)->countBy('status')->all();

        return array_merge(array_fill_keys(array_keys(self::STATUSES), 0), $counts);
    }
}
