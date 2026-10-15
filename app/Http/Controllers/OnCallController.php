<?php

namespace App\Http\Controllers;

use App\Models\OnCallChange;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

/**
 * Pestaña «Guardias» de la app del técnico: SOLO sus asignaciones (actual,
 * próximas e historial), los cambios que lo afectaron y los trabajos de
 * guardia que tiene asignados. Solo consulta: la planificación la hace la
 * oficina. Todo se filtra por el técnico autenticado y su empresa en el
 * servidor; no hay parámetros que permitan ver a otro técnico.
 */
class OnCallController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = (int) $user->company_id;
        $now = now();

        $mine = fn () => OnCallShift::withoutGlobalScopes()->where('company_id', $companyId)->where('user_id', $user->id)
            ->with(['schedule' => fn ($q) => $q->withoutGlobalScopes(), 'parent.user']);

        // Vigentes (en curso) y próximas.
        $upcoming = $mine()->active()->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('starts_at')->limit(30)->get()
            ->map(fn (OnCallShift $s) => $this->describe($s, $now));

        // De guardia AHORA: el turno está vigente y el horario de la guardia cubre este momento.
        $current = $upcoming->first(fn ($row) => $row['on_duty']);

        // Historial: turnos cumplidos (lo que efectivamente le tocó).
        $history = $mine()->active()->whereNotNull('ends_at')->where('ends_at', '<=', $now)
            ->latest('starts_at')->limit(20)->get();

        // Cambios que lo afectaron (le asignaron, lo reemplazaron, le cancelaron).
        $changes = OnCallChange::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->with(['schedule' => fn ($q) => $q->withoutGlobalScopes(), 'fromUser', 'toUser'])
            ->latest('created_at')->latest('id')->limit(15)->get();

        // Trabajos de guardia que TIENE ASIGNADOS (los ve en «Trabajos»; acá, solo el vínculo).
        $workOrders = WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('on_call_shift_id')
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->with(['building' => fn ($q) => $q->withoutGlobalScopes(), 'deliveryNote', 'onCallShift' => fn ($q) => $q->withoutGlobalScopes(), 'onCallShift.schedule' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest('created_at')->limit(15)->get();

        return view('on-call.index', compact('current', 'upcoming', 'history', 'changes', 'workOrders'));
    }

    /** Turno con su próxima franja y si está cubriendo ahora. */
    private function describe(OnCallShift $shift, $now): array
    {
        $schedule = $shift->schedule ?? new OnCallSchedule;
        $window = $shift->includes($now) ? $schedule->currentWindow($now) : null;

        // Próxima franja dentro del turno (si ahora no está cubriendo).
        $next = null;
        if (! $window) {
            $from = $shift->starts_at->max($now);
            $candidate = $schedule->nextWindow($from);
            if ($candidate && ($shift->ends_at === null || $candidate[0]->lt($shift->ends_at))) {
                $next = [$candidate[0], $shift->ends_at ? $candidate[1]->min($shift->ends_at) : $candidate[1]];
            }
        }

        return [
            'shift' => $shift,
            'schedule' => $schedule,
            'on_duty' => $window !== null,
            'until' => $window ? ($shift->ends_at ? $window[1]->min($shift->ends_at) : $window[1]) : null,
            'next' => $next,
            'started' => $shift->starts_at->lte($now),
        ];
    }
}
