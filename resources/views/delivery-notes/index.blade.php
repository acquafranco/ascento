{{-- Remitos del técnico, con filtro de fecha opcional. --}}
@php
    $slug = auth()->user()->company->slug;
    $hasDate = request()->filled('day') || request()->filled('month') || request()->filled('year');
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h1 class="t-h1">Remitos</h1>
            <p class="t-sub">Los remitos que generaste.</p>
        </div>
        <span class="t-chip-neutral mt-1 shrink-0">{{ $deliveryNotes->total() }} {{ $deliveryNotes->total() === 1 ? 'remito' : 'remitos' }}</span>
    </header>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="t-alert-warning mb-4" role="status">{{ session('warning') }}</div>
    @endif
    {{-- Salida sin registrar: firmar el remito no la registra (puede ser antes o después de irse). --}}
    @foreach($pendingExits ?? [] as $order)
        <a href="{{ route('work-orders.show', ['company' => auth()->user()->company->slug, 'workOrder' => $order->id]) }}" class="t-alert-warning mb-4 flex items-center justify-between gap-3" data-pending-exit>
            <span>¿Ya te fuiste de {{ $order->building?->name ?? 'el edificio' }}? Registrá la salida.</span>
            <span class="shrink-0 underline">Registrar</span>
        </a>
    @endforeach

    <details class="t-card mb-4" @if($hasDate) open @endif>
        <summary class="flex min-h-[48px] cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold text-ink-700">
            Filtrar por fecha {{ $hasDate ? '· activo' : '' }}
            <svg class="h-4 w-4 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <form method="GET" class="grid grid-cols-3 gap-2 border-t border-ink-100 p-3">
            <select name="day" class="t-input" aria-label="Día" onchange="this.form.submit()">
                <option value="">Día</option>
                @for($d = 1; $d <= 31; $d++)<option value="{{ $d }}" @selected(request('day') == $d)>{{ $d }}</option>@endfor
            </select>
            <select name="month" class="t-input" aria-label="Mes" onchange="this.form.submit()">
                <option value="">Mes</option>
                @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" @selected(request('month') == $m)>{{ ucfirst(\Carbon\Carbon::create()->month($m)->translatedFormat('F')) }}</option>@endfor
            </select>
            <select name="year" class="t-input" aria-label="Año" onchange="this.form.submit()">
                <option value="">Año</option>
                @for($y = now()->year - 3; $y <= now()->year + 1; $y++)<option value="{{ $y }}" @selected(request('year') == $y)>{{ $y }}</option>@endfor
            </select>
            @if($hasDate)
                <a href="{{ route('delivery-notes.index', ['company' => $slug]) }}" class="col-span-3 text-center text-sm font-semibold text-brand-700">Quitar filtro de fecha</a>
            @endif
        </form>
    </details>

    @if($deliveryNotes->isEmpty())
        <div class="t-empty">
            <p class="font-semibold text-ink">{{ $hasDate ? 'No hay remitos en esa fecha' : 'Todavía no generaste remitos' }}</p>
            <p class="mt-1">{{ $hasDate ? 'Probá quitando el filtro.' : 'Se generan al terminar una visita o una orden de trabajo.' }}</p>
        </div>
    @else
        <div class="t-card t-divide overflow-hidden">
            @foreach($deliveryNotes as $note)
                @php
                    $kind = $note->workOrder
                        ? 'Orden · '.\App\Support\WorkOrderLabels::type($note->workOrder->type)
                        : ($note->buildingVisit ? \App\Support\WorkOrderLabels::type($note->buildingVisit->assignment_type) : null);
                @endphp
                <a href="{{ route('delivery-notes.show', ['company' => $slug, 'deliveryNote' => $note->number]) }}" class="t-row hover:bg-ink-50">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span class="{{ $note->performed ? 't-chip-done' : 't-chip-failed' }}">{{ $note->performed ? 'Realizado' : 'No realizado' }}</span>
                            @if($kind)<span class="t-chip-neutral">{{ $kind }}</span>@endif
                        </span>
                        <span class="mt-1 block truncate font-semibold text-ink">{{ $note->building?->name }}</span>
                        <span class="block truncate text-sm text-ink-500">Remito {{ $note->number }} · {{ $note->building?->address }}</span>
                    </span>
                    <span class="shrink-0 text-right text-xs text-ink-500">
                        <span class="block font-semibold text-ink">{{ $note->created_at->format('d/m') }}</span>
                        {{ $note->created_at->format('H:i') }}
                    </span>
                </a>
            @endforeach
        </div>
        @if($deliveryNotes->hasPages())
            <div class="mt-4">{{ $deliveryNotes->onEachSide(1)->links() }}</div>
        @endif
    @endif
</div>
</x-app-layout>
