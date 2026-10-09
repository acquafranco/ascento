@php
    $type = $filters['type'] ?? null;
    $base = array_filter(\Illuminate\Support\Arr::except($filters, ['type']), fn ($v) => $v !== null && $v !== '');
    $statusOptions = match ($type) {
        'report' => \App\Models\Report::STATUS_LABELS,
        'quote' => \App\Models\Quote::STATUSES,
        default => [],
    };
@endphp
<x-portal-layout title="Documentos">
    <h1 class="p-h1">Documentos</h1>
    <p class="p-sub">Remitos, reportes, presupuestos y documentación técnica que {{ $portalCompany?->name }} compartió con vos.</p>

    <details class="p-filter-box" open data-filters>
        <summary>Filtros ▾</summary>
    <form method="GET" action="{{ route('portal.documents') }}" class="p-filters" role="search">
        @if($type)<input type="hidden" name="type" value="{{ $type }}">@endif
        <div>
            <label for="f-q">Buscar</label>
            <input id="f-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Número, título o texto" maxlength="80">
        </div>
        <div>
            <label for="f-building">Edificio</label>
            <select id="f-building" name="building">
                <option value="">Todos</option>
                @foreach($buildings as $b)
                    <option value="{{ $b->id }}" @selected((int) ($filters['building'] ?? 0) === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        @if($statusOptions)
            <div>
                <label for="f-status">Estado</label>
                <select id="f-status" name="status">
                    <option value="">Todos</option>
                    @foreach($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="f-from">Desde</label>
            <input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div>
            <label for="f-to">Hasta</label>
            <input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        </div>
        <div>
            <label for="f-sort">Orden</label>
            <select id="f-sort" name="sort">
                <option value="desc">Más recientes</option>
                <option value="asc" @selected(($filters['sort'] ?? '') === 'asc')>Más antiguos</option>
            </select>
        </div>
        <div class="p-filter-actions">
            <button class="p-btn" type="submit">Filtrar</button>
            @if($base)<a class="p-btn secondary" href="{{ route('portal.documents', $type ? ['type' => $type] : []) }}">Limpiar</a>@endif
        </div>
    </form>
    </details>
    <script>
        // En el celular, los filtros arrancan plegados si no hay ninguno activo.
        (function () { var d = document.querySelector('[data-filters]'); if (d && window.innerWidth < 640 && !/[?&](q|building|status|from|to|year|result)=[^&]/.test(location.search)) d.open = false; })();
    </script>

    <nav class="p-tabs" aria-label="Tipo de documento">
        <a href="{{ route('portal.documents', $base) }}" @if(! $type) aria-current="page" @endif>Todos<b>{{ array_sum($counts) }}</b></a>
        @foreach(\App\Support\Portal\PortalDocuments::TYPES as $key => $label)
            <a href="{{ route('portal.documents', $base + ['type' => $key]) }}" @if($type === $key) aria-current="page" @endif>{{ $label }}<b>{{ $counts[$key] ?? 0 }}</b></a>
        @endforeach
    </nav>

    @if($documents->isEmpty())
        <div class="p-empty">
            @if($base || $type)
                No hay documentos con estos filtros. <a class="p-link" href="{{ route('portal.documents') }}">Ver todos</a>
            @else
                Todavía no te compartieron documentos. Cuando {{ $portalCompany?->name }} lo haga, los vas a ver acá.
            @endif
        </div>
    @else
        <ul class="p-list">
            @foreach($documents as $d)
                @include('portal.partials.doc-row', ['d' => $d])
            @endforeach
        </ul>
        @include('portal.partials.pager', ['paginator' => $documents])
    @endif
</x-portal-layout>
