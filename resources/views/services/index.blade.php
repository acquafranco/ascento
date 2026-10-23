<x-services-layout>
    <x-slot:hero>
        <div class="p-hero">
            <h1>Profesionales y servicios</h1>
            <p>Especialistas en ascensores para empresas de mantenimiento: placas, variadores, controles, asistencia técnica, inspecciones, modernización y consultoría. Publicar un perfil y recibir consultas es gratis.</p>
        </div>
    </x-slot:hero>

    <form method="GET" action="{{ route('services.index') }}" class="p-card" role="search" aria-label="Buscar profesionales" data-services-filters>
        <div class="p-filters">
            <div style="grid-column: span 2"><label for="p-q">Buscar</label><input id="p-q" class="p-input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" placeholder="Nombre, especialidad, equipo…"></div>
            <div><label for="p-s">Servicio</label>
                <select id="p-s" name="servicio" class="p-input"><option value="">Todos</option>
                    @foreach($categories as $slug => $name)<option value="{{ $slug }}" @selected(($filters['servicio'] ?? '') === $slug)>{{ $name }}</option>@endforeach
                </select></div>
            <div><label for="p-m">Marca o sistema</label><input id="p-m" class="p-input" name="marca" list="p-brands" value="{{ $filters['marca'] ?? '' }}" maxlength="80" placeholder="Schindler, Otis…">
                <datalist id="p-brands">@foreach($brands as $label)<option value="{{ $label }}">@endforeach</datalist></div>
            <div><label for="p-c">País</label>
                <select id="p-c" name="pais" class="p-input"><option value="">Todos</option>
                    @foreach($countries as $code => $name)<option value="{{ $code }}" @selected(($filters['pais'] ?? '') === $code)>{{ $name }}</option>@endforeach
                </select></div>
            <div><label for="p-p">Provincia (Argentina)</label>
                <select id="p-p" name="provincia" class="p-input"><option value="">Todas</option>
                    @foreach($provinces as $p)<option value="{{ $p }}" @selected(($filters['provincia'] ?? '') === $p)>{{ $p }}</option>@endforeach
                </select></div>
            <div><label for="p-mo">Modalidad</label>
                <select id="p-mo" name="modalidad" class="p-input"><option value="">Cualquiera</option>
                    <option value="onsite" @selected(($filters['modalidad'] ?? '') === 'onsite')>Presencial</option>
                    <option value="remote" @selected(($filters['modalidad'] ?? '') === 'remote')>Remota</option>
                </select></div>
            <div><button class="p-btn" type="submit">Buscar</button></div>
        </div>
        <p class="p-muted" style="margin:10px 0 0">Para filtrar por provincia, elegí «Argentina» en país. La información de cada perfil la declara su titular: Ascento no verifica títulos, matrículas ni certificaciones.</p>
    </form>

    <p class="p-muted" style="margin:16px 0 10px" aria-live="polite">{{ $profiles->total() === 1 ? '1 perfil' : $profiles->total().' perfiles' }}</p>

    @if($profiles->isEmpty())
        <div class="p-card p-muted">No encontramos perfiles con esos filtros. Probá con menos filtros o con otra palabra.</div>
    @else
        <div class="p-grid">
            @foreach($profiles as $p)
                <article class="p-card" data-service-profile="{{ $p->slug }}">
                    <a class="p-name" href="{{ route('services.show', $p->slug) }}">{{ $p->public_name }}</a>
                    <div class="p-muted">{{ $p->kindLabel() }} · {{ $p->modalityLabel() }}</div>
                    @if($p->headline)<p style="margin:8px 0 6px; font-size:14px">{{ $p->headline }}</p>@endif
                    <div style="margin-top:8px">@foreach($p->categories->take(4) as $c)<span class="p-tag">{{ $c->name }}</span>@endforeach</div>
                    @if($p->brands)<div class="p-muted">Marcas: {{ implode(', ', array_slice($p->brands, 0, 6)) }}</div>@endif
                    <div class="p-muted">Zona: {{ $p->coverageLabel() }}</div>
                    <div style="margin-top:10px"><a class="p-btn secondary" href="{{ route('services.show', $p->slug) }}">Ver perfil</a></div>
                </article>
            @endforeach
        </div>
        @if($profiles->hasPages())
            <nav class="p-pages" aria-label="Páginas">
                @if($profiles->previousPageUrl())<a class="p-btn secondary" href="{{ $profiles->previousPageUrl() }}" rel="prev">Anterior</a>@endif
                <span class="p-muted">Página {{ $profiles->currentPage() }} de {{ $profiles->lastPage() }}</span>
                @if($profiles->nextPageUrl())<a class="p-btn secondary" href="{{ $profiles->nextPageUrl() }}" rel="next">Siguiente</a>@endif
            </nav>
        @endif
    @endif

    <section class="p-card" style="margin-top:22px">
        <h2 style="font-size:16px; margin:0 0 6px">¿Ofrecés servicios para ascensores?</h2>
        <p class="p-muted" style="margin:0 0 10px">Creá tu perfil gratis: sin vencimiento, sin comisiones y sin límite de servicios. Si ya vendés repuestos en Ascento o tenés una empresa de mantenimiento, usá la misma cuenta.</p>
        <a class="p-btn" href="{{ route('suppliers.register', ['ofrezco' => 'services']) }}">Crear mi perfil profesional</a>
    </section>
</x-services-layout>
