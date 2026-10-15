<?php

namespace App\Services\OnCall;

use App\Models\OnCallChange;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único punto que crea y cambia turnos de guardia.
 *
 * Reglas:
 * - Un turno nunca se borra ni se reescribe: un cambio lo marca «modificado» o
 *   «cancelado» y crea los turnos resultantes (antes / reemplazo / después).
 *   Lo que ya ocurrió sigue a nombre de quien estaba asignado, y las órdenes de
 *   trabajo vinculadas a un turno siguen apuntando a ese turno.
 * - El pasado no se modifica: un cambio empieza ahora o más adelante.
 * - Cada guardia indica cuántos técnicos cubren a la vez; nunca se asignan más
 *   (ni el mismo técnico dos veces en el mismo período de la misma guardia).
 * - Todo dentro de una transacción con bloqueo de la guardia: dos cambios
 *   simultáneos no se pisan ni dejan superposiciones.
 * - Cada cambio queda en on_call_changes (quién, cuándo, qué período, motivo).
 */
class OnCallService
{
    public function __construct(private OnCallNotifier $notifier) {}

    /** Técnicos activos de la empresa que pueden cubrir guardias. */
    public static function technicians(?int $companyId = null): Collection
    {
        return User::query()
            ->where('company_id', $companyId ?? CompanyContext::currentId())
            ->where('role', 'technician')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Asignación manual: un técnico durante un período ($to null = permanente). */
    public function assign(OnCallSchedule $schedule, User $user, CarbonInterface $from, ?CarbonInterface $to, ?User $actor = null, ?string $reason = null): OnCallShift
    {
        $from = Carbon::parse($from)->seconds(0);
        $to = $to ? Carbon::parse($to)->seconds(0) : null;

        return DB::transaction(function () use ($schedule, $user, $from, $to, $actor, $reason) {
            $schedule = $this->lockSchedule($schedule);
            $this->assertTechnician($schedule, $user);

            if ($to && $to->lte($from)) {
                throw ValidationException::withMessages(['ends_at' => 'El fin tiene que ser posterior al inicio.']);
            }
            if (! $schedule->is_active) {
                throw ValidationException::withMessages(['user_id' => 'La guardia está finalizada: no admite nuevas asignaciones.']);
            }

            $this->assertCapacity($schedule, (int) $user->id, $from, $to);

            $shift = $this->createShift($schedule, (int) $user->id, $from, $to, 'manual', null, $actor);
            $this->log($schedule, $shift, 'assigned', null, (int) $user->id, $from, $to, $reason, null, $actor);
            $this->notifier->assigned($shift);

            return $shift;
        });
    }

    /**
     * Cambio de guardia sobre [$from, $to) de un turno: lo cubre $newUser, o
     * nadie si es null (cancelación de esa parte). $to null = hasta el fin del turno.
     *
     * @return list<OnCallShift> turnos vigentes resultantes
     */
    public function replace(OnCallShift $shift, CarbonInterface $from, ?CarbonInterface $to, ?User $newUser, ?User $actor = null, ?string $reason = null): array
    {
        $from = Carbon::parse($from)->seconds(0);
        $to = $to ? Carbon::parse($to)->seconds(0) : null;

        return DB::transaction(function () use ($shift, $from, $to, $newUser, $actor, $reason) {
            $schedule = $this->lockSchedule($shift->schedule()->withoutGlobalScopes()->firstOrFail());
            $shift = OnCallShift::withoutGlobalScopes()->lockForUpdate()->findOrFail($shift->id);

            if (! $shift->isActive()) {
                throw ValidationException::withMessages(['shift' => 'Ese turno ya fue modificado o cancelado. Actualizá la pantalla.']);
            }

            $end = $shift->ends_at;
            $to ??= $end;
            $now = now()->seconds(0);

            if ($from->lt($shift->starts_at) || ($end && $from->gte($end))) {
                throw ValidationException::withMessages(['from' => 'El cambio tiene que estar dentro del turno ('.$shift->periodLabel().').']);
            }
            if ($from->lt($now)) {
                throw ValidationException::withMessages(['from' => 'Lo que ya pasó no se modifica: el cambio tiene que empezar ahora o más adelante.']);
            }
            if ($to && ($to->lte($from) || ($end && $to->gt($end)))) {
                throw ValidationException::withMessages(['to' => 'El fin del cambio tiene que ser posterior al inicio y no pasar del fin del turno.']);
            }
            if ($newUser) {
                $this->assertTechnician($schedule, $newUser);
                if ((int) $newUser->id === (int) $shift->user_id) {
                    throw ValidationException::withMessages(['user_id' => 'Elegí otro técnico: ese ya es el asignado.']);
                }
                $this->assertCapacity($schedule, (int) $newUser->id, $from, $to, [$shift->id]);
            }

            $whole = $from->eq($shift->starts_at) && (($to === null && $end === null) || ($to && $end && $to->eq($end)));
            $shift->forceFill([
                'status' => $whole && ! $newUser ? OnCallShift::CANCELLED : OnCallShift::REPLACED,
                'closed_at' => now(), 'closed_by' => $actor?->id, 'close_reason' => $reason,
            ])->save();

            $result = [];
            if ($from->gt($shift->starts_at)) {
                $result[] = $this->createShift($schedule, (int) $shift->user_id, $shift->starts_at, $from, $shift->origin, $shift->id, $actor, $shift->reminded_at);
            }
            if ($newUser) {
                $result[] = $replacement = $this->createShift($schedule, (int) $newUser->id, $from, $to, 'replacement', $shift->id, $actor);
            }
            if ($to && ($end === null || $to->lt($end))) {
                $result[] = $this->createShift($schedule, (int) $shift->user_id, $to, $end, $shift->origin, $shift->id, $actor);
            }

            $action = $newUser ? 'replaced' : ($whole ? 'cancelled' : ($to === null || ($end && $to->eq($end)) ? 'ended' : 'cancelled'));
            $this->log($schedule, $shift, $action, (int) $shift->user_id, $newUser?->id, $from, $to, $reason, null, $actor);

            $this->notifier->removed($shift, $from, $to, $newUser);
            if (isset($replacement)) {
                $this->notifier->assigned($replacement, replacing: $shift);
            }

            return $result;
        });
    }

    /** Cancelar un turno que todavía no empezó. */
    public function cancel(OnCallShift $shift, ?User $actor = null, ?string $reason = null): void
    {
        if ($shift->starts_at->lte(now())) {
            throw ValidationException::withMessages(['shift' => 'Ese turno ya empezó: no se cancela, se finaliza (desde ahora) o se cambia el técnico desde ahora.']);
        }

        $this->replace($shift, $shift->starts_at, $shift->ends_at, null, $actor, $reason);
    }

    /** Finalizar un turno (por ejemplo, uno permanente) a partir de $at (ahora o después). */
    public function endAt(OnCallShift $shift, CarbonInterface $at, ?User $actor = null, ?string $reason = null): void
    {
        $at = Carbon::parse($at)->seconds(0)->max(now()->seconds(0));
        if ($shift->ends_at && $at->gte($shift->ends_at)) {
            throw ValidationException::withMessages(['at' => 'El turno ya termina antes de esa fecha.']);
        }

        $this->replace($shift, $at->max($shift->starts_at), $shift->ends_at, null, $actor, $reason);
    }

    /** Finaliza la guardia: cancela los turnos futuros y corta los vigentes desde ahora. Conserva todo el historial. */
    public function finishSchedule(OnCallSchedule $schedule, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($schedule, $actor, $reason) {
            $schedule = $this->lockSchedule($schedule);
            $now = now()->seconds(0);

            $shifts = OnCallShift::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->active()
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->get();

            foreach ($shifts as $shift) {
                $shift->starts_at->gt($now)
                    ? $this->replace($shift, $shift->starts_at, $shift->ends_at, null, $actor, $reason ?? 'Guardia finalizada')
                    : $this->replace($shift, $now->max($shift->starts_at), $shift->ends_at, null, $actor, $reason ?? 'Guardia finalizada');
            }

            $schedule->forceFill(['is_active' => false])->save();
            $this->log($schedule, null, 'schedule_finished', null, null, $now, null, $reason, 'Guardia finalizada: sin turnos desde ahora.', $actor);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAS
    |--------------------------------------------------------------------------
    */

    /**
     * Quién está de guardia en un momento (por guardia). Incluye las guardias cuyo
     * horario cubre ese momento pero no tienen técnico (sin cobertura).
     *
     * @return list<array{schedule: OnCallSchedule, shifts: Collection<int, OnCallShift>, window: array, needed: int}>
     */
    public function onDutyAt(int $companyId, CarbonInterface $at): array
    {
        $rows = [];
        $schedules = OnCallSchedule::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('name')->get();

        foreach ($schedules as $schedule) {
            $window = $schedule->currentWindow($at);
            if (! $window) {
                continue;
            }

            $shifts = $this->shiftsAt($schedule, $at);
            if ($shifts->isEmpty() && ! $schedule->is_active) {
                continue;
            }

            $rows[] = ['schedule' => $schedule, 'shifts' => $shifts, 'window' => $window, 'needed' => (int) $schedule->technicians_needed];
        }

        return $rows;
    }

    /** Turnos vigentes de una guardia en un momento (sin mirar el horario). */
    public function shiftsAt(OnCallSchedule $schedule, CarbonInterface $at): Collection
    {
        return OnCallShift::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->active()
            ->where('starts_at', '<=', $at)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->with(['user' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('starts_at')->get()
            ->filter(fn (OnCallShift $s) => $s->user && ! $s->user->trashed())
            ->values();
    }

    /**
     * Huecos de cobertura entre $from y $to: tramos de las ventanas con menos técnicos
     * de los necesarios (0 = sin cobertura). Un técnico desactivado no cubre.
     *
     * @return list<array{start: Carbon, end: Carbon, assigned: int, needed: int}>
     */
    public function gaps(OnCallSchedule $schedule, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $schedule->is_active) {
            return [];
        }

        $needed = (int) $schedule->technicians_needed;
        $shifts = OnCallShift::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->active()
            ->overlapping($from, $to)->with(['user' => fn ($q) => $q->withoutGlobalScopes()])->get()
            ->filter(fn (OnCallShift $s) => $s->user && ! $s->user->trashed());
        $gaps = [];

        foreach ($schedule->windowsBetween($from, $to) as [$wStart, $wEnd]) {
            $wStart = $wStart->max($from);
            $wEnd = $wEnd->min($to);
            $points = collect([$wStart, $wEnd]);
            foreach ($shifts as $s) {
                foreach ([$s->starts_at, $s->ends_at] as $p) {
                    if ($p && $p->gt($wStart) && $p->lt($wEnd)) {
                        $points->push($p->copy());
                    }
                }
            }
            $points = $points->unique(fn ($p) => $p->timestamp)->sortBy(fn ($p) => $p->timestamp)->values();

            for ($i = 0; $i < $points->count() - 1; $i++) {
                [$a, $b] = [$points[$i], $points[$i + 1]];
                $count = $shifts->filter(fn (OnCallShift $s) => $s->includes($a))->count();
                if ($count >= $needed) {
                    continue;
                }
                $last = end($gaps);
                if ($last && $last['end']->eq($a) && $last['assigned'] === $count) {
                    $gaps[array_key_last($gaps)]['end'] = $b->copy();
                } else {
                    $gaps[] = ['start' => $a->copy(), 'end' => $b->copy(), 'assigned' => $count, 'needed' => $needed];
                }
            }
        }

        return $gaps;
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNOS
    |--------------------------------------------------------------------------
    */

    public function lockSchedule(OnCallSchedule $schedule): OnCallSchedule
    {
        $locked = OnCallSchedule::withoutGlobalScopes()->lockForUpdate()->findOrFail($schedule->id);
        $context = CompanyContext::currentId();
        if ($context !== null && $context !== (int) $locked->company_id) {
            throw ValidationException::withMessages(['schedule' => 'Esa guardia no es de tu empresa.']);
        }

        return $locked;
    }

    public function assertTechnician(OnCallSchedule $schedule, User $user): void
    {
        $fresh = User::withoutGlobalScopes()->find($user->id);
        if (! $fresh || (int) $fresh->company_id !== (int) $schedule->company_id || $fresh->role !== 'technician' || $fresh->trashed()) {
            throw ValidationException::withMessages(['user_id' => 'Elegí un técnico activo de la empresa.']);
        }
    }

    /**
     * No más técnicos a la vez de los que la guardia admite, ni el mismo técnico dos veces.
     *
     * @param  list<int>  $ignore  turnos que se están reemplazando
     */
    public function assertCapacity(OnCallSchedule $schedule, int $userId, CarbonInterface $from, ?CarbonInterface $to, array $ignore = []): void
    {
        $overlapping = OnCallShift::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->active()
            ->whereNotIn('id', $ignore ?: [0])->overlapping($from, $to)->get();

        if ($overlapping->contains('user_id', $userId)) {
            throw ValidationException::withMessages(['user_id' => 'Ese técnico ya está asignado a esta guardia en parte de ese período.']);
        }

        // Máximo de turnos simultáneos dentro de [$from, $to).
        $max = 0;
        $points = collect([$from])->merge($overlapping->pluck('starts_at')->filter(fn ($p) => $p->gt($from) && (! $to || $p->lt($to))));
        foreach ($points as $p) {
            $max = max($max, $overlapping->filter(fn (OnCallShift $s) => $s->includes($p))->count());
        }

        if ($max + 1 > (int) $schedule->technicians_needed) {
            $n = (int) $schedule->technicians_needed;
            throw ValidationException::withMessages(['user_id' => "Esta guardia admite {$n} ".($n === 1 ? 'técnico' : 'técnicos').' a la vez y ese período ya está cubierto. Para cambiar al técnico, usá «Cambiar técnico» sobre su turno.']);
        }
    }

    public function createShift(OnCallSchedule $schedule, int $userId, CarbonInterface $from, ?CarbonInterface $to, string $origin, ?int $parentId, ?User $actor, ?CarbonInterface $remindedAt = null): OnCallShift
    {
        $shift = new OnCallShift;
        $shift->forceFill([
            'company_id' => $schedule->company_id,
            'on_call_schedule_id' => $schedule->id,
            'user_id' => $userId,
            'starts_at' => $from,
            'ends_at' => $to,
            'status' => OnCallShift::ACTIVE,
            'origin' => $origin,
            'parent_shift_id' => $parentId,
            'created_by' => $actor?->id,
            'reminded_at' => $remindedAt,
        ])->save();

        return $shift;
    }

    public function log(OnCallSchedule $schedule, ?OnCallShift $shift, string $action, ?int $fromUser, ?int $toUser, ?CarbonInterface $start, ?CarbonInterface $end, ?string $reason, ?string $summary, ?User $actor): OnCallChange
    {
        $change = new OnCallChange;
        $change->forceFill([
            'company_id' => $schedule->company_id,
            'on_call_schedule_id' => $schedule->id,
            'on_call_shift_id' => $shift?->id,
            'action' => $action,
            'from_user_id' => $fromUser,
            'to_user_id' => $toUser,
            'period_start' => $start,
            'period_end' => $end,
            'reason' => $reason ? mb_substr(trim($reason), 0, 500) : null,
            'summary' => $summary ? mb_substr($summary, 0, 500) : null,
            'user_id' => $actor?->id,
            'created_at' => now(),
        ])->save();

        return $change;
    }
}
