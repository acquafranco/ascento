<?php

namespace App\Services\Visits;

use App\Models\Building;
use App\Models\BuildingVisitPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambia la frecuencia de visitas de un edificio para un tipo de trabajo,
 * desde un mes (el actual o uno futuro: nunca hacia atrás, así el historial
 * conserva la frecuencia que tenía). Si ese mes cae en medio de un período de
 * varios meses, rige desde el siguiente período (effectiveStart). No toca
 * servicios, cobros ni precios.
 */
class VisitPlanService
{
    /**
     * Opciones de "desde qué mes": el actual y los 12 siguientes.
     *
     * @return array<string, string> "Y-m" => "Octubre 2026"
     */
    public static function startOptions(): array
    {
        return collect(range(0, 12))->mapWithKeys(function (int $offset) {
            $month = CarbonImmutable::now()->startOfMonth()->addMonthsNoOverflow($offset);

            return [$month->format('Y-m') => ucfirst($month->locale('es')->translatedFormat('F Y'))];
        })->all();
    }

    /**
     * Normaliza lo que viene del formulario: [visitas por mes, cada N meses, inicio].
     *
     * @return array{0: int, 1: int, 2: CarbonImmutable}
     */
    public static function normalize(string $frequency, mixed $everyMonths, mixed $startsOn): array
    {
        if (! array_key_exists($frequency, BuildingVisitPlan::FREQUENCIES)) {
            throw ValidationException::withMessages(['frequency' => 'Elegí una frecuencia válida.']);
        }

        [, $perMonth, $every] = BuildingVisitPlan::FREQUENCIES[$frequency];
        $every ??= (int) $everyMonths;

        if ($every < 1 || $every > 12 || ($frequency === 'months' && $every < 2)) {
            throw ValidationException::withMessages(['every_months' => 'Elegí cada cuántos meses (de 2 a 12).']);
        }

        if (! is_string($startsOn) || ! preg_match('/^\d{4}-\d{2}$/', $startsOn) || ! array_key_exists($startsOn, self::startOptions())) {
            throw ValidationException::withMessages(['starts_on' => 'Elegí desde qué mes rige (el actual o uno próximo).']);
        }

        return [(int) $perMonth, (int) $every, CarbonImmutable::createFromFormat('!Y-m', $startsOn)];
    }

    /**
     * Plan vigente en un mes (null = mensual por defecto).
     */
    public function effective(Building $building, string $type, CarbonImmutable $month): ?BuildingVisitPlan
    {
        return BuildingVisitPlan::withoutGlobalScopes()
            ->where('building_id', $building->id)->where('type', $type)
            ->whereDate('starts_on', '<=', $month->toDateString())
            ->orderByDesc('starts_on')->first();
    }

    /**
     * Mes desde el que rige de verdad un cambio pedido "desde $start".
     *
     * Un cambio nunca corta un período de varios meses ya empezado: si $start
     * cae en medio de uno, rige desde el mes siguiente a ese período (el
     * siguiente período válido). Así ningún período anterior se reinterpreta
     * (no aparece vencido uno que se completó) y ninguna visita registrada
     * queda fuera de su período. Mensual y quincenal empiezan cada mes: rige
     * desde $start.
     */
    public function effectiveStart(Building $building, string $type, CarbonImmutable $start): CarbonImmutable
    {
        $start = $start->startOfMonth();
        $current = $this->effective($building, $type, $start);
        $every = max(1, (int) ($current?->every_months ?? 1));

        if ($every === 1) {
            return $start;
        }

        $origin = CarbonImmutable::create($current->starts_on->year, $current->starts_on->month, 1)->startOfDay();
        $elapsed = VisitPeriod::index($start) - VisitPeriod::index($origin);

        return $elapsed % $every === 0 ? $start : $origin->addMonthsNoOverflow((intdiv($elapsed, $every) + 1) * $every);
    }

    /**
     * Aplica la frecuencia desde $start. Devuelve true si cambió algo.
     * Las frecuencias programadas para después de $start se reemplazan (son
     * futuras: no son historial).
     */
    public function set(Building $building, string $type, int $perMonth, int $every, CarbonImmutable $start): bool
    {
        if (! array_key_exists($type, BuildingVisitPlan::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Tipo de visita inválido.']);
        }

        $start = $start->startOfMonth();

        if ($start->lt(CarbonImmutable::now()->startOfMonth())) {
            throw ValidationException::withMessages(['starts_on' => 'La frecuencia no se puede cambiar hacia atrás.']);
        }

        // En medio de un período de varios meses, rige desde el siguiente.
        $start = $this->effectiveStart($building, $type, $start);

        // Todo o nada: si al final no se puede, no quedan las frecuencias futuras borradas.
        return DB::transaction(fn () => $this->apply($building, $type, $perMonth, $every, $start));
    }

    private function apply(Building $building, string $type, int $perMonth, int $every, CarbonImmutable $start): bool
    {
        $query = fn () => BuildingVisitPlan::withoutGlobalScopes()->where('building_id', $building->id)->where('type', $type);

        $future = $query()->whereDate('starts_on', '>', $start->toDateString())->get();
        $future->each->delete();

        $same = $query()->whereDate('starts_on', $start->toDateString())->first();
        $before = $query()->whereDate('starts_on', '<', $start->toDateString())->orderByDesc('starts_on')->first();
        $previous = [$before?->visits_per_month ?? 1, $before?->every_months ?? 1];

        // Igual a lo que ya regía: no hace falta una fila nueva.
        if ([$perMonth, $every] === $previous) {
            if ($same) {
                $same->delete();

                return true;
            }

            return $future->isNotEmpty();
        }

        if ($same) {
            if ([$same->visits_per_month, $same->every_months] === [$perMonth, $every]) {
                return $future->isNotEmpty();
            }
            $same->forceFill(['visits_per_month' => $perMonth, 'every_months' => $every])->save();

            return true;
        }

        $plan = new BuildingVisitPlan(['building_id' => $building->id, 'type' => $type, 'visits_per_month' => $perMonth, 'every_months' => $every, 'starts_on' => $start->toDateString()]);
        $plan->created_by = auth()->id();
        $plan->save();

        return true;
    }
}
