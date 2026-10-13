@php
    use App\Models\RepairComponent;
    $c = $getRecord()->loadMissing(['building', 'elevator', 'workOrder', 'followUp', 'creator', 'files']);
    $money = fn ($v) => $v === null ? '—' : '$ '.number_format((float) $v, 2, ',', '.');
    // Historial: el registro de actividad de este componente (solo de esta empresa).
    $history = \App\Models\ActivityLog::query()->where('company_id', $c->company_id)
        ->where('subject_type', 'RepairComponent')->where('subject_id', $c->id)->orderByDesc('id')->limit(100)->get();
    $fieldLabels = ['status' => 'Estado', 'sent_at' => 'Envío', 'provider' => 'Proveedor', 'expected_return_at' => 'Devolución estimada', 'returned_at' => 'Devolución',
        'quote_amount' => 'Presupuesto', 'final_cost' => 'Costo final', 'follow_up_user_id' => 'Seguimiento', 'failure' => 'Falla', 'notes' => 'Observaciones',
        'building_id' => 'Edificio', 'elevator_id' => 'Ascensor', 'work_order_id' => 'Orden', 'component_type' => 'Componente', 'description' => 'Detalle',
        'brand' => 'Marca', 'model' => 'Modelo', 'serial_number' => 'Serie', 'file' => 'Archivo'];
    // Ids del historial → nombres (personas, edificios, ascensores de esta empresa).
    $ids = fn (string $field) => $history->flatMap(fn ($h) => collect([$h->changes[$field]['from'] ?? null, $h->changes[$field]['to'] ?? null, is_scalar($h->changes[$field] ?? null) ? $h->changes[$field] : null]))->filter()->unique()->all();
    $names = [
        'follow_up_user_id' => \App\Models\User::withTrashed()->where('company_id', $c->company_id)->whereIn('id', $ids('follow_up_user_id'))->pluck('name', 'id'),
        'building_id' => \App\Models\Building::withTrashed()->whereIn('id', $ids('building_id'))->pluck('name', 'id'),
        'elevator_id' => \App\Models\Elevator::whereIn('id', $ids('elevator_id'))->pluck('label', 'id'),
    ];
    $show = fn ($field, $value) => match (true) {
        $value === null || $value === '' => '—',
        isset($names[$field]) => $names[$field][$value] ?? '#'.$value,
        $field === 'work_order_id' => '#'.$value,
        $field === 'status' => RepairComponent::STATUSES[$value] ?? $value,
        $field === 'component_type' => RepairComponent::TYPES[$value] ?? $value,
        default => \Illuminate\Support\Str::limit((string) $value, 80),
    };
@endphp
@include('filament.partials.insights-styles')
<div class="asc-grid-2" style="align-items:start" data-repair-detail>
    <div class="asc-card">
        <div class="asc-row"><span class="asc-muted">Estado</span><span class="asc-pill {{ $c->status === RepairComponent::RECEIVED ? 'is-done' : ($c->isLate() ? 'is-overdue' : '') }}" data-repair-status>{{ RepairComponent::STATUSES[$c->status] ?? $c->status }}{{ $c->isLate() ? ' · demorado' : '' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Componente</span><span>{{ $c->componentLabel() }}</span></div>
        <div class="asc-row"><span class="asc-muted">Marca y modelo</span><span>{{ trim(($c->brand ?? '').' '.($c->model ?? '')) ?: '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Número de serie</span><span>{{ $c->serial_number ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Edificio</span><span>{{ $c->building?->name ?? '—' }}@if($c->building?->client) · {{ $c->building->client->name }}@endif</span></div>
        <div class="asc-row"><span class="asc-muted">Ascensor</span><span>{{ $c->elevator?->label ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Orden de trabajo</span><span>
            @if($c->workOrder)
                <a class="asc-link" style="color:#d97706" href="{{ \App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('edit', ['record' => $c->workOrder]) }}">#{{ $c->workOrder->id }} · {{ \App\Support\WorkOrderLabels::type((string) $c->workOrder->type) }}</a>
            @else — @endif
        </span></div>
        <div class="asc-row"><span class="asc-muted">Proveedor o taller</span><span>{{ $c->provider ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Envío</span><span>{{ $c->sent_at?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Devolución estimada</span><span>{{ $c->expected_return_at?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Devolución</span><span>{{ $c->returned_at?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Presupuesto de reparación</span><span>{{ $money($c->quote_amount) }}</span></div>
        <div class="asc-row"><span class="asc-muted">Costo final</span><span>{{ $money($c->final_cost) }}</span></div>
        <div class="asc-row"><span class="asc-muted">Seguimiento</span><span>{{ $c->followUp?->name ?? '—' }}</span></div>
        <div class="asc-row"><span class="asc-muted">Registrado por</span><span>{{ $c->creator?->name ?? '—' }} · {{ $c->created_at->format('d/m/Y H:i') }}</span></div>
        <p class="asc-q" style="margin-top:.75rem">Falla</p>
        <p class="asc-c" style="white-space:pre-line">{{ $c->failure }}</p>
        @if($c->notes)
            <p class="asc-q" style="margin-top:.75rem">Observaciones</p>
            <p class="asc-c" style="white-space:pre-line">{{ $c->notes }}</p>
        @endif
        <p class="asc-q" style="margin-top:.75rem">Documentos y fotos</p>
        @forelse($c->files as $file)
            <p class="asc-row"><a href="{{ $file->url() }}" target="_blank" rel="noopener" class="asc-link" style="color:#d97706">{{ $file->original_name ?: 'Archivo' }}</a><span class="asc-muted asc-small">{{ number_format($file->size / 1024, 0, ',', '.') }} KB</span></p>
        @empty
            <p class="asc-c">Sin archivos.</p>
        @endforelse
    </div>

    <div class="asc-card" data-repair-history>
        <p class="asc-q" style="font-weight:600">Historial de cambios</p>
        @forelse($history as $h)
            <div style="padding:.5rem 0; border-top:1px solid rgba(148,163,184,.25)">
                <p class="asc-small"><strong>{{ $h->created_at?->format('d/m/Y H:i') }}</strong> · {{ $h->actor_name ?? 'Sistema' }}</p>
                @if($h->action === 'repair_component.created')
                    <p class="asc-small asc-muted">Registró el componente ({{ $show('status', $h->changes['status'] ?? null) }}).</p>
                @elseif($h->action === 'repair_component.file_added')
                    <p class="asc-small asc-muted">Adjuntó «{{ $h->changes['file'] ?? 'un archivo' }}».</p>
                @else
                    @foreach(($h->changes ?? []) as $field => $change)
                        @if(is_array($change) && array_key_exists('to', $change))
                            <p class="asc-small asc-muted">{{ $fieldLabels[$field] ?? $field }}: {{ $show($field, $change['from'] ?? null) }} → {{ $show($field, $change['to']) }}</p>
                        @endif
                    @endforeach
                @endif
            </div>
        @empty
            <p class="asc-c">Sin cambios registrados.</p>
        @endforelse
    </div>
</div>
