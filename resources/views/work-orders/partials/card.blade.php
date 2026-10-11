{{--
    Tarjeta de una orden de trabajo (listado y detalle). Requiere $workOrder.
    Opcional $linkToDetail (true en el listado): el encabezado abre el detalle.
    Acciones: "Tomar trabajo" (pendiente) y "Hacer remito y finalizar" (en
    curso y asignado). Las reglas las vuelve a validar el servidor.
--}}
@php
    use App\Support\WorkOrderLabels;
    $slug = auth()->user()->company->slug;
    $statusChip = ['pending' => 't-chip-pending', 'in_progress' => 't-chip-progress', 'completed' => 't-chip-done', 'failed' => 't-chip-failed'];
    $priorityChip = ['urgent' => 't-chip-failed', 'high' => 't-chip-progress', 'medium' => 't-chip-pending', 'low' => 't-chip-neutral'];
    $linkToDetail = $linkToDetail ?? false;
    $detail = route('work-orders.show', ['company' => $slug, 'workOrder' => $workOrder]);
@endphp

<article class="t-card overflow-hidden">
    <div class="p-4">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="{{ $statusChip[$workOrder->status] ?? 't-chip-neutral' }}">{{ WorkOrderLabels::status($workOrder->status) }}</span>
            <span class="{{ $priorityChip[$workOrder->priority] ?? 't-chip-neutral' }}">Prioridad {{ mb_strtolower(WorkOrderLabels::priority($workOrder->priority)) }}</span>
            <span class="t-chip-neutral">{{ WorkOrderLabels::type($workOrder->type) }}</span>
            <span class="ml-auto text-xs font-semibold text-ink-500">{{ $workOrder->created_at->format('d/m/Y') }}</span>
        </div>

        @if($linkToDetail)
            <a href="{{ $detail }}" class="mt-2.5 block">
        @else
            <div class="mt-2.5">
        @endif
                <h3 class="text-[17px] font-bold leading-snug text-ink">{{ $workOrder->building?->name ?? 'Edificio eliminado' }}</h3>
                <p class="text-sm text-ink-500">
                    {{ trim(($workOrder->building?->address ?? '').' '.($workOrder->building?->locality ?? '')) }}
                    @if($workOrder->unit) · <span class="font-semibold text-ink-700">{{ $workOrder->unit }}</span> @endif
                </p>
        @if($linkToDetail)
            </a>
        @else
            </div>
        @endif

        @if($workOrder->notes)
            <div class="mt-3 rounded-xl bg-ink-50 px-3 py-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wider text-ink-500">Trabajo a realizar</div>
                <p class="mt-0.5 whitespace-pre-line text-sm leading-6 text-ink-700">{{ $workOrder->notes }}</p>
            </div>
        @endif

        @if($workOrder->started_at || $workOrder->finished_at || $workOrder->deliveryNote)
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-500">
                @if($workOrder->started_at)<span>Entrada <strong class="text-ink">{{ $workOrder->started_at->format('H:i') }}</strong></span>@endif
                @if($workOrder->finished_at)<span>Salida <strong class="text-ink">{{ $workOrder->finished_at->format('H:i') }}</strong></span>@endif
                @if($workOrder->deliveryNote)
                    <a href="{{ route('delivery-notes.show', ['company' => $slug, 'deliveryNote' => $workOrder->deliveryNote]) }}" class="font-semibold text-brand-700">Remito {{ $workOrder->deliveryNote->number }}</a>
                @endif
            </div>
        @endif
    </div>

    @if($workOrder->status === 'pending' || ($workOrder->status === 'in_progress' && $workOrder->users->contains(auth()->id())))
        <div class="border-t border-ink-100 bg-ink-50/60 p-3">
            @if($workOrder->status === 'pending')
                <form method="POST" action="{{ route('work-orders.start', ['company' => $slug, 'workOrder' => $workOrder]) }}">
                    @csrf
                    <button type="submit" class="t-btn-primary w-full">Tomar trabajo</button>
                </form>
            @else
                <a href="{{ route('delivery-notes.work-order', ['company' => $slug, 'workOrder' => $workOrder]) }}" class="t-btn-dark w-full">Hacer remito y finalizar</a>
            @endif
        </div>
    @endif
</article>
