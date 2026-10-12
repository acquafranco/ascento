<?php

namespace App\Services\Insights;

use App\Enums\PlanFeature;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Elevators\ElevatorResource;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\Report;
use App\Models\StockItem;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Visits\VisitService;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use Illuminate\Support\Collection;

/**
 * Centro de atención: solo situaciones que piden una acción (no
 * estadísticas). Cada ítem dice qué pasa, cuántos casos y adónde ir.
 *
 * - Básico (todos los planes): lo operativo del día.
 * - Avanzado (Profesional+): reincidencias, documentación y atrasos.
 * - Alertas avanzadas (Empresa): tendencias, contratos y deudas viejas.
 *
 * Solo se calculan las secciones que el plan incluye.
 */
class AttentionCenter
{
    public function __construct(
        private MaintenanceAgenda $agenda,
        private FailureAnalysis $failures,
    ) {}

    /** @return array{basic: Collection, advanced: ?Collection, alerts: ?Collection} */
    public function sections(Company $company): array
    {
        $guard = PlanGuard::for($company);

        $user = auth()->user();

        // Filtrado en el servidor según los permisos de quien mira (visibleTo).
        return [
            'basic' => $this->visibleTo($user, $this->basic($company)),
            'advanced' => $guard->allows(PlanFeature::AttentionAdvanced) ? $this->visibleTo($user, $this->advanced()) : null,
            'alerts' => $guard->allows(PlanFeature::AdvancedAlerts) ? $this->visibleTo($user, $this->alerts()) : null,
        ];
    }

