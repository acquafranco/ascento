@php
    $money = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    <div class="asc-grid">
        @foreach($sections['basic'] as $kpi)
            <div class="asc-card">
                <p class="asc-q">{{ $kpi['question'] }}</p>
                <p class="asc-v">{{ $kpi['value'] }}</p>
                <p class="asc-c">{{ $kpi['context'] }}</p>
            </div>
        @endforeach
    </div>

    @if($c = $sections['company'])
        <section class="asc-stack">
            <h2 class="asc-h2">¿Cómo venimos los últimos 6 meses?</h2>
            <div class="asc-card asc-scroll">
                <table class="asc-table">
                    <thead><tr><th>Mes</th><th>Mantenimientos cumplidos</th><th>Reportes y reclamos</th><th>Órdenes terminadas</th><th>Días para cerrar una orden</th></tr></thead>
                    <tbody>
                        @foreach($c['evolution'] as $m)
                            <tr><td>{{ $m['month'] }}</td><td>{{ $m['maintenance'] }}</td><td>{{ $m['claims'] }}</td><td>{{ $m['completed'] }}</td><td>{{ $m['close_days'] ?? '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <h2 class="asc-h2">¿Cómo trabaja cada técnico? <span class="asc-small asc-muted" style="font-weight: 400">(últimos 90 días)</span></h2>
            <div class="asc-card asc-scroll">
                <table class="asc-table">
                    <thead><tr><th>Técnico</th><th>Mantenimientos e inspecciones</th><th>Órdenes terminadas</th><th>Días promedio por orden</th></tr></thead>
                    <tbody>
                        @forelse($c['technicians'] as $t)
                            <tr><td>{{ $t['name'] }}</td><td>{{ $t['visits'] }}</td><td>{{ $t['orders'] }}</td><td>{{ $t['close_days'] ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="asc-muted">Todavía no hay técnicos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="asc-grid-2">
                <div class="asc-card">
                    <p class="asc-q" style="font-weight: 600">¿Qué ascensores fallan más? <span class="asc-muted" style="font-weight: 400">(90 días)</span></p>
                    @forelse($c['top_failures'] as $f)
                        <p class="asc-row">
                            @if($f['elevator'])
                                <a class="asc-link" href="{{ \App\Filament\Resources\Elevators\ElevatorResource::getUrl('view', ['record' => $f['elevator']]) }}">{{ $f['message'] }}</a>
                            @else
                                {{ $f['message'] }}
                            @endif
                        </p>
                    @empty
                        <p class="asc-c">Sin reportes ni reclamos en 90 días.</p>
                    @endforelse
                </div>
                @if($c['quotes'])
                    <div class="asc-card">
                        <p class="asc-q">{{ $c['quotes']['question'] }}</p>
                        <p class="asc-v">{{ $c['quotes']['value'] }}</p>
                        <p class="asc-c">{{ $c['quotes']['context'] }}</p>
                    </div>
                @endif
            </div>
        </section>
    @else
        @include('filament.pages.partials.insight-locked', ['title' => 'Indicadores de empresa', 'text' => 'evolución de 6 meses, desempeño de cada técnico, ascensores que más fallan y aprobación de presupuestos.', 'plan' => 'Profesional', 'url' => $upsell['company']])
    @endif

    @if($a = $sections['advanced'])
        <section class="asc-stack">
            <h2 class="asc-h2">Este año contra el anterior</h2>
            <div class="asc-grid">
                @foreach($a['comparison'] as $m)
                    <div class="asc-card">
                        <p class="asc-q">{{ $m['label'] }}</p>
                        <p class="asc-v">{{ str_contains($m['label'], '$') ? $money($m['now']) : $m['now'] }}</p>
                        <p class="asc-c">antes: {{ str_contains($m['label'], '$') ? $money($m['before']) : $m['before'] }}@if($m['change'] !== null) · {{ $m['change'] > 0 ? '+' : '' }}{{ $m['change'] }}%@endif</p>
                    </div>
                @endforeach
            </div>

            <h2 class="asc-h2">Tu cartera</h2>
            <div class="asc-grid">
                @foreach($a['portfolio'] as $kpi)
                    <div class="asc-card">
                        <p class="asc-q">{{ $kpi['question'] }}</p>
                        <p class="asc-v">{{ $kpi['value'] }}</p>
                        <p class="asc-c">{{ $kpi['context'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="asc-grid-2">
                <div class="asc-card">
                    <p class="asc-q" style="font-weight: 600">Clientes que más facturan (por mes)</p>
                    @forelse($a['top_clients'] as $client)
                        <p class="asc-row"><span>{{ $client['client'] }}</span><span>{{ $money($client['monthly']) }} · {{ $client['share'] }}%</span></p>
                    @empty
                        <p class="asc-c">Sin contratos activos.</p>
                    @endforelse
                </div>
                <div class="asc-card">
                    <p class="asc-q" style="font-weight: 600">¿Las fallas suben o bajan? <span class="asc-muted" style="font-weight: 400">(por trimestre)</span></p>
                    @foreach($a['quarters'] as $q)
                        <p class="asc-row"><span>{{ $q['label'] }}</span><span>{{ $q['claims'] }}</span></p>
                    @endforeach
                </div>
            </div>
        </section>
    @else
        @include('filament.pages.partials.insight-locked', ['title' => 'Indicadores avanzados', 'text' => 'comparativa contra el año anterior, análisis de cartera, dependencia de clientes y tendencia de fallas.', 'plan' => 'Empresa', 'url' => $upsell['advanced']])
    @endif
</x-filament-panels::page>
