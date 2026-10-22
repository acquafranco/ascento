{{--
    Pasaporte del ascensor: el QR público (PublicBuildingInfo). Cada dato lleva
    su categoría para que no se lea como una certificación, y se separa lo
    REGISTRADO, lo PUBLICADO por la empresa y lo PENDIENTE. Su única acción es
    «Reportar un problema» ($report: la dirección, o null si no se ofrece).
    Sin enlaces al portal ni al panel. También es la vista previa del panel.
--}}
@php
    $d = fn ($date) => $date?->format('d/m/Y');
    $tag = fn (string $text, string $tone) => '<span class="qr-tag qr-'.$tone.'">'.e($text).'</span>';
    $show = $info['show'];
@endphp
<div class="qr-wrap" data-public-qr @if($info['company']['accent'] ?? null) style="--qr-accent: {{ $info['company']['accent'] }}" @endif>
    {{-- Identidad de la empresa que mantiene el ascensor (no de Ascento: Ascento va al pie). --}}
    <header class="qr-head" data-qr="company">
        @if($info['company']['logo'])
            {{-- Si la imagen no carga (archivo movido o sin conexión), se oculta: nunca un ícono roto. --}}
            <img src="{{ $info['company']['logo'] }}" alt="Logo de {{ $info['company']['name'] }}" class="qr-logo" onerror="this.remove()">
        @endif
        <div style="min-width:0">
            <p class="qr-company">Mantenimiento a cargo de <strong>{{ $info['company']['name'] }}</strong></p>
            <h1 class="qr-building">{{ $info['building']['name'] }}</h1>
            @if($info['building']['address'])<p class="qr-company">{{ $info['building']['address'] }}</p>@endif
        </div>
    </header>

    {{-- Acción principal: reportar lo que se observó (no es un pedido de rescate). --}}
    @if(! empty($report))
        <a href="{{ $report }}" class="qr-cta" data-qr="report">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V4h11l-1 4h6v9H9l1-4H4"/></svg>
            Reportar un problema
        </a>
        <p class="qr-cta-note">Para avisar algo que notaste (puertas, ruidos, luces, daños). No es un servicio de emergencias: ante una urgencia, usá la alarma del ascensor y llamá {{ \App\Support\Countries::emergencyNumber($info['building']['country'] ?? null) }}.</p>
    @endif

    {{-- REGISTRADO: trabajos con remito --}}
    @if($show['maintenance'] || $show['inspection'] || $show['history'])
        <h2 class="qr-sec-h">Registrado</h2>
        <section class="qr-card">
            @if($show['maintenance'])
                <div class="qr-row" data-qr="maintenance">
                    <div>{!! $tag('Trabajo realizado', 'done') !!}<p class="qr-label">Último mantenimiento registrado</p></div>
                    <p class="qr-value">{{ $d($info['last_maintenance']) ?? 'Sin registros' }}</p>
                </div>
            @endif
            @if($show['inspection'])
                <div class="qr-row" data-qr="inspection">
                    <div>{!! $tag('Inspección registrada', 'info') !!}<p class="qr-label">Última inspección registrada</p></div>
                    <p class="qr-value">{{ $d($info['last_inspection']) ?? 'Sin registros' }}</p>
                </div>
            @endif
            @if($show['history'])
                <div data-qr="history" style="{{ $show['maintenance'] || $show['inspection'] ? 'border-top:1px solid #F4F5F7; margin-top:.4rem; padding-top:.5rem' : '' }}">
                    <p class="qr-label" style="margin:0 0 .3rem"><strong>Historial de servicios</strong> <span class="qr-muted">(últimos {{ count($info['history']) ?: '' }} remitos)</span></p>
                    @if($info['history'])
                        <ul class="qr-list">
                            @foreach($info['history'] as $h)
                                <li><span>{{ $d($h['date']) }} · {{ $h['type'] }}</span><span class="qr-muted">Remito {{ $h['number'] }} · {{ $h['performed'] ? 'Realizado' : 'No realizado' }}</span></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="qr-muted" style="margin:0">Sin servicios registrados todavía.</p>
                    @endif
                </div>
            @endif
        </section>
    @endif

    @if($show['elevators'])
        <h2 class="qr-sec-h">Equipos</h2>
        <section class="qr-card">
            @forelse($info['elevators'] as $e)
                <div class="qr-elevator" data-qr="elevator">
                    <p class="qr-label"><strong>{{ $e['label'] }}</strong>@if($e['kind']) <span class="qr-muted">· {{ $e['kind'] }}</span>@endif</p>
                    @if($e['status'])
                        <p class="qr-line">{!! $tag('Información administrativa', 'admin') !!} Estado informado por la empresa: <strong>{{ $e['status'] }}</strong> <span class="qr-muted">({{ $d($e['status_at']) }})</span></p>
                    @endif
                    @if($show['tests'])
                        @if($e['test'] && $e['test']['ok'])
                            <p class="qr-line">{!! $tag('Inspección registrada', 'info') !!} Prueba de paracaídas registrada el {{ $d($e['test']['date']) }}</p>
                        @elseif($e['test'])
                            <p class="qr-line">{!! $tag('Observación pendiente', 'warn') !!} La última prueba de paracaídas registrada ({{ $d($e['test']['date']) }}) no fue satisfactoria</p>
                        @else
                            <p class="qr-line qr-muted">Sin pruebas de paracaídas registradas</p>
                        @endif
                        @if($e['next_test'])
                            <p class="qr-line" data-qr="next-test">{!! $tag('Observación pendiente', 'warn') !!}
                                @if($e['next_test']['overdue'])
                                    Prueba de paracaídas prevista para el {{ $d($e['next_test']['date']) }}, todavía sin registrar
                                @else
                                    Próxima prueba de paracaídas prevista: {{ $d($e['next_test']['date']) }}
                                @endif
                            </p>
                        @endif
                    @endif
                    @if($show['repairs'])
                        @foreach($e['repairs'] as $r)
                            <p class="qr-line" data-qr="repair">{!! $tag('Información administrativa', 'admin') !!} {{ $r['component'] }}: <strong>{{ $r['status'] }}</strong>@if($r['at']) <span class="qr-muted">(desde el {{ $d($r['at']) }})</span>@endif</p>
                        @endforeach
                    @endif
                </div>
            @empty
                <p class="qr-muted">No hay equipos registrados.</p>
            @endforelse
        </section>
    @endif

    @if($show['repairs'] && $info['building_repairs'])
        <section class="qr-card" data-qr="building-repairs">
            <h2 class="qr-h2">Componentes en reparación</h2>
            @foreach($info['building_repairs'] as $r)
                <p class="qr-line">{!! $tag('Información administrativa', 'admin') !!} {{ $r['component'] }}: <strong>{{ $r['status'] }}</strong>@if($r['at']) <span class="qr-muted">(desde el {{ $d($r['at']) }})</span>@endif</p>
            @endforeach
        </section>
    @endif

    {{-- PUBLICADO: documentos que la empresa eligió publicar --}}
    @if($show['documents'])
        <h2 class="qr-sec-h">Documentación publicada</h2>
        <section class="qr-card" data-qr="documents">
            @if($info['documents'])
                <ul class="qr-list">
                    @foreach($info['documents'] as $doc)
                        <li>
                            <span>{{ $doc['title'] }}<span class="qr-muted"> · {{ $doc['type'] }}{{ $doc['elevator'] ? ' · '.$doc['elevator'] : '' }}</span>
                                @if($doc['expires_at'])<span class="qr-muted" style="display:block; font-size:.82rem">{{ $doc['expired'] ? 'Vencido el ' : 'Vence el ' }}{{ $d($doc['expires_at']) }}</span>@endif
                            </span>
                            <a href="{{ $doc['url'] }}" target="_blank" rel="noopener">Ver</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="qr-muted" style="margin:0">La empresa todavía no publicó documentación.</p>
            @endif
        </section>
    @endif

    {{-- PENDIENTE: lo que corresponde y todavía no tiene registro --}}
    @if(($show['next_visit'] && $info['next_visits']) || $show['pending'])
        <h2 class="qr-sec-h">Pendiente</h2>
        @if($show['next_visit'] && $info['next_visits'])
            <section class="qr-card" data-qr="next-visit">
                @foreach($info['next_visits'] as $v)
                    <p class="qr-line">
                        @if($v['registered'] >= $v['expected'])
                            {!! $tag('Trabajo realizado', 'done') !!} {{ $v['type'] }} de {{ $v['period'] }}: registrado
                        @else
                            {!! $tag('Observación pendiente', 'warn') !!} {{ $v['type'] }} de {{ $v['period'] }}: previsto, todavía sin registrar
                        @endif
                        <span class="qr-muted">(frecuencia: {{ mb_strtolower($v['frequency']) }})</span>
                    </p>
                @endforeach
            </section>
        @endif
        @if($show['pending'])
            <section class="qr-card" data-qr="pending">
                <h2 class="qr-h2">Trabajos pendientes</h2>
                @forelse($info['pending'] as $p)
                    <p class="qr-line">{!! $tag('Observación pendiente', 'warn') !!} {{ $p['type'] }}: {{ $p['count'] }} {{ mb_strtolower($p['status']) }}</p>
                @empty
                    <p class="qr-muted">No hay trabajos pendientes registrados.</p>
                @endforelse
            </section>
        @endif
    @endif

    {{-- Incidencias que la empresa eligió publicar (solo su estado administrativo). --}}
    @if($show['incidents'])
        <h2 class="qr-sec-h">Incidencias informadas</h2>
        <section class="qr-card" data-qr="incidents">
            @if($info['incidents'])
                <ul class="qr-list">
                    @foreach($info['incidents'] as $i)
                        <li><span>{{ $d($i['date']) }} · {{ $i['category'] }}</span><strong style="white-space:nowrap">{{ $i['status'] }}</strong></li>
                    @endforeach
                </ul>
                <p class="qr-muted" style="margin:.5rem 0 0; font-size:.82rem">{{ \App\Models\ClientClaim::STATUS_NOTE }}</p>
            @else
                <p class="qr-muted" style="margin:0">No hay incidencias publicadas.</p>
            @endif
        </section>
    @endif

    @if($show['contact'] && ($info['company']['phone'] || $info['company']['email']))
        <section class="qr-card" data-qr="contact">
            <h2 class="qr-h2">Empresa de mantenimiento</h2>
            @if($info['company']['phone'])<p class="qr-line">Teléfono: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $info['company']['phone']) }}">{{ $info['company']['phone'] }}</a></p>@endif
            @if($info['company']['email'])<p class="qr-line">Correo: <a href="mailto:{{ $info['company']['email'] }}">{{ $info['company']['email'] }}</a></p>@endif
        </section>
    @endif

    <section class="qr-note" data-qr="disclaimer">
        <p><strong>Esta información es administrativa.</strong> La publica la empresa de mantenimiento con los registros que carga en Ascento. Es un historial de documentos. No es una evaluación ni una certificación de seguridad, no es una habilitación y no garantiza que el ascensor esté en condiciones de funcionamiento.</p>
        <p>Ante una emergencia o si alguien queda atrapado, usá el botón de alarma o el intercomunicador del ascensor. Si hay peligro inmediato, llamá {{ \App\Support\Countries::emergencyNumber($info['building']['country'] ?? null) }}.</p>
        <p class="qr-muted">Actualizado: {{ $info['updated_at']?->format('d/m/Y') ?? '—' }}</p>
    </section>

    @include('public-qr.partials.footer')
</div>
