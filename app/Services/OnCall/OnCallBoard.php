<?php

namespace App\Services\OnCall;

use App\Models\OnCallChange;
use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la portada de «Guardias», calculado una vez por pedido:
 *
 * - now: quién está de guardia ahora, desde y hasta cuándo (por guardia).
 * - next: la próxima guardia (el próximo tramo con otra cobertura).
 * - upcoming: los próximos tramos, en orden (un reemplazo parte la franja).
 * - warnings: tramos sin técnico, el mismo técnico en dos guardias a la vez y
 *   datos que faltan (sin técnicos, rotación sin participantes o por terminar).
 * - past / changes: turnos ya cumplidos y el historial de cambios.
 *
 * Solo lee, y solo de la empresa indicada.
 */
class OnCallBoard
{
    public const UPCOMING_DAYS = 7;

    public const CALENDAR_DAYS = 28;

    public function __construct(private OnCallService $service) {}

    public function build(int $companyId, ?CarbonInterface $at = null): array
    {
        $now = Carbon::parse($at ?? now());
        $schedules = OnCallSchedule::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        $calendar = $this->segments($schedules, $now, $now->copy()->addDays(self::CALENDAR_DAYS));
        $upcoming = $calendar->filter(fn ($s) => $s['start']->lt($now->copy()->addDays(self::UPCOMING_DAYS)))->values();

        return [
            'schedules' => $schedules,
            'hasSchedules' => $schedules->isNotEmpty(),
            'hasAnySchedule' => $schedules->isNotEmpty() || OnCallSchedule::withoutGlobalScopes()->where('company_id', $companyId)->exists(),
            'technicians' => OnCallService::technicians($companyId)->count(),
            'now' => $calendar->filter(fn ($s) => $s['start']->lte($now))->values(),
            'next' => $this->next($calendar, $now),
            'upcoming' => $upcoming->filter(fn ($s) => $s['start']->gt($now))->values(),
            'calendar' => $calendar->groupBy(fn ($s) => $s['start']->toDateString()),
            'warnings' => $this->warnings($companyId, $schedules, $upcoming, $now),
            'past' => OnCallShift::withoutGlobalScopes()->where('company_id', $companyId)->active()
                ->whereNotNull('ends_at')->where('ends_at', '<=', $now)
                ->with(['schedule' => fn ($q) => $q->withoutGlobalScopes(), 'user' => fn ($q) => $q->withoutGlobalScopes()])
                ->withCount(['workOrders' => fn ($q) => $q->withoutGlobalScopes()])
                ->latest('starts_at')->limit(20)->get(),
            'changes' => OnCallChange::withoutGlobalScopes()->where('company_id', $companyId)
                ->with(['schedule' => fn ($q) => $q->withoutGlobalScopes(), 'fromUser', 'toUser', 'actor'])
                ->latest('created_at')->latest('id')->limit(15)->get(),
        ];
    }

    /**
     * Tramos de cobertura de todas las guardias, en orden.
     *
     * @return Collection<int, array{schedule: OnCallSchedule, start: Carbon, end: Carbon, shifts: Collection, needed: int, missing: int}>
     */
    public function segments(Collection $schedules, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $schedules->flatMap(fn (OnCallSchedule $schedule) => collect($this->service->segments($schedule, $from, $to))
            ->map(fn ($s) => $s + ['schedule' => $schedule, 'missing' => max(0, $s['needed'] - $s['shifts']->count())]))
            ->sortBy(fn ($s) => [$s['start']->timestamp, $s['schedule']->name])->values();
    }

    /** El próximo tramo que empieza después de ahora (o después del tramo en curso de cada guardia). */
    private function next(Collection $segments, CarbonInterface $now): ?array
    {
        return $segments->first(fn ($s) => $s['start']->gt($now));
    }

