<?php

namespace App\Services\Technicians;

use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\WorkOrderLabels;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Planilla de un técnico: los trabajos que hizo o en los que participó, día
 * por día. Una sola regla para la app del técnico y para el panel.
 *
 * Quién la ve (canView):
 * - El técnico, solo la suya.
 * - El dueño de la empresa, la de cualquier técnico de su empresa.
 * - El SuperAdmin, la de la empresa que tiene elegida (soporte).
 * - Nadie más: ni otros técnicos, ni administrativos, ni otra empresa.
 *
 * Qué cuenta como trabajo del técnico (igual que antes):
 * - Orden de trabajo: si participó.
 * - Inspección: si la hizo él.
 * - Mantenimiento: si participó.
 * La fecha es la del trabajo (visited_at; en una orden, cuando se terminó).
 * Las visitas «en curso» (sin remito) no son historial y no aparecen.
 */
final class TechnicianSheet
{
    /** ¿$viewer puede ver la planilla de $technician? */
    public static function canView(?User $viewer, User $technician): bool
    {
        if (! $viewer || $technician->trashed() && ! $viewer->isAdmin() && ! $viewer->isSuperAdmin()) {
            return false;
        }

        // Solo personas que hacen trabajos (técnicos y el dueño): no clientes ni administrativos.
        if (! in_array($technician->role, ['technician', 'admin'], true) || $technician->isSuperAdmin() || $technician->company_id === null) {
            return false;
        }

        if ($viewer->isSuperAdmin()) {
            return (int) session('selected_company_id') === (int) $technician->company_id;
        }

        if (Access::isStaff($viewer) || $viewer->company_id === null || (int) $viewer->company_id !== (int) $technician->company_id) {
            return false;
        }

        return (int) $viewer->id === (int) $technician->id || $viewer->isAdmin();
    }

    /** Trabajos del técnico en [$from, $to), más viejos primero. */
    public function visits(User $technician, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $id = (int) $technician->id;
        $participated = fn (Builder $q) => $q->whereHas('participants', fn ($u) => $u->where('users.id', $id));

        return BuildingVisit::withoutGlobalScopes()
            ->where('building_visits.in_progress', false)
            ->where('company_id', $technician->company_id)
            ->whereNotNull('visited_at')
            ->where('visited_at', '>=', $from)->where('visited_at', '<', $to)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $participated($w->where('source', 'work_order')))
                ->orWhere(fn (Builder $w) => $w->where('source', 'building')->where('assignment_type', 'inspection')->where('user_id', $id))
                ->orWhere(fn (Builder $w) => $participated($w->where('source', 'building')->where('assignment_type', 'maintenance'))))
            ->with([
                'building' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()->select('id', 'company_id', 'client_id', 'name', 'address'),
                'building.client' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()->select('id', 'name'),
                'user' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()->select('id', 'name'),
                'participants' => fn ($q) => $q->withoutGlobalScopes()->select('users.id', 'users.name'),
                'deliveryNote' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'company_id', 'building_visit_id', 'number', 'user_id', 'public_token'),
                'workOrder' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'company_id', 'started_at', 'finished_at', 'arrived_at', 'left_at'),
                'workOrder.deliveryNote' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'company_id', 'work_order_id', 'number', 'user_id', 'public_token'),
            ])
            ->orderBy('visited_at')->orderBy('id')
            ->get();
    }

    /**
     * El mes, listo para mostrar: resumen y semanas (lunes a domingo) con los
     * días y sus trabajos.
     *
     * @return array{month: int, year: int, label: string, summary: array, weeks: list<array>, prev: array, next: array}
     */
    public function month(User $technician, int $month, int $year): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->addMonth();
        $jobs = $this->visits($technician, $start, $end)->map(fn (BuildingVisit $v) => $this->job($v, $technician));
        $byDay = $jobs->groupBy(fn ($j) => $j['date']->toDateString());

        $weeks = [];
        for ($monday = $start->copy()->startOfWeek(Carbon::MONDAY); $monday->lt($end); $monday->addWeek()) {
            $days = collect(range(0, 6))->map(fn ($i) => $monday->copy()->addDays($i))
                ->map(fn (Carbon $d) => ['date' => $d, 'inMonth' => $d->month === $month, 'jobs' => $byDay->get($d->toDateString(), collect())->values()]);
            $weeks[] = ['start' => $monday->copy(), 'end' => $monday->copy()->addDays(6), 'days' => $days, 'count' => $days->sum(fn ($d) => $d['jobs']->count())];
        }

        $prev = $start->copy()->subMonth();
        $next = $start->copy()->addMonth();

        return [
            'month' => $month,
            'year' => $year,
            'label' => ucfirst($start->locale('es')->translatedFormat('F Y')),
            'summary' => [
                'total' => $jobs->count(),
                'maintenance' => $jobs->where('kind', 'maintenance')->count(),
                'inspection' => $jobs->where('kind', 'inspection')->count(),
                'work_order' => $jobs->where('kind', 'work_order')->count(),
                'days' => $byDay->count(),
            ],
            'weeks' => $weeks,
            'prev' => ['month' => $prev->month, 'year' => $prev->year],
            'next' => $next->lte(now()->startOfMonth()->addMonth()) ? ['month' => $next->month, 'year' => $next->year] : null,
        ];
    }

    /** Los trabajos de un día. */
    public function day(User $technician, CarbonInterface $date): Collection
    {
        $from = Carbon::parse($date)->startOfDay();

        return $this->visits($technician, $from, $from->copy()->addDay())->map(fn (BuildingVisit $v) => $this->job($v, $technician));
    }

    /** Un trabajo, en palabras (sin notas internas ni costos). */
    private function job(BuildingVisit $visit, User $technician): array
    {
        $kind = $visit->source === 'work_order' ? 'work_order' : (string) $visit->assignment_type;
        $note = $visit->deliveryNote ?? $visit->workOrder?->deliveryNote;
        $mine = (int) $visit->user_id === (int) $technician->id;
        $others = $visit->participants->reject(fn ($p) => (int) $p->id === (int) $technician->id)->pluck('name');

        // Llegada y salida del edificio si se registraron; si no, como antes (tomar trabajo y remito).
        $started = $visit->workOrder?->arrived_at ?? $visit->workOrder?->started_at ?? $visit->started_at;
        $finished = $visit->workOrder?->left_at ?? $visit->workOrder?->finished_at ?? $visit->finished_at;

        return [
            'id' => $visit->id,
            'date' => $visit->visited_at->copy(),
            'kind' => $kind,
            'label' => match ($kind) {
                'work_order' => 'Orden de trabajo'.($visit->work_type ? ' · '.WorkOrderLabels::type((string) $visit->work_type) : ''),
                'inspection' => 'Inspección',
                default => 'Mantenimiento',
            },
            'time' => $kind === 'work_order' && $started
                ? $started->format('H:i').' a '.($finished?->format('H:i') ?? '–')
                : $visit->visited_at->format('H:i'),
            'building' => $visit->building?->name ?? 'Edificio',
            'address' => $visit->building?->address,
            'client' => $visit->building?->client?->name,
            'role' => match (true) {
                $mine && $others->isEmpty() => 'Solo',
                $mine => 'Con '.$others->implode(', '),
                default => 'Participó con '.($visit->user?->name ?? 'otro técnico'),
            },
            'mine' => $mine,
            'note' => $note instanceof DeliveryNote ? $note : null,
        ];
    }
}
