<?php

namespace App\Services\Crm;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Elevators\ElevatorResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use App\Support\WorkOrderLabels;
use Illuminate\Support\Collection;

/**
 * Ficha CRM de un cliente (Etapa 6, PR 4): lo comercial y lo operativo del
 * cliente en un solo lugar, leyendo de los módulos que ya existen (no copia
 * datos). Cada sección se arma SOLO si el usuario tiene «ver» en su área:
 * una sección sin permiso no se consulta ni se devuelve (ni sus números en
 * el resumen ni sus eventos en la actividad). Los enlaces aparecen solo si
 * el usuario puede abrir el destino. Todo filtrado por la empresa del
 * cliente.
 */
class ClientDossier
{
    private const RECENT = 5;

    private Company $company;

    /** @return array<string, mixed> */
    public function for(Client $client, ?User $user): array
    {
        $companyId = (int) $client->company_id;
        $this->company = Company::findOrFail($companyId);
        $can = fn (PermissionArea $area) => Access::can($user, $area);
        $buildingIds = Building::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', $client->id)->pluck('id');

        $sections = [
            'buildings' => $can(PermissionArea::Buildings) ? $this->buildings($companyId, $buildingIds) : null,
            'quotes' => $can(PermissionArea::Quotes) && $this->company->plan()->allows(PlanFeature::Quotes) ? $this->quotes($companyId, $client, $buildingIds) : null,
            'work_orders' => $can(PermissionArea::WorkOrders) ? $this->workOrders($companyId, $buildingIds) : null,
            'delivery_notes' => $can(PermissionArea::Documents) ? $this->deliveryNotes($companyId, $buildingIds) : null,
            'receivables' => $can(PermissionArea::Receivables) ? $this->receivables($companyId, $client) : null,
            'tasks' => $can(PermissionArea::Tasks) && $this->company->plan()->allows(PlanFeature::Tasks) ? $this->tasks($companyId, $client, $buildingIds) : null,
            'contacts' => $can(PermissionArea::Contacts) && $this->company->plan()->allows(PlanFeature::ClientContacts) ? $this->contacts($companyId, $client) : null,
        ];

        return [
            'client' => $client,
            'summary' => $this->summary($sections),
            'activity' => $this->activity($sections),
            ...$sections,
        ];
    }

    /** Enlace solo si el usuario puede abrir el destino (y el plan lo incluye): nunca un 403. */
    private function link(string $resource, string $page, mixed $record, ?PlanFeature $feature = null): ?string
    {
        if ($feature && ! PlanGuard::for($this->company)->allows($feature)) {
            return null;
        }

        $allowed = match ($page) {
            'view' => $resource::canView($record),
            'edit' => $resource::canEdit($record),
            default => $resource::canAccess(),
        };

        return $allowed ? $resource::getUrl($page, ['record' => $record]) : null;
    }

    private function buildings(int $companyId, Collection $ids): array
    {
        // Los edificios desactivados no se listan, pero su historial (órdenes, remitos) sigue contando.
        $buildings = Building::withoutGlobalScopes()->whereIn('id', $ids)->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')->get();
        $elevators = Elevator::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('building_id', $buildings->pluck('id'))->orderBy('label')->get()->groupBy('building_id');

        return [
            'count' => $buildings->count(),
            'elevators' => $elevators->flatten()->count(),
            'items' => $buildings->map(fn (Building $b) => [
                'name' => $b->name,
                'address' => trim(($b->address ?? '').' '.($b->locality ? '· '.$b->locality : '')),
                'active' => (bool) $b->is_active,
                'url' => $this->link(BuildingResource::class, 'edit', $b),
                'elevators' => ($elevators[$b->id] ?? collect())->map(fn (Elevator $e) => [
                    'label' => $e->label, 'url' => $this->link(ElevatorResource::class, 'view', $e, PlanFeature::ElevatorFile),
                ])->values()->all(),
            ])->all(),
        ];
    }

    private function quotes(int $companyId, Client $client, Collection $buildingIds): array
    {
        $base = Quote::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhereIn('building_id', $buildingIds));
        $pending = (clone $base)->awaitingResponse();

