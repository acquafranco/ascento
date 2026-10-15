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
 * - Solo genera turnos FUTUROS (que todavía no empezaron), hasta un horizonte.
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

            $now = now()->seconds(0);
            $until = Carbon::parse($until ?? $now->copy()->addDays(self::HORIZON_DAYS));
            $members = OnCallMember::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->orderBy('position')
                ->with(['user' => fn ($q) => $q->withoutGlobalScopes()])->get();
            $changed = [];

            foreach ($this->periods($schedule, $now, $until) as [$k, $start, $end]) {
                $existing = OnCallShift::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->active()
                    ->where('starts_at', '<', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->get();

                // Un cambio hecho a mano en ese período manda: no se recalcula.
                if ($existing->contains(fn (OnCallShift $s) => $s->origin !== 'rotation' || $s->parent_shift_id !== null || ! $s->starts_at->eq($start))) {
                    continue;
                }

                $desired = $this->pick($schedule, $members, $k, $start, $end);

                foreach ($existing as $shift) {
                    if (! in_array((int) $shift->user_id, $desired, true)) {
                        $shift->forceFill(['status' => OnCallShift::CANCELLED, 'closed_at' => now(), 'closed_by' => $actor?->id, 'close_reason' => $reason ?? 'Rotación modificada'])->save();
                        $result['cancelled']++;
                        $changed[] = $shift;
                    }
                }

                foreach ($desired as $userId) {
                    if (! $existing->contains(fn (OnCallShift $s) => (int) $s->user_id === $userId)) {
                        $this->service->createShift($schedule, $userId, $start, $end, 'rotation', null, $actor);
                        $result['created']++;
                    }
                }

                if (count($desired) < (int) $schedule->technicians_needed) {
                    $result['uncovered'][] = ['start' => $start, 'end' => $end];
                }
            }

            if ($result['created'] || $result['cancelled']) {
                $summary = "Rotación: {$result['created']} turnos nuevos, {$result['cancelled']} recalculados"
                    .($result['uncovered'] ? ', '.count($result['uncovered']).' sin técnico disponible' : '').'.';
                $this->service->log($schedule, null, 'rotation_updated', null, null, $now, $until, $reason, $summary, $actor);
            }

            // Quien tenía un turno futuro de la rotación y ya no lo tiene: aviso.
            foreach ($changed as $shift) {
                app(OnCallNotifier::class)->removed($shift, $shift->starts_at, $shift->ends_at, null);
            }

            return $result;
        });
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
