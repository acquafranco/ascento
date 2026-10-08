<?php

namespace App\Services\Insights;

use App\Enums\PlanFeature;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\BuildingVisit;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Report;
use App\Models\WorkOrder;
use App\Support\ElevatorComponents;
use App\Support\Plans\PlanGuard;
use App\Support\WorkOrderLabels;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Historial operativo de un ascensor, armado con lo que ya existe (no se
 * copia nada): reportes y órdenes del equipo, sus remitos y materiales,
 * presupuestos, y los mantenimientos / inspecciones del edificio.
 *
 * Básico (todos los planes): los últimos BASIC_LIMIT hechos.
 * Avanzado (Profesional+): todo, con filtros por tipo y período y materiales.
 */
class ElevatorHistory
{
    public const BASIC_LIMIT = 15;

    public const TYPES = [
        'report' => 'Reportes',
        'work_order' => 'Órdenes de trabajo',
        'visit' => 'Mantenimientos e inspecciones',
        'quote' => 'Presupuestos',
    ];

    /**
     * @return Collection<int, array{date: CarbonInterface, type: string, title: string, detail: ?string, badge: ?string, url: ?string, building_level?: bool}>
     */
    public function events(Elevator $elevator, bool $advanced, ?string $type = null, ?int $months = null): Collection
    {
        // Sin el plan, los filtros no se aplican (aunque vengan del navegador).
        if (! $advanced) {
            $type = null;
            $months = null;
        }

        $since = $months ? now()->subMonths($months) : null;
        $company = $elevator->building?->company;
        $withQuotes = $company && PlanGuard::for($company)->allows(PlanFeature::Quotes);
        $events = collect();

        if (! $type || $type === 'report') {
            Report::where('building_id', $elevator->building_id)->where('elevator_number', $elevator->label)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->with('user:id,name')->latest()->get()
                ->each(fn (Report $r) => $events->push([
                    'date' => $r->created_at,
                    'type' => 'report',
                    'title' => 'Reporte: '.Str::limit($r->description, 90),
                    'detail' => trim(($r->user?->name ?? '').' · '.ElevatorComponents::label($r->component).' · prioridad '.$r->priority, ' ·'),
                    'badge' => ['pendiente' => 'Pendiente', 'en_revision' => 'En revisión', 'resuelto' => 'Resuelto'][$r->status] ?? $r->status,
                    'url' => ReportResource::getUrl('view', ['record' => $r]),
                ]));
        }

        if (! $type || $type === 'work_order') {
            WorkOrder::where('building_id', $elevator->building_id)->where('unit', $elevator->label)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->with(['users:id,name', 'deliveryNote', 'materials.stockItem'])->latest()->get()
                ->each(function (WorkOrder $o) use ($events, $advanced) {
                    $materials = $advanced && $o->materials->isNotEmpty()
                        ? ' · Materiales: '.$o->materials->map(fn ($m) => $m->stockItem?->name.' × '.rtrim(rtrim((string) $m->quantity, '0'), '.'))->implode(', ')
                        : '';

                    $events->push([
                        'date' => $o->finished_at ?? $o->created_at,
                        'type' => 'work_order',
                        'title' => 'Orden #'.$o->id.': '.WorkOrderLabels::type($o->type),
                        'detail' => trim($o->users->pluck('name')->implode(', ').' · '.ElevatorComponents::label($o->component).($o->deliveryNote ? ' · Remito '.$o->deliveryNote->number : ''), ' ·').$materials,
                        'badge' => WorkOrderLabels::status($o->status),
                        'url' => WorkOrderResource::getUrl('edit', ['record' => $o]),
                    ]);
                });
        }

        if (! $type || $type === 'visit') {
            BuildingVisit::where('building_id', $elevator->building_id)
                ->where('visit_type', 'fixed')
                ->whereIn('assignment_type', ['maintenance', 'inspection'])
                ->when($since, fn ($q) => $q->where('visited_at', '>=', $since))
                ->with(['user:id,name', 'deliveryNote'])->latest('visited_at')->get()
                ->each(fn (BuildingVisit $v) => $events->push([
                    'date' => $v->visited_at,
                    'type' => 'visit',
                    'title' => ($v->assignment_type === 'inspection' ? 'Inspección' : 'Mantenimiento').' mensual del edificio',
                    'detail' => trim(($v->user?->name ?? '').($v->deliveryNote ? ' · Remito '.$v->deliveryNote->number : ''), ' ·'),
                    'badge' => str_pad((string) $v->month, 2, '0', STR_PAD_LEFT).'/'.$v->year,
                    'url' => $v->deliveryNote ? DeliveryNoteResource::getUrl('view', ['record' => $v->deliveryNote]) : null,
                    'building_level' => true,
                ]));
        }

        if ($withQuotes && (! $type || $type === 'quote')) {
            Quote::where('building_id', $elevator->building_id)->where('unit', $elevator->label)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->latest()->get()
                ->each(fn (Quote $q) => $events->push([
                    'date' => $q->created_at,
                    'type' => 'quote',
                    'title' => 'Presupuesto: '.$q->title,
                    'detail' => '$'.number_format((float) $q->amount, 0, ',', '.'),
                    'badge' => $q->displayStatusLabel(),
                    'url' => QuoteResource::getUrl('view', ['record' => $q]),
                ]));
        }

        $events = $events->filter(fn ($e) => $e['date'] !== null)->sortByDesc('date')->values();

        return $advanced ? $events : $events->take(self::BASIC_LIMIT);
    }

    /** Totales del legajo (todos los planes). */
    public function counts(Elevator $elevator): array
    {
        return [
            'reports' => $elevator->reports()->count(),
            'work_orders' => $elevator->workOrders()->count(),
            'claims' => $elevator->workOrders()->where('type', 'claim')->count(),
            'last_visit' => BuildingVisit::where('building_id', $elevator->building_id)->where('visit_type', 'fixed')
                ->where('assignment_type', 'maintenance')->latest('visited_at')->value('visited_at'),
        ];
    }
}
