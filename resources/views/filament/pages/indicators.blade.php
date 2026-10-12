@php
    $money = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    // Un administrativo solo recibe los números de sus áreas (Indicators::allows).
    $c = $sections['company'];
    $a = $sections['advanced'];
    $p = $sections['period'];
    $companyHasAny = $c && ($c['evolution'] || $c['technicians'] || $c['top_failures'] !== null || $c['quotes']);
    $advancedHasAny = $a && ($a['comparison']->isNotEmpty() || $a['portfolio'] || $a['top_clients'] !== null || $a['quarters'] !== null);
    $nothing = ! $sections['basic'] && ! ($p['items'] ?? []) && ! $companyHasAny && ! $advancedHasAny;
    $labels = ['maintenance' => 'Mantenimientos cumplidos', 'claims' => 'Reportes y reclamos', 'completed' => 'Órdenes terminadas', 'close_days' => 'Días para cerrar una orden'];
    $techLabels = ['visits' => 'Mantenimientos e inspecciones', 'orders' => 'Órdenes terminadas', 'close_days' => 'Días promedio por orden'];
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    @if($nothing && ! $showUpsell)
        <div class="asc-card" data-indicators-empty>
            <p class="asc-q">No hay indicadores disponibles con tus permisos.</p>
            <p class="asc-c">Cada indicador se muestra solo si tenés acceso a las áreas de las que sale (por ejemplo, Órdenes de trabajo o Presupuestos). Pedile al dueño de la cuenta que revise tus permisos.</p>
        </div>
    @endif

    <div class="asc-grid">
        @foreach($sections['basic'] as $kpi)
            <div class="asc-card">
                <p class="asc-q">{{ $kpi['question'] }}</p>
                <p class="asc-v">{{ $kpi['value'] }}</p>
                <p class="asc-c">{{ $kpi['context'] }}</p>
            </div>
        @endforeach
    </div>

    @if($p && $p['items'])
        <section class="asc-stack" data-indicators-period>
            <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.75rem">
                <h2 class="asc-h2" style="margin:0">Trabajo del mes</h2>
                <label class="asc-small asc-muted" style="display:flex; align-items:center; gap:.5rem">
                    Mes
                    <select wire:model.live="period" class="fi-select-input" style="border-radius:.5rem; padding:.3rem 2rem .3rem .6rem; font-size:.875rem" data-period-select>
                        @foreach($months as $value => $text)
                            <option value="{{ $value }}" @selected($value === $month)>{{ $text }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="asc-grid">
                @foreach($p['items'] as $kpi)
                    <div class="asc-card" data-indicator="{{ $kpi['key'] }}">
                        <p class="asc-q">{{ $kpi['question'] }}</p>
                        <p class="asc-v">{{ $kpi['value'] }}</p>
                        <p class="asc-c">{{ $kpi['context'] }}</p>
                        <details class="asc-small asc-muted" style="margin-top:.4rem">
                            <summary style="cursor:pointer">Cómo se calcula</summary>
                            <p style="margin:.3rem 0 0">{{ $kpi['definition'] }}</p>
                        </details>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($c)
        <section class="asc-stack">
            @if($c['evolution'])
                @php $cols = array_keys(array_diff_key($c['evolution']->first(), ['month' => true])); @endphp
                <h2 class="asc-h2">¿Cómo venimos los últimos 6 meses?</h2>
                <div class="asc-card asc-scroll">
                    <table class="asc-table">
                        <thead><tr><th>Mes</th>@foreach($cols as $col)<th>{{ $labels[$col] }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($c['evolution'] as $m)
                                <tr><td>{{ $m['month'] }}</td>@foreach($cols as $col)<td>{{ $m[$col] ?? '—' }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($c['technicians'] !== null)
                @php $tcols = $c['technician_columns']; @endphp
                <h2 class="asc-h2">¿Cómo trabaja cada técnico? <span class="asc-small asc-muted" style="font-weight: 400">(últimos 90 días)</span></h2>
                <div class="asc-card asc-scroll">
                    <table class="asc-table">
                        <thead><tr><th>Técnico</th>@foreach($tcols as $col)<th>{{ $techLabels[$col] }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse($c['technicians'] as $t)
                                <tr><td>{{ $t['name'] }}</td>@foreach($tcols as $col)<td>{{ $t[$col] ?? '—' }}</td>@endforeach</tr>
                            @empty
                                <tr><td colspan="{{ count($tcols) + 1 }}" class="asc-muted">Todavía no hay técnicos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="asc-grid-2">
                @if($c['top_failures'] !== null)
                <div class="asc-card">
                    <p class="asc-q" style="font-weight: 600">¿Qué ascensores fallan más? <span class="asc-muted" style="font-weight: 400">(90 días)</span></p>
                    @forelse($c['top_failures'] as $f)
                        <p class="asc-row">
                            @if($f['elevator'] && \App\Filament\Resources\Elevators\ElevatorResource::canView($f['elevator']))
                                <a class="asc-link" href="{{ \App\Filament\Resources\Elevators\ElevatorResource::getUrl('view', ['record' => $f['elevator']]) }}">{{ $f['message'] }}</a>
                            @else
                                {{ $f['message'] }}
                            @endif
                        </p>
                    @empty
                        <p class="asc-c">Sin reportes ni reclamos en 90 días.</p>
                    @endforelse
                </div>
                @endif
                @if($c['quotes'])
                    <div class="asc-card">
                        <p class="asc-q">{{ $c['quotes']['question'] }}</p>
                        <p class="asc-v">{{ $c['quotes']['value'] }}</p>
                        <p class="asc-c">{{ $c['quotes']['context'] }}</p>
                    </div>
                @endif
            </div>
        </section>
    @elseif($showUpsell)
        @include('filament.pages.partials.insight-locked', ['title' => 'Indicadores de empresa', 'text' => 'evolución de 6 meses, desempeño de cada técnico, ascensores que más fallan y aprobación de presupuestos.', 'plan' => 'Profesional', 'url' => $upsell['company']])
    @endif

    @if($a)
        <section class="asc-stack">
            @if($a['comparison']->isNotEmpty())
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
            @endif

            @if($a['portfolio'])
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
            @endif

            <div class="asc-grid-2">
                @if($a['top_clients'] !== null)
                <div class="asc-card">
                    <p class="asc-q" style="font-weight: 600">Clientes que más facturan (por mes)</p>
                    @forelse($a['top_clients'] as $client)
                        <p class="asc-row"><span>{{ $client['client'] }}</span><span>{{ $money($client['monthly']) }} · {{ $client['share'] }}%</span></p>
                    @empty
                        <p class="asc-c">Sin contratos activos.</p>
                    @endforelse
                </div>
                @endif
                @if($a['quarters'] !== null)
                <div class="asc-card">
                    <p class="asc-q" style="font-weight: 600">¿Las fallas suben o bajan? <span class="asc-muted" style="font-weight: 400">(por trimestre)</span></p>
                    @foreach($a['quarters'] as $q)
                        <p class="asc-row"><span>{{ $q['label'] }}</span><span>{{ $q['claims'] }}</span></p>
                    @endforeach
                </div>
                @endif
            </div>
        </section>
    @elseif($showUpsell)
        @include('filament.pages.partials.insight-locked', ['title' => 'Indicadores avanzados', 'text' => 'comparativa contra el año anterior, análisis de cartera, dependencia de clientes y tendencia de fallas.', 'plan' => 'Empresa', 'url' => $upsell['advanced']])
    @endif
</x-filament-panels::page>
