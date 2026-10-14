<?php

namespace App\Services\Insights;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\MaintenanceService;
use App\Services\Visits\VisitPeriod;
use App\Services\Visits\VisitSchedule;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Agenda automática de mantenimientos e inspecciones.
 *
 * Sale de lo que ya existe, con las reglas de VisitSchedule:
 * - Qué edificio se visita y quién: asignaciones edificio–técnico
 *   (building_user, tipo maintenance / inspection) y servicios activos.
 * - Cada cuánto: la frecuencia de visitas del edificio (mensual si no tiene
 *   una propia; quincenal = 2 visitas en el mes; cada N meses = 1 visita por
 *   período). La frecuencia de COBRO del servicio no interviene.
 * - Si se hizo: las visitas periódicas (building_visits, fixed) del período,
 *   que se crean cuando el técnico firma el remito.
 *
 * Estado de cada fila: hecho, no realizado, vencido (período terminado con
 * visitas faltantes), pendiente (período en curso), próximo (período
 * futuro), sin asignar (tiene contrato pero no técnico) o hecho en el
 * período (frecuencias de varios meses: ya se visitó otro mes del período).
 */
class MaintenanceAgenda
{
    /**
     * Frecuencia de un edificio SIN plan propio: mensual, como siempre. La
     * frecuencia del servicio (maintenance_services.frequency) es la de
     * COBRO, no la de visitas: la frecuencia de visitas es BuildingVisitPlan.
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
        // Frecuencia de varios meses: la visita del período ya se hizo otro mes.
        'covered' => 'Hecho en el período',
        // Sin ascensores ni montacargas activos: no se programa ninguna visita
        // hasta que se registren sus equipos.
        'no_equipment' => 'Sin equipos',
        // Período anterior al alta de la empresa en Ascento y sin registros:
        // no es un vencimiento (ver VisitSchedule).
        'before_start' => 'Antes de usar Ascento',
    ];

    /**
     * @return Collection<int, array{building: Building, type: string, status: string, technicians: Collection, visit: ?BuildingVisit, visits: Collection, period: VisitPeriod, expected: int, done: int, not_done: int, pending: int, elevators: int}>
     */
    public function rows(int $month, int $year, ?string $type = null, ?int $technicianId = null, ?string $status = null): Collection
    {
        $buildings = $this->buildings($month, $year, $technicianId);
        $schedule = $this->schedule($buildings, $month, $year, $technicianId);

        // Edificios con un contrato vigente en ese mes (aunque no tengan técnico).
        [$contractBuildings, $contractClients] = $this->contracts(Carbon::create($year, $month, 1)->startOfMonth());

        $rows = collect();

        foreach ($buildings as $building) {
            foreach (array_keys(self::TYPES) as $each) {
                if ($type && $type !== $each) {
                    continue;
                }

                $technicians = $building->users->filter(fn ($u) => $u->pivot->type === $each)->values();
                $eval = $schedule->evaluate($building->id, $each, $month, $year);
                $hasContract = $contractBuildings->has($building->id) || $contractClients->has($building->client_id);

                // Solo lo que corresponde visitar: tiene técnico asignado, o
                // tiene contrato de mantenimiento (y entonces falta asignarlo).
                if ($technicians->isEmpty() && $eval['visits']->isEmpty() && ! ($hasContract && $each === 'maintenance')) {
                    continue;
                }

                $period = $eval['period'];
                $hasEquipment = $building->hasEquipment();
                $rowStatus = self::statusFor($eval, $technicians->isNotEmpty(), $hasEquipment);
                // Sin equipos: no es candidato a visitas (nada previsto ni pendiente).
                $eval = $hasEquipment ? $eval : [...$eval, 'expected' => 0, 'done' => 0, 'not_done' => 0, 'pending' => 0];

                if ($technicianId && ! $technicians->contains('id', $technicianId) && ! $eval['visits']->contains('user_id', $technicianId)) {
                    continue;
                }

                if ($status && $status !== $rowStatus) {
                    continue;
                }

                $rows->push([
                    'building' => $building,
                    'building_id' => $building->id,
                    'assigned' => $technicians->isNotEmpty(),
                    'type' => $each,
                    'status' => $rowStatus,
                    'technicians' => $technicians,
                    // La última visita del período (compatibilidad con las vistas).
                    'visit' => $eval['visits']->last(),
                    'visits' => $eval['visits'],
                    'period' => $period,
                    'expected' => $eval['expected'],
                    'done' => $eval['done'],
                    'not_done' => $eval['not_done'],
                    'pending' => $eval['pending'],
                    'elevators' => (int) $building->elevator_count + (int) $building->freight_elevator_count,
                ]);
            }
        }

        // Primero lo que requiere acción.
        $order = ['overdue' => 0, 'not_done' => 1, 'unassigned' => 2, 'no_equipment' => 3, 'pending' => 4, 'upcoming' => 5, 'done' => 6, 'covered' => 7, 'before_start' => 8];

        return $rows->sortBy(fn ($row) => $order[$row['status']].'|'.$row['building']->name)->values();
    }

