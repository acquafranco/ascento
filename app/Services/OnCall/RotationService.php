<?php

namespace App\Services\OnCall;

use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rotación de técnicos de una guardia: turnos de N días que se alternan en el
 * orden de los participantes, desde la fecha de inicio (cada turno empieza a la
 * hora de inicio de la guardia, así una ventana nunca queda partida).
 *
 * - Solo genera turnos FUTUROS (que todavía no empezaron), hasta un horizonte
 *   de unas semanas o hasta la fecha de fin elegida (rotation_ends_on).
 * - Al cambiar la rotación (orden, participantes, duración, exclusiones) se
 *   recalculan solo los turnos futuros que generó la rotación. Los pasados, los
 *   en curso y los períodos con cambios manuales no se tocan.
 * - Un técnico excluido (o desactivado) se saltea: toma el turno el siguiente
 *   disponible. Si no hay nadie disponible, el período queda sin cobertura (se
 *   informa en «Huecos»); no se inventan asignaciones.
 */
class RotationService
{
    public const HORIZON_DAYS = 56;

    public function __construct(private OnCallService $service) {}

    /**
     * @return array{created: int, cancelled: int, uncovered: list<array{start: Carbon, end: Carbon}>}
     */
    public function sync(OnCallSchedule $schedule, ?User $actor = null, ?CarbonInterface $until = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($schedule, $actor, $until, $reason) {
            $schedule = $this->service->lockSchedule($schedule);
            $result = ['created' => 0, 'cancelled' => 0, 'uncovered' => []];

            if (! $schedule->isRotation() || ! $schedule->is_active || ! $schedule->rotation_starts_on || ! $schedule->rotation_days) {
                return $result;
            }

            $now = now()->startOfMinute();
            $members = OnCallMember::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->orderBy('position')
                ->with(['user' => fn ($q) => $q->withoutGlobalScopes()])->get();
            $changed = [];

            foreach ($this->plan($schedule, $members, $now, $until) as $period) {
                // Un cambio hecho a mano en ese período manda: no se recalcula.
                if ($period['status'] === 'manual') {
                    continue;
                }

                foreach ($period['existing'] as $shift) {
                    if (! in_array((int) $shift->user_id, $period['users'], true)) {
                        $shift->forceFill(['status' => OnCallShift::CANCELLED, 'closed_at' => now(), 'closed_by' => $actor?->id, 'close_reason' => $reason ?? 'Rotación modificada'])->save();
                        $result['cancelled']++;
                        $changed[] = $shift;
                    }
                }

                foreach ($period['users'] as $userId) {
                    if (! $period['existing']->contains(fn (OnCallShift $s) => (int) $s->user_id === $userId)) {
                        $this->service->createShift($schedule, $userId, $period['start'], $period['end'], 'rotation', null, $actor);
                        $result['created']++;
                    }
                }

                if (count($period['users']) < (int) $schedule->technicians_needed) {
                    $result['uncovered'][] = ['start' => $period['start'], 'end' => $period['end']];
                }
            }

            if ($result['created'] || $result['cancelled']) {
                $summary = "Rotación: {$result['created']} turnos nuevos, {$result['cancelled']} recalculados"
                    .($result['uncovered'] ? ', '.count($result['uncovered']).' sin técnico disponible' : '').'.';
                $this->service->log($schedule, null, 'rotation_updated', null, null, $now, $this->until($schedule, $now, $until), $reason, $summary, $actor);
            }

            // Quien tenía un turno futuro de la rotación y ya no lo tiene: aviso.
            foreach ($changed as $shift) {
                app(OnCallNotifier::class)->removed($shift, $shift->starts_at, $shift->ends_at, null);
            }

            return $result;
        });
    }

    /**
     * Lo que la rotación generaría desde $now (sin guardar nada): cada turno con
     * quién lo cubriría y qué pasa con lo ya generado. Sirve para la vista previa
     * del formulario (también con datos sin guardar) y para sync().
     *
     * status: new (turno nuevo), same (ya está así), change (se recalcula y se
     * avisa), manual (tiene un cambio hecho a mano: no se toca), uncovered
     * (nadie disponible).
     *
     * @param  Collection<int, OnCallMember>  $members  en orden, con su usuario cargado
     * @return list<array{start: Carbon, end: Carbon, users: list<int>, status: string, existing: Collection<int, OnCallShift>}>
     */
    public function plan(OnCallSchedule $schedule, Collection $members, CarbonInterface $now, ?CarbonInterface $until = null): array
    {
        if (! $schedule->rotation_starts_on || ! $schedule->rotation_days) {
            return [];
        }

        $members = $members->values();
        $plan = [];
        foreach ($this->periods($schedule, $now, $this->until($schedule, $now, $until)) as [$k, $start, $end]) {
            $existing = $schedule->exists
                ? OnCallShift::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->active()
                    ->where('starts_at', '<', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->get()
                : collect();
            $users = $this->pick($schedule, $members, $k, $start, $end);

            $manual = $existing->contains(fn (OnCallShift $s) => $s->origin !== 'rotation' || $s->parent_shift_id !== null || ! $s->starts_at->eq($start));
            $current = $existing->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $wanted = collect($users)->sort()->values()->all();

            $status = match (true) {
                $manual => 'manual',
                $users === [] => 'uncovered',
                $existing->isEmpty() => 'new',
                $current === $wanted => 'same',
                default => 'change',
            };

            $plan[] = ['start' => $start, 'end' => $end, 'users' => $manual ? $current : $users, 'status' => $status, 'existing' => $existing];
        }

        return $plan;
    }

    /** Hasta dónde se generan turnos: el horizonte de unas semanas o el fin elegido, lo que llegue antes. */
    public function until(OnCallSchedule $schedule, CarbonInterface $now, ?CarbonInterface $until = null): Carbon
    {
        $until = Carbon::parse($until ?? $now->copy()->addDays(self::HORIZON_DAYS));
        if ($schedule->rotation_ends_on) {
            $until = $until->min($schedule->rotation_ends_on->copy()->addDay()->startOfDay());
        }

        return $until;
    }

    /**
     * Períodos de la rotación que empiezan después de $from y antes de $until.
     *
     * @return list<array{0: int, 1: Carbon, 2: Carbon}> [índice, inicio, fin]
     */
    public function periods(OnCallSchedule $schedule, CarbonInterface $from, CarbonInterface $until): array
    {
        $anchor = Carbon::parse($schedule->rotation_starts_on->format('Y-m-d').' '.$schedule->starts_time);
        $days = max(1, (int) $schedule->rotation_days);
        $k = $anchor->gte($from) ? 0 : (int) floor($anchor->diffInDays($from) / $days);
        $periods = [];

        for (; $k < 10000; $k++) {
            $start = $anchor->copy()->addDays($k * $days);
            if ($start->gte($until)) {
                break;
            }
            if ($start->gt($from)) {
                $periods[] = [$k, $start, $start->copy()->addDays($days)];
            }
        }

        return $periods;
    }

    /**
     * Técnicos del período $k: en orden, $needed por turno; se saltean los
     * excluidos, los desactivados y los que ya no son técnicos de la empresa.
     *
     * @return list<int>
     */
    public function pick(OnCallSchedule $schedule, Collection $members, int $k, CarbonInterface $start, CarbonInterface $end): array
    {
        $n = $members->count();
        $needed = (int) $schedule->technicians_needed;
        if ($n === 0) {
            return [];
        }

        $available = fn (OnCallMember $m) => $m->user && ! $m->user->trashed() && $m->user->role === 'technician'
            && (int) $m->user->company_id === (int) $schedule->company_id && ! $m->isExcludedBetween($start, $end);

        $chosen = [];
        $base = ($k * $needed) % $n;
        for ($i = 0; $i < $n && count($chosen) < $needed; $i++) {
            $member = $members[($base + $i) % $n];
            if ($available($member) && ! in_array((int) $member->user_id, $chosen, true)) {
                $chosen[] = (int) $member->user_id;
            }
        }

        return $chosen;
    }
}
