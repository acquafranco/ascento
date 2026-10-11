{{-- Paginación del portal (sin depender de Tailwind). --}}
@if($paginator->hasPages())
    <nav class="p-pager" aria-label="Páginas">
        <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
        <span style="display:flex;gap:8px">
            @if($paginator->onFirstPage())
                <span class="p-btn secondary" aria-disabled="true" style="opacity:.45">← Anteriores</span>
            @else
                <a class="p-btn secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anteriores</a>
            @endif
            @if($paginator->hasMorePages())
                <a class="p-btn secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguientes →</a>
            @else
                <span class="p-btn secondary" aria-disabled="true" style="opacity:.45">Siguientes →</span>
            @endif
        </span>
    </nav>
@endif