    /**
     * Estado de una fila (edificio + tipo) a partir de VisitSchedule::evaluate.
     * Lo usan la Agenda y la pantalla de Visitas del técnico: misma regla.
     */
    public static function statusFor(array $eval, bool $hasTechnicians, bool $hasEquipment = true): string
    {
        $current = VisitPeriod::index(CarbonImmutable::now()->startOfMonth());
        $period = $eval['period'];

        return match (true) {
            ! $hasEquipment => 'no_equipment',
            ($eval['before_start'] ?? false) && $eval['expected'] === 0 => 'before_start',
            $eval['covered'] => 'covered',
            $eval['pending'] === 0 => $eval['done'] >= $eval['expected'] ? 'done' : 'not_done',
            ! $hasTechnicians => 'unassigned',
            VisitPeriod::index($period->end) < $current => 'overdue',
            VisitPeriod::index($period->start) <= $current => 'pending',
            default => 'upcoming',
        };
    }

    /**
     * Progreso por tipo (y total), contando VISITAS (nunca ascensores):
     * - asignados: EDIFICIOS con técnico asignado (un edificio con
     *   mantenimiento e inspección cuenta una vez en el total);
     * - previstas: visitas que corresponden al período según la frecuencia
     *   real de cada edificio (un edificio quincenal suma 2; uno trimestral
     *   suma 1 solo en el mes que le toca);
     * - realizadas: visitas con remito que no está "no realizado";
     * - pendientes: previstas que todavía no se completaron (incluye las de
     *   remito "no realizado", que se informan aparte en not_done).
     * Filas: las de rows() o cualquier lista con building_id, type, assigned,
     * expected, done, not_done, pending y elevators.
     *
     * @return array<string, array{assigned: int, expected: int, done: int, not_done: int, pending: int, percent: ?int, machines: int, before_start: int}>
     */
    public static function progress(Collection $rows): array
    {
        $calc = function (Collection $rows): array {
            $assigned = $rows->filter(fn ($r) => $r['assigned']);
            $expected = (int) $rows->sum('expected');
            $done = (int) $rows->sum('done');

            return [
                'assigned' => $assigned->pluck('building_id')->unique()->count(),
                'expected' => $expected,
                'done' => $done,
                'not_done' => (int) $rows->sum('not_done'),
                'pending' => max(0, $expected - $done),
                'percent' => $expected > 0 ? (int) floor($done / $expected * 100) : null,
                'machines' => (int) $assigned->unique('building_id')->sum('elevators'),
                // Filas de un período anterior a que la empresa usara Ascento (sin registros).
                'before_start' => $rows->where('status', 'before_start')->count(),
            ];
        };

        $result = [];
        foreach (array_keys(self::TYPES) as $type) {
            $result[$type] = $calc($rows->where('type', $type));
        }
        $result['total'] = $calc($rows);

        return $result;
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

    /** Frecuencias y visitas de esos edificios alrededor del mes (VisitSchedule). */
    private function schedule(Collection $buildings, int $month, int $year, ?int $technicianId): VisitSchedule
    {
        return $this->remember('schedule.'.($technicianId ? "{$technicianId}." : 'all.')."{$month}.{$year}", fn () => VisitSchedule::load(
            $buildings->pluck('id'), $month, $year, CompanyContext::currentId(),
        ));
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