    /**
     * @param  array{url: string, target: class-string}|null  $link  destino (ver link())
     */
    private function item(string $severity, string $title, string $detail, int $count, ?array $link, array $examples = []): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'count' => $count, 'url' => $link['url'] ?? null, 'target' => $link['target'] ?? null, 'examples' => $examples];
    }

    /** Destino de una alerta: la pantalla (que declara su área de permisos) y su URL. */
    private function link(string $target, mixed ...$args): array
    {
        $suffix = $args['suffix'] ?? '';
        unset($args['suffix']);

        return ['url' => $target::getUrl(...$args).$suffix, 'target' => $target];
    }

    /**
     * Lo que puede ver este usuario (Etapa 6). El dueño y el SuperAdmin, todo
     * como siempre. Un administrativo, solo las alertas de sus áreas (el área
     * es la de la pantalla de destino): las demás no aparecen, ni su número ni
     * sus ejemplos. Si no puede abrir el destino, la alerta queda sin enlace.
     */
    public function visibleTo(?User $user, Collection $items): Collection
    {
        return $items
            ->filter(function (array $item) use ($user) {
                $target = $item['target'] ?? null;
                $area = $target && method_exists($target, 'permissionArea') ? $target::permissionArea() : null;

                return ! Access::isStaff($user) || ($area !== null && Access::can($user, $area));
            })
            ->map(function (array $item) {
                $target = $item['target'] ?? null;

                if ($target && ! $target::canAccess()) {
                    $item['url'] = null;
                }

                return $item;
            })
            ->values();
    }

    /** @return Collection<int, array> */
    public function basic(Company $company): Collection
    {
        $items = collect();
        $now = now();
        $previous = $now->copy()->subMonthNoOverflow();

        foreach (MaintenanceAgenda::TYPES as $type => $label) {
            [$plural, $overdueWord] = $type === 'inspection' ? ['Inspecciones', 'vencidas'] : ['Mantenimientos', 'vencidos'];

            $overdue = $this->agenda->rows($previous->month, $previous->year, $type, status: 'overdue');
            if ($overdue->isNotEmpty()) {
                $items->push($this->item('danger', "{$plural} {$overdueWord}", 'Del mes pasado, sin remito firmado.', $overdue->count(),
                    $this->link(Agenda::class, ['month' => $previous->format('Y-m'), 'type' => $type, 'status' => 'overdue']),
                    $overdue->take(3)->map(fn ($r) => $r['building']->name)->all()));
            }

            $pending = $this->agenda->rows($now->month, $now->year, $type, status: 'pending');
            $daysLeft = (int) $now->diffInDays($now->copy()->endOfMonth());
            // Solo es un problema cuando el mes se está terminando.
            if ($pending->isNotEmpty() && $now->day >= 20) {
                $items->push($this->item('warning', "{$plural} pendientes del mes", "Quedan {$daysLeft} días para terminar el mes.", $pending->count(),
                    $this->link(Agenda::class, ['type' => $type, 'status' => 'pending']),
                    $pending->take(3)->map(fn ($r) => $r['building']->name)->all()));
            }
        }

        // Remitos firmados como "no realizado" (este mes y el anterior): hay que volver.
        $notDone = $this->agenda->rows($now->month, $now->year, status: 'not_done')
            ->concat($this->agenda->rows($previous->month, $previous->year, status: 'not_done'));
        if ($notDone->isNotEmpty()) {
            $items->push($this->item('danger', 'Visitas marcadas como no realizadas', 'El técnico firmó el remito pero indicó que el trabajo no se pudo hacer. Para volver, creá una orden de trabajo.', $notDone->count(),
                $this->link(Agenda::class, ['status' => 'not_done']), $notDone->take(3)->map(fn ($r) => $r['building']->name.' ('.MaintenanceAgenda::TYPES[$r['type']].')')->all()));
        }

        // Visitas en curso (empezadas para cargar fotos) sin remito hace más de 7 días.
        $staleVisits = app(VisitService::class)->stale((int) $company->id);
        if ($staleVisits->isNotEmpty()) {
            $items->push($this->item('warning', 'Visitas en curso hace más de '.VisitService::STALE_DAYS.' días', 'Se empezaron y no tienen remito firmado. Abrilas desde la Agenda para continuarlas o descartarlas: no se cierran solas.', $staleVisits->count(),
                $this->link(Agenda::class, suffix: '#visitas-en-curso'), $staleVisits->take(3)->map(fn ($v) => ($v->building?->name ?? '—').' ('.MaintenanceAgenda::TYPES[$v->assignment_type].', desde el '.($v->started_at ?? $v->created_at)->format('d/m').')')->all()));
        }

        $unassigned = $this->agenda->rows($now->month, $now->year, status: 'unassigned');
        if ($unassigned->isNotEmpty()) {
            $items->push($this->item('danger', 'Edificios con contrato sin técnico asignado', 'Nadie tiene asignado su mantenimiento.', $unassigned->count(),
                $this->link(Agenda::class, ['status' => 'unassigned']), $unassigned->take(3)->map(fn ($r) => $r['building']->name)->all()));
        }

        $stale = WorkOrder::where('status', 'pending')->where('created_at', '<=', $now->copy()->subDays(2))->count();
        if ($stale > 0) {
            $items->push($this->item('warning', 'Órdenes sin tomar hace más de 2 días', 'Ningún técnico empezó estos trabajos.', $stale, $this->link(WorkOrderResource::class)));
        }

        $urgent = WorkOrder::whereIn('status', ['pending', 'in_progress'])->whereIn('priority', ['high', 'urgent'])->count();
        if ($urgent > 0) {
            $items->push($this->item('danger', 'Órdenes urgentes o de prioridad alta abiertas', 'Todavía no se terminaron.', $urgent, $this->link(WorkOrderResource::class)));
        }

        $critical = Report::incidents()->where('status', 'pendiente')->whereIn('priority', ['alta', 'critica'])->count();
        if ($critical > 0) {
            $items->push($this->item('danger', 'Reportes de prioridad alta o crítica sin revisar', 'Los cargaron los técnicos y siguen pendientes.', $critical, $this->link(ReportResource::class)));
        }

        if (PlanGuard::for($company)->allows(PlanFeature::Quotes)) {
            $expiring = Quote::where('status', Quote::SENT)->whereNotNull('valid_until')
                ->whereBetween('valid_until', [today(), today()->addDays(5)])->count();
            if ($expiring > 0) {
                $items->push($this->item('warning', 'Presupuestos enviados que vencen en 5 días', 'Conviene llamar al cliente antes de que venzan.', $expiring, $this->link(QuoteResource::class)));
            }
        }

        $low = StockItem::active()->low()->count();
        if ($low > 0) {
            $items->push($this->item('warning', 'Materiales con stock bajo', 'Están en el mínimo o por debajo.', $low, $this->link(StockItemResource::class)));
        }

        // Tareas administrativas vencidas (Etapa 6).
        $overdueTasks = Task::overdue()->count();
        if ($overdueTasks > 0) {
            $items->push($this->item('warning', 'Tareas vencidas', 'Tareas abiertas con el vencimiento pasado.', $overdueTasks, $this->link(TaskResource::class, 'index', ['tab' => 'overdue'])));
        }

        $overdueReceivables = Receivable::overdue()->count();
        if ($overdueReceivables > 0) {
            $items->push($this->item('warning', 'Cobros vencidos', 'Obligaciones con el vencimiento pasado y saldo pendiente.', $overdueReceivables, $this->link(ReceivableResource::class)));
        }

        return $items->sortBy(fn ($i) => $i['severity'] === 'danger' ? 0 : 1)->values();
    }

    /** @return Collection<int, array> */
    public function advanced(): Collection
    {
        $items = collect();

        $recurrent = $this->failures->recurrent();
        if ($recurrent->isNotEmpty()) {
            $first = $recurrent->first();
            $items->push($this->item('danger', 'Ascensores con fallas que se repiten',
                FailureAnalysis::THRESHOLD.' o más avisos de falla (reportes de técnicos o reclamos) en '.FailureAnalysis::DAYS.' días.',
                $recurrent->count(),
                $first['elevator'] ? $this->link(ElevatorResource::class, 'view', ['record' => $first['elevator']]) : $this->link(ElevatorResource::class),
                $recurrent->take(3)->pluck('message')->all()));
        }

        $expired = ElevatorDocument::where('type', 'certificate')->whereNotNull('expires_at')->whereDate('expires_at', '<', today())->count();
        $expiring = ElevatorDocument::where('type', 'certificate')->whereNotNull('expires_at')
            ->whereBetween('expires_at', [today(), today()->addDays(30)])->count();
        if ($expired + $expiring > 0) {
            $items->push($this->item($expired > 0 ? 'danger' : 'warning', 'Certificados vencidos o por vencer',
                "{$expired} vencidos y {$expiring} que vencen en los próximos 30 días.", $expired + $expiring, $this->link(ElevatorResource::class)));
        }

        // Contado en la base (antes se traían todos los ascensores para contarlos).
        $incomplete = Elevator::active()->incomplete()->count();
        if ($incomplete > 0) {
            $items->push($this->item('info', 'Ascensores con la ficha técnica incompleta',
                'Faltan datos básicos (fabricante, modelo, número de serie, año, capacidad o paradas).', $incomplete,
                $this->link(ElevatorResource::class, 'index', ['filters' => ['incomplete' => ['isActive' => true]]])));
        }

        $late = WorkOrder::where('status', 'in_progress')->where('started_at', '<=', now()->subDays(7))->with('users:id,name')->get();
        if ($late->isNotEmpty()) {
            $items->push($this->item('warning', 'Órdenes en curso hace más de 7 días', 'Trabajos empezados que no se cerraron.', $late->count(),
                $this->link(WorkOrderResource::class), $late->flatMap->users->pluck('name')->countBy()->map(fn ($n, $name) => "{$name}: {$n}")->values()->take(3)->all()));
        }

        // Edificios con mantenimiento vencido dos meses seguidos.
        $m1 = now()->subMonthNoOverflow();
        $m2 = now()->subMonthsNoOverflow(2);
        $twice = $this->agenda->rows($m1->month, $m1->year, 'maintenance', status: 'overdue')->pluck('building.id')
            ->intersect($this->agenda->rows($m2->month, $m2->year, 'maintenance', status: 'overdue')->pluck('building.id'));
        if ($twice->isNotEmpty()) {
            $items->push($this->item('danger', 'Edificios sin mantenimiento hace dos meses', 'Riesgo operativo: dos meses seguidos sin remito.', $twice->count(),
                $this->link(Agenda::class, ['month' => $m1->format('Y-m'), 'type' => 'maintenance', 'status' => 'overdue'])));
        }

        return $items->sortBy(fn ($i) => ['danger' => 0, 'warning' => 1, 'info' => 2][$i['severity']])->values();
    }

    /** @return Collection<int, array> */
    public function alerts(): Collection
    {
        $items = collect();

        // Fallas en aumento: últimos 90 días contra los 90 anteriores.
        $now = $this->failures->recurrent(90, 1)->keyBy(fn ($r) => $r['building_id'].'|'.$r['label']);
        $before = $this->failures->recurrent(90, 1, now()->subDays(90))->keyBy(fn ($r) => $r['building_id'].'|'.$r['label']);
        $rising = $now->filter(fn ($r, $key) => $r['signals'] >= 2 && $r['signals'] >= 1.5 * ($before->get($key)['signals'] ?? 0) + 1);
        if ($rising->isNotEmpty()) {
            $items->push($this->item('warning', 'Ascensores con fallas en aumento', 'Tuvieron más avisos de falla (reportes y reclamos) que en los 90 días anteriores.', $rising->count(), $this->link(ElevatorResource::class),
                $rising->take(3)->map(fn ($r) => $r['label'].' ('.$r['signals'].' vs '.($before->get($r['building_id'].'|'.$r['label'])['signals'] ?? 0).')')->values()->all()));
        }

        $ending = MaintenanceService::active()->whereNotNull('end_date')->whereBetween('end_date', [today(), today()->addDays(30)])->with('client:id,name')->get();
        if ($ending->isNotEmpty()) {
            $items->push($this->item('warning', 'Contratos que terminan en los próximos 30 días', 'Buen momento para renovarlos.', $ending->count(), $this->link(MaintenanceServiceResource::class),
                $ending->take(3)->map(fn ($s) => $s->client?->name.' — '.$s->end_date->format('d/m'))->all()));
        }

        $old = Receivable::open()->whereDate('due_date', '<', today()->subDays(60))->with('client:id,name')->get();
        if ($old->isNotEmpty()) {
            $items->push($this->item('danger', 'Deudas vencidas hace más de 60 días', 'Saldo total $'.number_format($old->sum(fn ($r) => $r->balance()), 0, ',', '.').'.', $old->count(), $this->link(ReceivableResource::class),
                $old->groupBy('client_id')->map(fn ($g) => $g->first()->client?->name)->filter()->take(3)->values()->all()));
        }

        return $items->values();
    }
}