        return [
            'pending_count' => (clone $pending)->count(),
            'pending_amount' => (float) (clone $pending)->sum('amount'),
            'approved_amount' => (float) (clone $base)->where('status', Quote::APPROVED)->sum('amount'),
            'items' => (clone $base)->latest()->limit(self::RECENT)->get()->map(fn (Quote $q) => [
                'date' => $q->created_at, 'label' => $q->numberLabel().' · '.$q->title, 'amount' => (float) $q->amount,
                'status' => $q->isExpired() ? 'Vencido' : (Quote::STATUSES[$q->status] ?? $q->status),
                'url' => $this->link(QuoteResource::class, 'view', $q, PlanFeature::Quotes),
            ])->all(),
        ];
    }

    private function workOrders(int $companyId, Collection $buildingIds): array
    {
        $base = WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('building_id', $buildingIds);

        return [
            'open_count' => (clone $base)->whereIn('status', ['pending', 'in_progress'])->count(),
            'open_claims' => (clone $base)->whereIn('status', ['pending', 'in_progress'])->where('type', 'claim')->count(),
            'items' => (clone $base)->with('building:id,name')->latest()->limit(self::RECENT)->get()->map(fn (WorkOrder $o) => [
                'date' => $o->created_at, 'label' => '#'.$o->id.' · '.WorkOrderLabels::type((string) $o->type).' · '.($o->building?->name ?? ''),
                'status' => WorkOrderLabels::status((string) $o->status), 'finished_at' => $o->finished_at,
                'url' => $this->link(WorkOrderResource::class, 'edit', $o),
            ])->all(),
        ];
    }

    private function deliveryNotes(int $companyId, Collection $buildingIds): array
    {
        $types = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Orden de trabajo'];
        $base = DeliveryNote::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('building_id', $buildingIds);

        return [
            'count' => (clone $base)->count(),
            'items' => (clone $base)->with('building:id,name')->latest()->limit(self::RECENT)->get()->map(fn (DeliveryNote $n) => [
                'date' => $n->created_at, 'label' => '#'.$n->number.' · '.($types[$n->assignment_type] ?? $n->assignment_type).' · '.($n->building?->name ?? ''),
                'status' => $n->performed === false ? 'No realizado' : 'Realizado',
                'url' => $this->link(DeliveryNoteResource::class, 'view', $n),
            ])->all(),
        ];
    }

    private function receivables(int $companyId, Client $client): array
    {
        $base = Receivable::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', $client->id);
        $open = (clone $base)->whereIn('status', [Receivable::PENDING, Receivable::PARTIAL])->get();

        return [
            'balance' => round($open->sum(fn (Receivable $r) => $r->balance()), 2),
            'overdue' => round($open->filter(fn (Receivable $r) => $r->isOverdue())->sum(fn (Receivable $r) => $r->balance()), 2),
            'items' => (clone $base)->latest('due_date')->limit(self::RECENT)->get()->map(fn (Receivable $r) => [
                'date' => $r->due_date, 'label' => $r->concept, 'amount' => (float) $r->amount, 'balance' => $r->balance(),
                'status' => $r->displayStatusLabel(), 'url' => $this->link(ReceivableResource::class, 'view', $r),
            ])->all(),
            'payments' => ReceivablePayment::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereIn('receivable_id', (clone $base)->pluck('id'))->latest('paid_at')->limit(self::RECENT)->get()
                ->map(fn (ReceivablePayment $p) => ['date' => $p->paid_at, 'amount' => (float) $p->amount])->all(),
        ];
    }

    private function tasks(int $companyId, Client $client, Collection $buildingIds): array
    {
        $base = Task::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhereIn('building_id', $buildingIds));

        return [
            'open_count' => (clone $base)->whereIn('status', Task::OPEN)->count(),
            'items' => (clone $base)->whereIn('status', Task::OPEN)->with('assignee:id,name')->orderByRaw('due_at is null')->orderBy('due_at')->limit(self::RECENT)->get()->map(fn (Task $t) => [
                'date' => $t->due_at, 'label' => $t->title, 'overdue' => $t->isOverdue(),
                'status' => $t->isOverdue() ? 'Vencida' : (Task::STATUSES[$t->status] ?? $t->status), 'assignee' => $t->assignee?->name,
                'url' => $this->link(TaskResource::class, 'edit', $t),
            ])->all(),
            'done' => (clone $base)->where('status', 'done')->latest('completed_at')->limit(self::RECENT)->get()
                ->map(fn (Task $t) => ['date' => $t->completed_at, 'label' => $t->title])->all(),
        ];
    }

    private function contacts(int $companyId, Client $client): array
    {
        $base = ClientInteraction::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', $client->id);
        $last = (clone $base)->latest('occurred_at')->first();

        return [
            'count' => (clone $base)->count(),
            'last' => $last ? ['date' => $last->occurred_at, 'type' => ClientInteraction::TYPES[$last->type] ?? $last->type, 'result' => ClientInteraction::RESULTS[$last->result] ?? $last->result] : null,
            'follow_up' => (clone $base)->needsFollowUp()->count(),
            'items' => (clone $base)->latest('occurred_at')->limit(self::RECENT)->get()->map(fn (ClientInteraction $i) => [
                'date' => $i->occurred_at, 'label' => (ClientInteraction::TYPES[$i->type] ?? $i->type).' · '.(ClientInteraction::RESULTS[$i->result] ?? $i->result),
                'notes' => $i->notes, 'by' => $i->user_name,
            ])->all(),
        ];
    }

    /** Números del resumen: solo de las secciones permitidas. */
    private function summary(array $s): array
    {
        return array_filter([
            'buildings' => $s['buildings'] ? ['label' => 'Edificios', 'value' => $s['buildings']['count'], 'hint' => $s['buildings']['elevators'].' ascensores'] : null,
            'quotes' => $s['quotes'] ? ['label' => 'Presupuestos pendientes', 'value' => $s['quotes']['pending_count'], 'hint' => $s['quotes']['pending_count'] ? '$ '.number_format($s['quotes']['pending_amount'], 0, ',', '.') : null] : null,
            'work_orders' => $s['work_orders'] ? ['label' => 'Órdenes abiertas', 'value' => $s['work_orders']['open_count'], 'hint' => $s['work_orders']['open_claims'] ? $s['work_orders']['open_claims'].' reclamos' : null] : null,
            'receivables' => $s['receivables'] ? ['label' => 'Saldo a cobrar', 'value' => '$ '.number_format($s['receivables']['balance'], 0, ',', '.'), 'hint' => $s['receivables']['overdue'] > 0 ? '$ '.number_format($s['receivables']['overdue'], 0, ',', '.').' vencido' : null] : null,
            'tasks' => $s['tasks'] ? ['label' => 'Tareas abiertas', 'value' => $s['tasks']['open_count'], 'hint' => null] : null,
            'contacts' => $s['contacts'] ? ['label' => 'Último contacto', 'value' => $s['contacts']['last'] ? $s['contacts']['last']['date']->format('d/m/Y') : '—', 'hint' => $s['contacts']['last']['result'] ?? null] : null,
        ]);
    }

    /** Actividad reciente: eventos de las secciones permitidas, lo más nuevo primero. */
    private function activity(array $s): array
    {
        $events = collect();

        foreach ($s['delivery_notes']['items'] ?? [] as $i) {
            $events->push(['date' => $i['date'], 'kind' => 'Remito', 'text' => $i['label'].' ('.$i['status'].')', 'url' => $i['url']]);
        }
        foreach ($s['quotes']['items'] ?? [] as $i) {
            $events->push(['date' => $i['date'], 'kind' => 'Presupuesto', 'text' => $i['label'].' ('.$i['status'].')', 'url' => $i['url']]);
        }
        foreach ($s['work_orders']['items'] ?? [] as $i) {
            $events->push(['date' => $i['date'], 'kind' => 'Orden', 'text' => $i['label'].' ('.$i['status'].')', 'url' => $i['url']]);
        }
        foreach ($s['receivables']['payments'] ?? [] as $i) {
            $events->push(['date' => $i['date'], 'kind' => 'Pago', 'text' => 'Se recibieron $ '.number_format($i['amount'], 0, ',', '.'), 'url' => null]);
        }
        foreach ($s['contacts']['items'] ?? [] as $i) {
            $events->push(['date' => $i['date'], 'kind' => 'Contacto', 'text' => $i['label'].($i['by'] ? ' · '.$i['by'] : ''), 'url' => null]);
        }
        foreach ($s['tasks']['done'] ?? [] as $i) {
            $events->push(['date' => $i['date'], 'kind' => 'Tarea', 'text' => 'Completada: '.$i['label'], 'url' => null]);
        }

        return $events->filter(fn ($e) => $e['date'] !== null)->sortByDesc(fn ($e) => $e['date']->timestamp)->take(12)->values()->all();
    }
}
