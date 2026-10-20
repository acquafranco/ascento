{{--
    Un día de la planilla del técnico (TechnicianSheet::day): sus trabajos con
    horario, edificio, con quién y el remito.
--}}
@php
    $slug = auth()->user()->company->slug;
@endphp
<x-app-layout>
<div class="t-page">
    <a href="{{ route('templates.index', ['company' => $slug, 'month' => $date->month, 'year' => $date->year]) }}"
       class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Mi planilla
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">{{ ucfirst($date->locale('es')->translatedFormat('l d \d\e F Y')) }}</h1>
        <p class="t-sub">{{ $jobs->isEmpty() ? 'Sin trabajos' : $jobs->count().' '.($jobs->count() === 1 ? 'trabajo' : 'trabajos') }}</p>
    </header>

    @if($jobs->isEmpty())
        <div class="t-empty">
            <p class="font-semibold text-ink">No hay trabajos registrados para este día</p>
            <p class="mt-1">Cuando completes un mantenimiento, una inspección o una orden de trabajo, aparece acá.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($jobs as $job)
                <article class="t-card p-4" data-sheet-job="{{ $job['id'] }}">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="t-chip-neutral">{{ $job['label'] }}</span>
                        <span class="{{ $job['mine'] ? 't-chip-done' : 't-chip-progress' }}">{{ $job['mine'] ? ($job['role'] === 'Solo' ? 'Lo hiciste vos' : 'Lo hiciste vos · '.mb_strtolower($job['role'])) : $job['role'] }}</span>
                    </div>
                    <h2 class="mt-2 text-[17px] font-bold leading-snug text-ink">{{ $job['building'] }}</h2>
                    <p class="text-sm text-ink-500">{{ collect([$job['address'], $job['client']])->filter()->implode(' · ') }}</p>
                    <p class="mt-2 text-sm font-semibold text-ink-700">{{ $job['kind'] === 'work_order' ? 'De '.$job['time'] : 'A las '.$job['time'] }}</p>

                    @if($job['note'])
                        <div class="mt-3">
                            @if($url = $remitoUrl($job['note']))
                                <a href="{{ $url }}" @if(str_contains($url, '/public/')) target="_blank" rel="noopener" @endif class="t-btn-secondary t-btn-sm">Ver remito {{ $job['note']->number }}</a>
                            @else
                                <span class="t-chip-pending">Remito no disponible</span>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
</x-app-layout>
