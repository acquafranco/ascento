@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-xl border border-rail-100 bg-rail-100/60 px-4 py-3 text-sm font-medium text-rail-600']) }}>
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        {{ $status }}
    </div>
@endif
