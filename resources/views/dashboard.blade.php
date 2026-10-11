{{--
    Inicio del técnico: lo próximo que tiene que hacer, sus contadores (en
    tiempo real) y las visitas del mes. Todo sale de TechnicianWorkload.
--}}
@php
    use App\Support\TechNav;
    use App\Support\WorkOrderLabels;
    $slug = auth()->user()->company->slug;
    $priorityChip = ['urgent' => 't-chip-failed', 'high' => 't-chip-progress', 'medium' => 't-chip-pending', 'low' => 't-chip-neutral'];
    $typeLabels = \App\Services\Insights\MaintenanceAgenda::TYPES;
    $monthTotal = $month['done'] + $month['not_done'] + $month['pending'];
@endphp
<x-app-layout>
<div class="t-page">

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif

    <header class="mb-5">
        <p class="text-sm font-semibold text-brand-700">{{ ucfirst(now()->translatedFormat('l j \d\e F')) }}</p>
        <h1 class="t-h1 mt-0.5">Hola, {{ Str::of(auth()->user()->name)->before(' ') }}</h1>
    </header>

    {{-- Contadores: enlazan a cada listado y se actualizan solos. --}}
    <div class="grid grid-cols-3 gap-2.5" data-tech-counters data-counts-url="{{ route('technician.counters', ['company' => $slug]) }}">
        @foreach([
            ['key' => 'pending', 'label' => 'Pendientes', 'status' => 'pending', 'accent' => 'bg-amber-400'],
            ['key' => 'in_progress', 'label' => 'En curso', 'status' => 'in_progress', 'accent' => 'bg-brand'],
            ['key' => 'completed_today', 'label' => 'Completados hoy', 'status' => 'completed', 'accent' => 'bg-emerald-500'],
        ] as $counter)
            <a href="{{ route('work-orders.index', ['company' => $slug, 'status' => $counter['status']]) }}"
               class="t-card relative overflow-hidden px-3 py-3.5 transition active:scale-[0.98] hover:border-ink-300">
                <span class="absolute inset-y-0 left-0 w-1 {{ $counter['accent'] }}"></span>
                <span class="block text-[28px] font-bold leading-none tracking-tight text-ink" data-count="{{ $counter['key'] }}">{{ $counts[$counter['key']] }}</span>
                <span class="mt-1.5 block text-xs font-semibold leading-tight text-ink-500">{{ $counter['label'] }}</span>
            </a>
        @endforeach
    </div>

    {{-- Activar avisos push (se oculta sola cuando ya están activos). --}}
    <div class="mt-3">@include('push-notifications.card', ['compact' => true])</div>

    <div class="lg:grid lg:grid-cols-2 lg:gap-6">
        {{-- Próximos trabajos --}}
        <section>
            <h2 class="t-section">
                Próximos trabajos
                <a href="{{ route('work-orders.index', ['company' => $slug]) }}" class="text-xs font-bold normal-case tracking-normal text-brand-700">Ver todos</a>
            </h2>
            @if($nextOrders->isEmpty())
                <div class="t-empty">
                    <p class="font-semibold text-ink">No tenés trabajos pendientes</p>
                    <p class="mt-1">Cuando la oficina te asigne una orden, aparece acá y te llega un aviso.</p>
                </div>
            @else
                <div class="t-card t-divide overflow-hidden">
                    @foreach($nextOrders as $order)
                        <a href="{{ route('work-orders.show', ['company' => $slug, 'workOrder' => $order]) }}" class="t-row hover:bg-ink-50">
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    @if($order->status === 'in_progress')
                                        <span class="t-chip-progress">En curso</span>
                                    @endif
                                    <span class="{{ $priorityChip[$order->priority] ?? 't-chip-neutral' }}">{{ WorkOrderLabels::priority($order->priority) }}</span>
                                    <span class="text-xs font-semibold text-ink-500">{{ WorkOrderLabels::type($order->type) }}{{ $order->unit ? ' · '.$order->unit : '' }}</span>
                                </span>
                                <span class="mt-1 block truncate font-semibold text-ink">{{ $order->building?->name }}</span>
                                <span class="block truncate text-sm text-ink-500">{{ trim(($order->building?->address ?? '').' '.($order->building?->locality ?? '')) }}</span>
                            </span>
                            <svg class="h-5 w-5 shrink-0 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('chevron') !!}</svg>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Visitas del mes --}}
        <section>
            <h2 class="t-section">
                Visitas de {{ now()->translatedFormat('F') }}
                <a href="{{ route('buildings.index', ['company' => $slug]) }}" class="text-xs font-bold normal-case tracking-normal text-brand-700">Ver visitas</a>
            </h2>
            @if($monthTotal === 0)
                <div class="t-empty">
                    <p class="font-semibold text-ink">No tenés edificios asignados para mantenimiento o inspección</p>
                    <p class="mt-1">Las asignaciones las hace la oficina desde el panel.</p>
                </div>
            @else
                <div class="t-card overflow-hidden">
                    <div class="px-4 pt-4">
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-semibold text-ink">{{ $month['done'] + $month['not_done'] }} de {{ $monthTotal }} hechas</span>
                            <span class="text-xs text-ink-500">Hoy: {{ $visitsToday }}</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-ink-50" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $monthTotal }}" aria-valuenow="{{ $month['done'] + $month['not_done'] }}">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $monthTotal ? round(($month['done'] + $month['not_done']) / $monthTotal * 100) : 0 }}%"></div>
                        </div>
                        @if($month['not_done'])
                            <p class="mt-2 text-xs font-semibold text-amber-700">{{ $month['not_done'] }} con remito "no realizado": la oficina coordina la nueva visita.</p>
                        @endif
                    </div>
                    @if($month['next']->isNotEmpty())
                        <div class="t-divide mt-3 border-t border-ink-100">
                            @foreach($month['next'] as $row)
                                <a href="{{ route('delivery-notes.building', ['company' => $slug, 'building' => $row['building'], 'month' => now()->month, 'year' => now()->year, 'assignment_type' => $row['type']]) }}" class="t-row hover:bg-ink-50">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold text-ink">{{ $row['building']->name }}</span>
                                        <span class="block truncate text-sm text-ink-500">{{ $typeLabels[$row['type']] }} · {{ $row['building']->address }}</span>
                                    </span>
                                    <span class="t-btn-secondary t-btn-sm shrink-0">Hacer remito</span>
                                </a>
                            @endforeach
                        </div>
                        @if($month['pending'] > $month['next']->count())
                            <a href="{{ route('buildings.index', ['company' => $slug]) }}" class="block border-t border-ink-100 px-4 py-3 text-center text-sm font-semibold text-brand-700">Ver las {{ $month['pending'] }} pendientes</a>
                        @endif
                    @else
                        <p class="px-4 pb-4 pt-2 text-sm font-semibold text-emerald-700">Todas las visitas del mes están hechas.</p>
                    @endif
                </div>
            @endif
        </section>
    </div>

    {{-- Accesos rápidos --}}
    <h2 class="t-section">Accesos rápidos</h2>
    <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
        <a href="{{ route('reports.create', ['company' => $slug]) }}" class="t-btn-primary justify-start">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">{!! TechNav::icon('plus') !!}</svg>Nuevo reporte
        </a>
        <a href="{{ route('buildings.all', ['company' => $slug]) }}" class="t-btn-secondary justify-start">
            <svg class="h-5 w-5 text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('building') !!}</svg>Edificios
        </a>
        <a href="{{ route('delivery-notes.index', ['company' => $slug]) }}" class="t-btn-secondary justify-start">
            <svg class="h-5 w-5 text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('document') !!}</svg>Remitos
        </a>
        <a href="{{ route('templates.index', ['company' => $slug]) }}" class="t-btn-secondary justify-start">
            <svg class="h-5 w-5 text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('table') !!}</svg>Planilla
        </a>
    </div>

    {{-- Últimos remitos --}}
    @if($recentNotes->isNotEmpty())
        <h2 class="t-section">Tus últimos remitos</h2>
        <div class="t-card t-divide overflow-hidden">
            @foreach($recentNotes as $note)
                <a href="{{ route('delivery-notes.show', ['company' => $slug, 'deliveryNote' => $note]) }}" class="t-row hover:bg-ink-50">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-ink">{{ $note->building?->name }}</span>
                        <span class="block text-sm text-ink-500">Remito {{ $note->number }} · {{ $note->created_at?->format('d/m H:i') }}</span>
                    </span>
                    <span class="{{ $note->performed === false ? 't-chip-failed' : 't-chip-done' }}">{{ $note->performed === false ? 'No realizado' : 'Realizado' }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
</x-app-layout>
