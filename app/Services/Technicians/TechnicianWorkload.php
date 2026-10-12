<?php

namespace App\Services\Technicians;

use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Insights\MaintenanceAgenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que tiene que hacer un técnico, con las MISMAS reglas que los listados:
 * - Pendientes / en curso: órdenes de su empresa en las que está asignado
 *   (work_order_user), como en "Trabajos".
 * - Completadas hoy: terminadas hoy en las que participó (o, en órdenes sin
 *   participantes registrados, en las que estaba asignado).
 * - No realizadas (failed) y canceladas (borradas) no cuentan como completadas.
 * - Visitas del mes: la agenda de mantenimientos e inspecciones de sus
 *   asignaciones (MaintenanceAgenda / VisitSchedule).
 */
class TechnicianWorkload
{
    public const PRIORITY_ORDER = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function assigned(User $user): Builder
    {
        return WorkOrder::query()
            ->where('company_id', $user->company_id)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
    }

    public function completedToday(User $user): Builder
    {
        return WorkOrder::query()
            ->where('company_id', $user->company_id)
            ->where('status', 'completed')
            ->whereDate('finished_at', today())
            ->where(fn ($q) => $q
                ->whereHas('participants', fn ($p) => $p->where('users.id', $user->id))
                ->orWhere(fn ($old) => $old->whereDoesntHave('participants')->whereHas('users', fn ($u) => $u->where('users.id', $user->id))));
    }

    /** @return array{pending: int, in_progress: int, completed_today: int} */
    public function counts(User $user): array
    {
        $byStatus = $this->assigned($user)->whereIn('status', ['pending', 'in_progress'])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
            'completed_today' => $this->completedToday($user)->count(),
        ];
    }

    /** Próximas órdenes: primero las en curso, después por prioridad y antigüedad. */
    public function nextWorkOrders(User $user, int $limit = 4): Collection
    {
        return $this->assigned($user)->whereIn('status', ['in_progress', 'pending'])
            ->with(['building:id,name,address,locality', 'users:id'])
            ->get()
            ->sortBy(fn (WorkOrder $o) => [
                $o->status === 'in_progress' ? 0 : 1,
                self::PRIORITY_ORDER[$o->priority] ?? 9,
                $o->created_at?->timestamp ?? 0,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Mantenimientos e inspecciones del mes de sus asignaciones, contados
     * por visitas previstas (VisitSchedule), igual que la pantalla de Visitas
     * y la Agenda. Un remito "no realizado" no cuenta como realizada.
     *
     * @return array{done: int, not_done: int, pending: int, expected: int, progress: array, next: Collection}
     */
    public function monthVisits(User $user): array
    {
        $rows = app(MaintenanceAgenda::class)->rows(now()->month, now()->year, null, $user->id)
            ->filter(fn ($row) => $row['technicians']->contains('id', $user->id));
        $progress = MaintenanceAgenda::progress($rows);

        return [
            'done' => $progress['total']['done'],
            'not_done' => $progress['total']['not_done'],
            'pending' => $progress['total']['pending'],
            'expected' => $progress['total']['expected'],
            'progress' => $progress,
            'next' => $rows->filter(fn ($row) => $row['pending'] > 0)->take(5)->values(),
        ];
    }

    public function visitsToday(User $user): int
    {
        return BuildingVisit::where('company_id', $user->company_id)
            ->whereDate('visited_at', today())
            ->whereIn('assignment_type', ['maintenance', 'inspection'])
            ->where(fn ($q) => $q
                ->whereHas('participants', fn ($p) => $p->where('users.id', $user->id))
                ->orWhere(fn ($old) => $old->whereDoesntHave('participants')->where('user_id', $user->id)))
            ->distinct('building_id')
            ->count('building_id');
    }

    public function recentDeliveryNotes(User $user, int $limit = 3): Collection
    {
        return DeliveryNote::where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->with('building:id,name,address')
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'number', 'building_id', 'assignment_type', 'performed', 'created_at', 'company_id']);
    }
}
