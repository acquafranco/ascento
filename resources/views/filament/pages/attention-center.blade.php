@php
    $icons = ['danger' => '🔴', 'warning' => '🟠', 'info' => '🔵'];
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    @foreach([['basic', 'Para hoy'], ['advanced', 'Seguimiento'], ['alerts', 'Alertas avanzadas']] as [$key, $heading])
        @if($sections[$key] !== null)
            <section>
                <h2 class="asc-h2">{{ $heading }}</h2>
                @forelse($sections[$key] as $item)
                    <a href="{{ $item['url'] }}" class="asc-item is-{{ $item['severity'] }}">
                        <div>
                            <p class="asc-item-title">{{ $icons[$item['severity']] }} {{ $item['title'] }}</p>
                            <p class="asc-item-detail">{{ $item['detail'] }}</p>
                            @if($item['examples'])
                                <p class="asc-small asc-muted" style="margin: .25rem 0 0">{{ implode(' · ', $item['examples']) }}</p>
                            @endif
                        </div>
                        <span class="asc-count">{{ $item['count'] }}</span>
                    </a>
                @empty
                    <p class="asc-ok">✅ Nada pendiente acá.</p>
                @endforelse
            </section>
        @elseif($key === 'advanced')
            @include('filament.pages.partials.insight-locked', ['title' => 'Seguimiento', 'text' => 'ascensores con fallas que se repiten, certificados por vencer, fichas incompletas y trabajos atrasados.', 'plan' => 'Profesional', 'url' => $upsell['advanced']])
        @elseif($key === 'alerts')
            @include('filament.pages.partials.insight-locked', ['title' => 'Alertas avanzadas', 'text' => 'fallas en aumento, contratos por vencer y deudas viejas.', 'plan' => 'Empresa', 'url' => $upsell['alerts']])
        @endif
    @endforeach
</x-filament-panels::page>