    /** @return list<array{type: string, tone: string, text: string, schedule: ?OnCallSchedule, start: ?Carbon, end: ?Carbon}> */
    private function warnings(int $companyId, Collection $schedules, Collection $upcoming, CarbonInterface $now): array
    {
        $warnings = [];

        if ($schedules->isNotEmpty() && OnCallService::technicians($companyId)->isEmpty()) {
            $warnings[] = ['type' => 'no_technicians', 'tone' => 'danger', 'text' => 'No hay técnicos cargados: para asignar guardias, primero cargá a tus técnicos en «Técnicos».', 'schedule' => null, 'start' => null, 'end' => null];
        }

        // Tramos sin técnico (o con menos de los necesarios), unidos si son seguidos.
        $gaps = [];
        foreach ($upcoming->filter(fn ($s) => $s['missing'] > 0)->values() as $s) {
            $key = array_key_last($gaps);
            if ($key !== null && $gaps[$key]['schedule']->is($s['schedule']) && $gaps[$key]['end']->eq($s['start']) && $gaps[$key]['missing'] === $s['missing']) {
                $gaps[$key]['end'] = $s['end'];
            } else {
                $gaps[] = $s;
            }
        }
        foreach ($gaps as $gap) {
            $text = $gap['shifts']->isEmpty()
                ? 'Sin técnico: '.OnCallText::span($gap['start']->max($now), $gap['end']).' · '.$gap['schedule']->name
                : 'Falta '.($gap['missing'] === 1 ? '1 técnico' : $gap['missing'].' técnicos').': '.OnCallText::span($gap['start']->max($now), $gap['end']).' · '.$gap['schedule']->name;
            $warnings[] = ['type' => 'gap', 'tone' => 'danger', 'text' => $text, 'schedule' => $gap['schedule'], 'start' => $gap['start']->copy()->max($now), 'end' => $gap['end']];
        }

        // El mismo técnico de guardia en dos guardias al mismo tiempo.
        $seen = [];
        $covered = $upcoming->flatMap(fn ($s) => $s['shifts']->map(fn (OnCallShift $shift) => ['user' => $shift->user, 'schedule' => $s['schedule'], 'start' => $s['start'], 'end' => $s['end']]))->values();
        foreach ($covered as $i => $a) {
            foreach ($covered->slice($i + 1) as $b) {
                if ($a['user']->is($b['user']) && ! $a['schedule']->is($b['schedule']) && $a['start']->lt($b['end']) && $b['start']->lt($a['end'])) {
                    $key = $a['user']->id.':'.$a['schedule']->id.':'.$b['schedule']->id;
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $warnings[] = ['type' => 'overlap', 'tone' => 'warning', 'text' => $a['user']->name.' está de guardia en «'.$a['schedule']->name.'» y en «'.$b['schedule']->name.'» al mismo tiempo ('.OnCallText::span($a['start']->max($b['start']), $a['end']->min($b['end'])).'). Revisá que pueda cubrir las dos.', 'schedule' => null, 'start' => null, 'end' => null];
                    }
                }
            }
        }

        // Rotaciones: sin participantes, o que dejan de generar turnos pronto.
        foreach ($schedules->filter(fn (OnCallSchedule $s) => $s->isRotation()) as $schedule) {
            if (! OnCallMember::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->exists()) {
                $warnings[] = ['type' => 'rotation_empty', 'tone' => 'warning', 'text' => 'La rotación de «'.$schedule->name.'» no tiene técnicos: no genera turnos. Agregalos en su configuración.', 'schedule' => $schedule, 'start' => null, 'end' => null];
            } elseif ($schedule->rotation_ends_on && $schedule->rotation_ends_on->lt($now->copy()->addDays(self::UPCOMING_DAYS)->startOfDay())) {
                $warnings[] = ['type' => 'rotation_ending', 'tone' => 'warning', 'text' => 'La rotación de «'.$schedule->name.'» genera turnos hasta el '.$schedule->rotation_ends_on->format('d/m').'. Después, asigná a mano o extendé la fecha en su configuración.', 'schedule' => $schedule, 'start' => null, 'end' => null];
            }
        }

        return $warnings;
    }
}
