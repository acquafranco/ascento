{{--
    Trabajos del técnico: pestañas por estado (con sus contadores) y filtro de
    fecha opcional. Las reglas de qué ve cada técnico están en el controlador.
--}}
@php
    $slug = auth()->user()->company->slug;
    $status = request('status');
    // Fotos de órdenes (si la empresa las habilitó): «Tomar foto» en cada tarjeta.
    $woPhotoSections = \App\Models\Company::find(auth()->user()->company_id)?->photosEnabledFor('work_order')
        ? \App\Models\PhotoSection::forCompany(auth()->user()->company_id)->where('is_active', true)->values()
        : collect();
    $woPhotoCounts = $woPhotoSections->isEmpty() || $workOrders->isEmpty() ? [] : \App\Models\VisitPhoto::withoutGlobalScopes()
        ->where('company_id', auth()->user()->company_id)->whereIn('work_order_id', $workOrders->pluck('id'))
        ->selectRaw('work_order_id, count(*) as total')->groupBy('work_order_id')->pluck('total', 'work_order_id')->all();
    $counts = app(\App\Services\Technicians\TechnicianWorkload::class)->counts(auth()->user());
    $tabs = [
        ['value' => 'pending', 'label' => 'Pendientes', 'count' => $counts['pending']],
        ['value' => 'in_progress', 'label' => 'En curso', 'count' => $counts['in_progress']],
        ['value' => 'completed', 'label' => 'Completados', 'count' => null],
        ['value' => null, 'label' => 'Todos', 'count' => null],
    ];
    foreach ($tabs as $i => $tab) {
        $tabs[$i]['active'] = $status === $tab['value'] || ($tab['value'] === null && blank($status));
    }
    $hasDate = request()->filled('day') || request()->filled('month') || request()->filled('year');
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Trabajos</h1>
        <p class="t-sub">Órdenes que te asignó la oficina: tomalas y finalizalas con el remito.</p>
    </header>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
        <script>navigator.vibrate?.(40);</script>
    @endif

    {{-- Pestañas de estado --}}
    <nav class="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="Estado" data-tech-counters data-counts-url="{{ route('technician.counters', ['company' => $slug]) }}">
        @foreach($tabs as $tab)
            <a href="{{ route('work-orders.index', array_filter(['company' => $slug, 'status' => $tab['value'], 'day' => request('day'), 'month' => request('month'), 'year' => request('year')])) }}"
               @if($tab['active']) aria-current="page" @endif
               class="inline-flex min-h-[40px] shrink-0 items-center gap-2 rounded-full px-4 text-sm font-semibold transition {{ $tab['active'] ? 'bg-ink text-white' : 'border border-ink-100 bg-white text-ink-700 hover:bg-ink-50' }}">
                {{ $tab['label'] }}
                @if($tab['count'] !== null)
                    <span data-count="{{ $tab['value'] }}" class="rounded-full px-1.5 text-xs font-bold {{ $tab['active'] ? 'bg-brand text-ink' : 'bg-ink-50 text-ink-700' }}">{{ $tab['count'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    {{-- Filtro de fecha (fecha de creación de la orden) --}}
    <details class="t-card mb-4" @if($hasDate) open @endif>
        <summary class="flex min-h-[48px] cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold text-ink-700">
            Filtrar por fecha {{ $hasDate ? '· activo' : '' }}
            <svg class="h-4 w-4 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <form method="GET" class="grid grid-cols-3 gap-2 border-t border-ink-100 p-3">
            <input type="hidden" name="status" value="{{ $status }}">
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
                <a href="{{ route('work-orders.index', array_filter(['company' => $slug, 'status' => $status])) }}" class="col-span-3 text-center text-sm font-semibold text-brand-700">Quitar filtro de fecha</a>
            @endif
        </form>
    </details>

    <div class="space-y-3">
        @forelse($workOrders as $workOrder)
            @include('work-orders.partials.card', ['workOrder' => $workOrder, 'linkToDetail' => true, 'quickPhotos' => ['sections' => $woPhotoSections, 'count' => (int) ($woPhotoCounts[$workOrder->id] ?? 0)]])
        @empty
            <div class="t-empty">
                <p class="font-semibold text-ink">
                    @switch($status)
                        @case('pending') No tenés trabajos pendientes @break
                        @case('in_progress') No tenés trabajos en curso @break
                        @case('completed') Todavía no completaste trabajos @break
                        @default No hay órdenes de trabajo
                    @endswitch
                </p>
                <p class="mt-1">{{ $hasDate ? 'Probá quitando el filtro de fecha.' : 'Cuando la oficina te asigne una orden, aparece acá y te llega un aviso.' }}</p>
            </div>
        @endforelse

        @if($workOrders->hasPages())
            <div class="pt-2">{{ $workOrders->onEachSide(1)->links() }}</div>
        @endif
    </div>
</div>
</x-app-layout>
