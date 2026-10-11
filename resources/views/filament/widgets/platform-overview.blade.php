@php
    $o = \App\Support\PlatformOverview::get();
    $companyUrl = fn ($c) => \App\Filament\Resources\Companies\CompanyResource::getUrl('view', ['record' => $c]);
    $listUrl = \App\Filament\Resources\Companies\CompanyResource::getUrl('index');
@endphp
<x-filament-widgets::widget>
    @include('filament.partials.insights-styles')
    <div class="asc-stack">
        <section>
            <h2 class="asc-h2">Empresas <span class="asc-muted asc-small" style="font-weight:400">· datos reales</span></h2>
            <div class="asc-grid">
                <a class="asc-card asc-link" href="{{ $listUrl }}"><p class="asc-q">Registradas</p><p class="asc-v">{{ $o['companies']['total'] }}</p><p class="asc-c">{{ $o['companies']['disabled'] }} desactivadas o deshabilitadas aparte</p></a>
                <div class="asc-card"><p class="asc-q">Altas</p><p class="asc-v">{{ $o['companies']['last7'] }}</p><p class="asc-c">últimos 7 días · {{ $o['companies']['last30'] }} en 30 días</p></div>
                <a class="asc-card asc-link" href="{{ $listUrl }}?tableFilters[email_verification][value]=unverified"><p class="asc-q">Correo sin confirmar</p><p class="asc-v">{{ $o['companies']['unverified'] }}</p><p class="asc-c">el responsable no abrió el enlace</p></a>
                <div class="asc-card"><p class="asc-q">Técnicos</p><p class="asc-v">{{ $o['users']['technicians'] }}</p><p class="asc-c">y {{ $o['users']['portal'] }} usuarios del portal</p></div>
            </div>
        </section>

        <section>
            <h2 class="asc-h2">Suscripciones <span class="asc-muted asc-small" style="font-weight:400">· calculado (fechas y avisos de Mercado Pago)</span></h2>
            <div class="asc-grid">
                <div class="asc-card"><p class="asc-q">En prueba gratis</p><p class="asc-v">{{ $o['subscriptions']['trial'] }}</p><p class="asc-c">{{ $o['subscriptions']['trial_ending'] }} terminan en 7 días o menos</p></div>
                <div class="asc-card"><p class="asc-q">Pagando</p><p class="asc-v">{{ $o['subscriptions']['active'] }}</p><p class="asc-c">activas (incluye canceladas con días pagos)</p></div>
                <div class="asc-card"><p class="asc-q">Para revisar</p><p class="asc-v" @if($o['subscriptions']['attention']) style="color:#d97706" @endif>{{ $o['subscriptions']['attention'] }}</p><p class="asc-c">pago rechazado o sin terminar</p></div>
                <div class="asc-card"><p class="asc-q">Terminadas</p><p class="asc-v">{{ $o['subscriptions']['ended'] }}</p><p class="asc-c">prueba vencida, vencidas o canceladas</p></div>
            </div>
        </section>

        <section>
            <h2 class="asc-h2">Salud operativa <span class="asc-muted asc-small" style="font-weight:400">· datos reales</span></h2>
            <div class="asc-grid">
                <div class="asc-card"><p class="asc-q">Último backup completo</p>
                    <p class="asc-v" style="font-size:1.1rem; @if(! $o['health']['last_backup'] || $o['health']['last_backup']->lt(now()->subHours(26))) color:#dc2626 @endif">{{ $o['health']['last_backup']?->format('d/m H:i') ?? 'Ninguno' }}</p>
                    <p class="asc-c">{{ $o['health']['failed_backups'] }} fallidos en 48 h</p></div>
                <div class="asc-card"><p class="asc-q">Exportaciones fallidas</p><p class="asc-v">{{ $o['health']['failed_exports'] }}</p><p class="asc-c">últimos 7 días</p></div>
                <div class="asc-card"><p class="asc-q">Videos que no se pudieron procesar</p><p class="asc-v">{{ $o['health']['failed_videos'] }}</p><p class="asc-c">últimos 7 días</p></div>
            </div>
        </section>

        <section>
            <h2 class="asc-h2">Últimas altas</h2>
            @if($o['recent']->isEmpty())
                <p class="asc-locked">Todavía no hay empresas registradas.</p>
            @else
                <div class="asc-card asc-scroll">
                    <table class="asc-table">
                        <thead><tr><th>Empresa</th><th>Responsable</th><th>Alta</th><th>Correo</th><th>Suscripción</th></tr></thead>
                        <tbody>
                            @foreach($o['recent'] as $row)
                                @php($c = $row['company'])
                                <tr>
                                    <td><a class="asc-link" style="font-weight:600" href="{{ $companyUrl($c) }}">{{ $c->name }}</a></td>
                                    <td>{{ $c->owner?->name ?? '—' }}</td>
                                    <td>{{ $c->created_at?->format('d/m/Y') }}</td>
                                    <td><span class="asc-pill {{ $c->owner?->hasVerifiedEmail() ? 'is-done' : 'is-pending' }}">{{ $c->owner?->hasVerifiedEmail() ? 'Confirmado' : 'Sin confirmar' }}</span></td>
                                    <td>{{ $row['status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-widgets::widget>
