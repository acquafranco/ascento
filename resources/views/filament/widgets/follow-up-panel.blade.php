{{--
    Seguimiento comercial (DashboardSummary::followUp). Una línea en null no
    se consultó (sin permiso o sin plan) y no se muestra.
--}}
@php
    $icons = ['danger' => '🔴', 'warning' => '🟠', 'info' => '🔵'];
    $line = fn (?string $url) => $url ? 'a' : 'div';
@endphp
<x-filament-widgets::widget>
    @include('filament.partials.insights-styles')
    <x-filament::section heading="Seguimiento comercial">
        <div class="asc-grid" data-follow-up>
            @if($t = $data['tasks'])
                <{{ $line($t['url']) }} @if($t['url']) href="{{ $t['url'] }}" @endif class="asc-card asc-link" data-follow="tasks">
                    <p class="asc-q">Tareas vencidas</p>
                    <p class="asc-v" @if($t['overdue']) style="color:#dc2626" @endif>{{ $t['overdue'] }}</p>
                    <p class="asc-c">{{ $t['open'] }} abiertas · {{ $t['mine'] }} asignadas a vos</p>
                </{{ $line($t['url']) }}>
            @endif
            @if($c = $data['contacts'])
                <{{ $line($c['url']) }} @if($c['url']) href="{{ $c['url'] }}" @endif class="asc-card asc-link" data-follow="contacts">
                    <p class="asc-q">Contactos para seguir</p>
                    <p class="asc-v">{{ $c['count'] }}</p>
                    <p class="asc-c">Requieren seguimiento o esperan respuesta (últimos {{ $c['days'] }} días)</p>
                </{{ $line($c['url']) }}>
            @endif
            @if($q = $data['expiring'])
                <{{ $line($q['url']) }} @if($q['url']) href="{{ $q['url'] }}" @endif class="asc-card asc-link" data-follow="expiring">
                    <p class="asc-q">Presupuestos por vencer</p>
                    <p class="asc-v">{{ $q['count'] }}</p>
                    <p class="asc-c">Enviados que vencen en los próximos 5 días</p>
                </{{ $line($q['url']) }}>
            @endif
        </div>

        @if($a = $data['alerts'])
            <div style="margin-top:1rem" data-follow="alerts">
                <div style="display:flex; justify-content:space-between; align-items:baseline; gap:.5rem">
                    <p class="asc-q" style="font-weight:600; margin:0">Para atender</p>
                    @if($a['url'])
                        <a href="{{ $a['url'] }}" class="asc-small asc-link" style="color:#d97706">Ver el Centro de atención{{ $a['more'] ? ' (+'.$a['more'].')' : '' }} →</a>
                    @endif
                </div>
                @forelse($a['items'] as $item)
                    {{-- Sin acceso al destino: sin enlace (nunca un 403). --}}
                    <{{ $line($item['url']) }} @if($item['url']) href="{{ $item['url'] }}" @endif class="asc-item is-{{ $item['severity'] }}" style="margin-top:.5rem">
                        <p class="asc-item-title" style="margin:0">{{ $icons[$item['severity']] ?? '' }} {{ $item['title'] }}</p>
                        <span class="asc-count">{{ $item['count'] }}</span>
                    </{{ $line($item['url']) }}>
                @empty
                    <p class="asc-ok" style="margin-top:.5rem">✅ Nada pendiente.</p>
                @endforelse
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
