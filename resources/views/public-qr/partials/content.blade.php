{{--
    Contenido del QR público (PublicBuildingInfo). Cada dato lleva su
    categoría para que no se lea como una certificación. Sin enlaces al
    portal ni al panel, sin formularios.
--}}
@php
    $d = fn ($date) => $date?->format('d/m/Y');
    $tag = fn (string $text, string $tone) => '<span class="qr-tag qr-'.$tone.'">'.e($text).'</span>';
@endphp
<div class="qr-wrap" data-public-qr>
    <header class="qr-head">
        @if($info['company']['logo'])
            <img src="{{ $info['company']['logo'] }}" alt="" class="qr-logo">
        @endif
        <div>
            <p class="qr-company">{{ $info['company']['name'] }}</p>
            <h1 class="qr-building">{{ $info['building']['name'] }}</h1>
            @if($info['building']['address'])<p class="qr-muted">{{ $info['building']['address'] }}</p>@endif
        </div>
    </header>

    @if($info['show']['maintenance'] || $info['show']['inspection'])
        <section class="qr-card">
            @if($info['show']['maintenance'])
                <div class="qr-row" data-qr="maintenance">
                    <div>{!! $tag('Trabajo realizado', 'done') !!}<p class="qr-label">Último mantenimiento registrado</p></div>
                    <p class="qr-value">{{ $d($info['last_maintenance']) ?? 'Sin registros' }}</p>
                </div>
            @endif
            @if($info['show']['inspection'])
                <div class="qr-row" data-qr="inspection">
                    <div>{!! $tag('Inspección registrada', 'info') !!}<p class="qr-label">Última inspección registrada</p></div>
                    <p class="qr-value">{{ $d($info['last_inspection']) ?? 'Sin registros' }}</p>
                </div>
            @endif
        </section>
    @endif

    @if($info['show']['elevators'])
        <section class="qr-card">
            <h2 class="qr-h2">Equipos</h2>
            @forelse($info['elevators'] as $e)
                <div class="qr-elevator" data-qr="elevator">
                    <p class="qr-label"><strong>{{ $e['label'] }}</strong>@if($e['kind']) <span class="qr-muted">· {{ $e['kind'] }}</span>@endif</p>
                    @if($e['status'])
                        <p class="qr-line">{!! $tag('Información administrativa', 'admin') !!} Estado informado por la empresa: <strong>{{ $e['status'] }}</strong> <span class="qr-muted">({{ $d($e['status_at']) }})</span></p>
                    @endif
                    @if($info['show']['tests'])
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
                </div>
            @empty
                <p class="qr-muted">No hay equipos registrados.</p>
            @endforelse
        </section>
    @endif

    @if($info['show']['pending'])
        <section class="qr-card" data-qr="pending">
            <h2 class="qr-h2">Trabajos pendientes</h2>
            @forelse($info['pending'] as $p)
                <p class="qr-line">{!! $tag('Observación pendiente', 'warn') !!} {{ $p['type'] }}: {{ $p['count'] }} {{ mb_strtolower($p['status']) }}</p>
            @empty
                <p class="qr-muted">No hay trabajos pendientes registrados.</p>
            @endforelse
        </section>
    @endif

    @if($info['show']['contact'] && ($info['company']['phone'] || $info['company']['email']))
        <section class="qr-card" data-qr="contact">
            <h2 class="qr-h2">Empresa de mantenimiento</h2>
            @if($info['company']['phone'])<p class="qr-line">Teléfono: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $info['company']['phone']) }}">{{ $info['company']['phone'] }}</a></p>@endif
            @if($info['company']['email'])<p class="qr-line">Correo: <a href="mailto:{{ $info['company']['email'] }}">{{ $info['company']['email'] }}</a></p>@endif
        </section>
    @endif

    <section class="qr-note" data-qr="disclaimer">
        <p><strong>Esta información es administrativa.</strong> La publica la empresa de mantenimiento con los registros que carga en Ascento. No es una evaluación ni una certificación de seguridad y no reemplaza la de un profesional habilitado.</p>
        <p class="qr-muted">Actualizado: {{ $info['updated_at']?->format('d/m/Y') ?? '—' }}</p>
    </section>
</div>
