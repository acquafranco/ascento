{{--
    Tarjeta de una orden de trabajo (listado y detalle). Requiere $workOrder.
    Opcional $linkToDetail (true en el listado): el encabezado abre el detalle.
    Opcional $quickPhotos (listado): «Tomar foto» / «Elegir de la galería» en
    la tarjeta, si la empresa habilitó las fotos de órdenes.
    Acciones, en orden: "Tomar trabajo" (pendiente), el deslizador "Llegué al
    edificio" (en curso, sin llegada; en el listado, porque en el detalle está en
    «Tiempos de atención») y "Hacer remito de esta orden" (con la llegada
    registrada a la vista). La salida es de la visita, no de la orden (Inicio). Las reglas las
    vuelve a validar el servidor.
--}}
@php
    use App\Support\WorkOrderLabels;
    $slug = auth()->user()->company->slug;
    $priorityChip = ['urgent' => 't-chip-failed', 'high' => 't-chip-progress', 'medium' => 't-chip-pending', 'low' => 't-chip-neutral'];
    $linkToDetail = $linkToDetail ?? false;
    $detail = route('work-orders.show', ['company' => $slug, 'workOrder' => $workOrder]);
@endphp

<article class="t-card overflow-hidden">
    <div class="p-4">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="{{ WorkOrderLabels::statusChip($workOrder->status) }}" data-status="{{ $workOrder->status }}">{{ WorkOrderLabels::status($workOrder->status) }}</span>
            <span class="{{ $priorityChip[$workOrder->priority] ?? 't-chip-neutral' }}">Prioridad {{ mb_strtolower(WorkOrderLabels::priority($workOrder->priority)) }}</span>
            <span class="t-chip-neutral">{{ WorkOrderLabels::type($workOrder->type) }}</span>
            @if($workOrder->isTechnicianInitiated())<span class="t-chip-neutral" data-technician-initiated>Iniciada por {{ (int) $workOrder->created_by === (int) auth()->id() ? 'vos' : 'un técnico' }}</span>@endif
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

        {{-- «Tomado» es «Tomar trabajo»; llegada y salida del edificio se registran aparte (AttendanceTimes). --}}
        @if($workOrder->started_at || $workOrder->arrived_at || $workOrder->left_at || $workOrder->deliveryNote)
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-500">
                @if($workOrder->started_at)<span>Tomado <strong class="text-ink">{{ $workOrder->started_at->format('H:i') }}</strong></span>@endif
                @if($workOrder->arrived_at)<span>Llegada <strong class="text-ink">{{ $workOrder->arrived_at->format('H:i') }}</strong></span>@endif
                @if($workOrder->left_at)<span>Salida <strong class="text-ink">{{ $workOrder->left_at->format('H:i') }}</strong></span>@endif
                @if($workOrder->deliveryNote)
                    <a href="{{ route('delivery-notes.show', ['company' => $slug, 'deliveryNote' => $workOrder->deliveryNote]) }}" class="font-semibold text-brand-700">Remito {{ $workOrder->deliveryNote->number }}</a>
                @endif
            </div>
        @endif
    </div>

    @if($workOrder->status === 'pending' || ($workOrder->status === 'in_progress' && $workOrder->users->contains(auth()->id())))
        <div class="border-t border-ink-100 bg-ink-50/60 p-3">
            {{-- Fotos de antes y después de la orden, sin salir de la lista (si la empresa las habilitó). --}}
            @if(($quickPhotos['sections'] ?? collect())->isNotEmpty() && $workOrder->users->contains(auth()->id()))
                <div class="mb-3 -mt-2">
                    @include('photos.quick', [
                        'slug' => $slug,
                        'sections' => $quickPhotos['sections'],
                        'workOrderId' => $workOrder->id,
                        'allUrl' => $detail.'#visitPhotos',
                        'count' => $quickPhotos['count'] ?? 0,
                    ])
                </div>
            @endif
            @if($workOrder->status === 'pending')
                <form method="POST" action="{{ route('work-orders.start', ['company' => $slug, 'workOrder' => $workOrder]) }}">
                    @csrf
                    <button type="submit" class="t-btn-primary w-full">Tomar trabajo</button>
                </form>
            @elseif($workOrder->arrived_at === null && $workOrder->users->contains(auth()->id()))
                {{-- Paso 2: la llegada, con el mismo deslizador de siempre (el único control
                     de llegada). En el detalle está en «Tiempos de atención», no acá. --}}
                @if($linkToDetail)
                    @php $openVisit = once(fn () => \App\Services\WorkOrders\SiteVisits::openFor(auth()->user())); @endphp
                    @if($openVisit && ! \App\Services\WorkOrders\SiteVisits::openIn(auth()->user(), (int) $workOrder->building_id))
                        <p class="text-sm text-amber-900" data-other-visit>Tenés una visita abierta en <strong>{{ $openVisit->building?->name }}</strong>. <a class="font-semibold underline" href="{{ route('dashboard', ['company' => $slug]) }}">Finalizala</a> para registrar la llegada acá.</p>
                    @else
                        @include('partials.slide-confirm', ['action' => route('work-orders.arrive', ['company' => $slug, 'workOrder' => $workOrder->id]), 'label' => 'Llegué al edificio', 'name' => 'arrive-'.$workOrder->id])
                    @endif
                @endif
            @else
                @if($workOrder->arrived_at && $linkToDetail) {{-- en el detalle lo dice «Tiempos de atención» --}}
                    <p class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-emerald-800" data-arrival-registered>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
                        Llegada registrada · {{ $workOrder->arrived_at->format('H:i') }}
                    </p>
                @endif
                <a href="{{ route('delivery-notes.work-order', ['company' => $slug, 'workOrder' => $workOrder]) }}" class="t-btn-dark w-full" data-next-step="remito">Hacer remito de esta orden</a>
            @endif
        </div>
    @endif
</article>
