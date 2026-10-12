<?php

namespace App\Services\Insights;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\MaintenanceService;
use App\Support\CompanyContext;
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
    /**
     * Cada cuántos meses se visita un edificio. En Ascento la visita es
     * MENSUAL en todo el sistema: una visita fija por edificio, tipo y mes
     * (building_visits.month/year), un remito por mes y la app del técnico
     * cuenta los pendientes del mes. La frecuencia del servicio
     * (maintenance_services.frequency) es la de COBRO, no la de visitas: un
     * contrato cobrado por trimestre igual se mantiene todos los meses.
     * Si algún día se necesitan visitas no mensuales, falta un dato propio
     * (p. ej. visit_every_months en el servicio) que hoy no existe.
     */
    public const VISIT_EVERY_MONTHS = 1;

    public const TYPES = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección'];

    public const STATUSES = [
        'done' => 'Hecho',
        // Remito firmado pero marcado "Trabajo NO realizado correctamente":
        // la visita existe, el mantenimiento no se cumplió. La segunda visita
        // se resuelve con una orden de trabajo (proceso existente).
        'not_done' => 'No realizado',
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

        $buildings = $this->buildings($month, $year, $technicianId);
        $visits = $this->visits($month, $year);

        // Edificios con un contrato vigente en ese mes (aunque no tengan técnico).
        [$contractBuildings, $contractClients] = $this->contracts($period);

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
                    $visit !== null && $visit->deliveryNote?->performed === false => 'not_done',
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
        $order = ['overdue' => 0, 'not_done' => 1, 'unassigned' => 2, 'pending' => 3, 'upcoming' => 4, 'done' => 5];

        return $rows->sortBy(fn ($row) => $order[$row['status']].'|'.$row['building']->name)->values();
    }

    /*
     * Datos de base, una sola vez por solicitud. El centro de atención, los
     * indicadores y el mapa piden la agenda varias veces (por tipo, estado y
     * mes): antes cada llamada volvía a cargar todos los edificios con sus
     * técnicos, visitas y contratos. La clave lleva la empresa actual, así que
     * nunca se mezclan datos de otra.
     */

    /** Edificios con cliente y técnicos. Con técnico: solo los suyos (asignados o visitados ese mes). */
    private function buildings(int $month, int $year, ?int $technicianId): Collection
    {
        return $this->remember('buildings.'.($technicianId ? "{$technicianId}.{$month}.{$year}" : 'all'), fn () => Building::query()
            ->with(['client:id,name', 'users' => fn ($q) => $q->select('users.id', 'users.name')])
            ->when($technicianId, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('users', fn ($u) => $u->where('users.id', $technicianId))
                ->orWhereHas('visits', fn ($v) => $v->where('user_id', $technicianId)->where('visit_type', 'fixed')->where('month', $month)->where('year', $year))))
            ->orderBy('name')
            ->get());
    }

    /** Visitas fijas del mes, por "edificio|tipo". */
    private function visits(int $month, int $year): Collection
    {
        return $this->remember("visits.{$month}.{$year}", fn () => BuildingVisit::query()
            ->with(['user:id,name', 'deliveryNote:id,building_visit_id,performed,number'])
            ->where('visit_type', 'fixed')
            ->where('month', $month)
            ->where('year', $year)
            ->whereIn('assignment_type', array_keys(self::TYPES))
            ->get()
            ->keyBy(fn (BuildingVisit $v) => $v->building_id.'|'.$v->assignment_type));
    }

    /** @return array{0: Collection, 1: Collection} edificios y clientes con contrato vigente en el mes */
    private function contracts(Carbon $period): array
    {
        return $this->remember('contracts.'.$period->format('Y-m'), function () use ($period) {
            $underContract = MaintenanceService::query()
                ->where('status', MaintenanceService::ACTIVE)
                ->whereDate('start_date', '<=', $period->copy()->endOfMonth())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $period))
                ->get(['building_id', 'client_id']);

            return [$underContract->pluck('building_id')->filter()->flip(), $underContract->whereNull('building_id')->pluck('client_id')->flip()];
        });
    }

    /**
     * Memoria de la solicitud actual (páginas y actualizaciones de Livewire).
     * Fuera de una solicitud web (consola, colas, llamadas directas) no se
     * guarda nada: siempre se consulta de nuevo.
     */
    private function remember(string $key, \Closure $load): mixed
    {
        $request = app()->bound('request') ? request() : null;

        if (! $request || ! $request->route()) {
            return $load();
        }

        $key = 'ascento.agenda.'.(CompanyContext::currentId() ?? 'none').'.'.$key;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $load());
        }

        return $request->attributes->get($key);
    }

    /** Conteo por estado del mes (para el resumen y el centro de atención). */
    public function summary(int $month, int $year, ?string $type = null): array
    {
        $counts = $this->rows($month, $year, $type)->countBy('status')->all();

        return array_merge(array_fill_keys(array_keys(self::STATUSES), 0), $counts);
    }
}
