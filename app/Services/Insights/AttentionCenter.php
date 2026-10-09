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
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\Report;
use App\Models\StockItem;
use App\Models\WorkOrder;
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

        return [
            'basic' => $this->basic($company),
            'advanced' => $guard->allows(PlanFeature::AttentionAdvanced) ? $this->advanced() : null,
            'alerts' => $guard->allows(PlanFeature::AdvancedAlerts) ? $this->alerts() : null,
        ];
    }

    private function item(string $severity, string $title, string $detail, int $count, ?string $url, array $examples = []): array
    {
        return compact('severity', 'title', 'detail', 'count', 'url', 'examples');
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
                    Agenda::getUrl(['month' => $previous->format('Y-m'), 'type' => $type, 'status' => 'overdue']),
                    $overdue->take(3)->map(fn ($r) => $r['building']->name)->all()));
            }

            $pending = $this->agenda->rows($now->month, $now->year, $type, status: 'pending');
            $daysLeft = (int) $now->diffInDays($now->copy()->endOfMonth());
            // Solo es un problema cuando el mes se está terminando.
            if ($pending->isNotEmpty() && $now->day >= 20) {
                $items->push($this->item('warning', "{$plural} pendientes del mes", "Quedan {$daysLeft} días para terminar el mes.", $pending->count(),
                    Agenda::getUrl(['type' => $type, 'status' => 'pending']),
                    $pending->take(3)->map(fn ($r) => $r['building']->name)->all()));
            }
        }

        $unassigned = $this->agenda->rows($now->month, $now->year, status: 'unassigned');
        if ($unassigned->isNotEmpty()) {
            $items->push($this->item('danger', 'Edificios con contrato sin técnico asignado', 'Nadie tiene asignado su mantenimiento.', $unassigned->count(),
                Agenda::getUrl(['status' => 'unassigned']), $unassigned->take(3)->map(fn ($r) => $r['building']->name)->all()));
        }

        $stale = WorkOrder::where('status', 'pending')->where('created_at', '<=', $now->copy()->subDays(2))->count();
        if ($stale > 0) {
            $items->push($this->item('warning', 'Órdenes sin tomar hace más de 2 días', 'Ningún técnico empezó estos trabajos.', $stale, WorkOrderResource::getUrl()));
        }

        $urgent = WorkOrder::whereIn('status', ['pending', 'in_progress'])->whereIn('priority', ['high', 'urgent'])->count();
        if ($urgent > 0) {
            $items->push($this->item('danger', 'Órdenes urgentes o de prioridad alta abiertas', 'Todavía no se terminaron.', $urgent, WorkOrderResource::getUrl()));
        }

        $critical = Report::where('status', 'pendiente')->whereIn('priority', ['alta', 'critica'])->count();
        if ($critical > 0) {
            $items->push($this->item('danger', 'Reportes de prioridad alta o crítica sin revisar', 'Los cargaron los técnicos y siguen pendientes.', $critical, ReportResource::getUrl()));
        }

        if (PlanGuard::for($company)->allows(PlanFeature::Quotes)) {
            $expiring = Quote::where('status', Quote::SENT)->whereNotNull('valid_until')
                ->whereBetween('valid_until', [today(), today()->addDays(5)])->count();
            if ($expiring > 0) {
                $items->push($this->item('warning', 'Presupuestos enviados que vencen en 5 días', 'Conviene llamar al cliente antes de que venzan.', $expiring, QuoteResource::getUrl()));
            }
        }

        $low = StockItem::active()->low()->count();
        if ($low > 0) {
            $items->push($this->item('warning', 'Materiales con stock bajo', 'Están en el mínimo o por debajo.', $low, StockItemResource::getUrl()));
        }

        $overdueReceivables = Receivable::overdue()->count();
        if ($overdueReceivables > 0) {
            $items->push($this->item('warning', 'Cobros vencidos', 'Obligaciones con el vencimiento pasado y saldo pendiente.', $overdueReceivables, ReceivableResource::getUrl()));
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
                $first['elevator'] ? ElevatorResource::getUrl('view', ['record' => $first['elevator']]) : ElevatorResource::getUrl(),
                $recurrent->take(3)->pluck('message')->all()));
        }

        $expired = ElevatorDocument::where('type', 'certificate')->whereNotNull('expires_at')->whereDate('expires_at', '<', today())->count();
        $expiring = ElevatorDocument::where('type', 'certificate')->whereNotNull('expires_at')
            ->whereBetween('expires_at', [today(), today()->addDays(30)])->count();
        if ($expired + $expiring > 0) {
            $items->push($this->item($expired > 0 ? 'danger' : 'warning', 'Certificados vencidos o por vencer',
                "{$expired} vencidos y {$expiring} que vencen en los próximos 30 días.", $expired + $expiring, ElevatorResource::getUrl()));
        }

        $incomplete = Elevator::active()->get()->filter(fn (Elevator $e) => $e->missingEssentials() !== [])->count();
        if ($incomplete > 0) {
            $items->push($this->item('info', 'Ascensores con la ficha técnica incompleta',
                'Faltan datos básicos (fabricante, modelo, número de serie, año, capacidad o paradas).', $incomplete,
                ElevatorResource::getUrl('index', ['filters' => ['incomplete' => ['isActive' => true]]])));
        }

        $late = WorkOrder::where('status', 'in_progress')->where('started_at', '<=', now()->subDays(7))->with('users:id,name')->get();
        if ($late->isNotEmpty()) {
            $items->push($this->item('warning', 'Órdenes en curso hace más de 7 días', 'Trabajos empezados que no se cerraron.', $late->count(),
                WorkOrderResource::getUrl(), $late->flatMap->users->pluck('name')->countBy()->map(fn ($n, $name) => "{$name}: {$n}")->values()->take(3)->all()));
        }

        // Edificios con mantenimiento vencido dos meses seguidos.
        $m1 = now()->subMonthNoOverflow();
        $m2 = now()->subMonthsNoOverflow(2);
        $twice = $this->agenda->rows($m1->month, $m1->year, 'maintenance', status: 'overdue')->pluck('building.id')
            ->intersect($this->agenda->rows($m2->month, $m2->year, 'maintenance', status: 'overdue')->pluck('building.id'));
        if ($twice->isNotEmpty()) {
            $items->push($this->item('danger', 'Edificios sin mantenimiento hace dos meses', 'Riesgo operativo: dos meses seguidos sin remito.', $twice->count(),
                Agenda::getUrl(['month' => $m1->format('Y-m'), 'type' => 'maintenance', 'status' => 'overdue'])));
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
            $items->push($this->item('warning', 'Ascensores con fallas en aumento', 'Tuvieron más avisos de falla (reportes y reclamos) que en los 90 días anteriores.', $rising->count(), ElevatorResource::getUrl(),
                $rising->take(3)->map(fn ($r) => $r['label'].' ('.$r['signals'].' vs '.($before->get($r['building_id'].'|'.$r['label'])['signals'] ?? 0).')')->values()->all()));
        }

        $ending = MaintenanceService::active()->whereNotNull('end_date')->whereBetween('end_date', [today(), today()->addDays(30)])->with('client:id,name')->get();
        if ($ending->isNotEmpty()) {
            $items->push($this->item('warning', 'Contratos que terminan en los próximos 30 días', 'Buen momento para renovarlos.', $ending->count(), MaintenanceServiceResource::getUrl(),
                $ending->take(3)->map(fn ($s) => $s->client?->name.' — '.$s->end_date->format('d/m'))->all()));
        }

        $old = Receivable::open()->whereDate('due_date', '<', today()->subDays(60))->with('client:id,name')->get();
        if ($old->isNotEmpty()) {
            $items->push($this->item('danger', 'Deudas vencidas hace más de 60 días', 'Saldo total $'.number_format($old->sum(fn ($r) => $r->balance()), 0, ',', '.').'.', $old->count(), ReceivableResource::getUrl(),
                $old->groupBy('client_id')->map(fn ($g) => $g->first()->client?->name)->filter()->take(3)->values()->all()));
        }

        return $items->values();
    }
}
