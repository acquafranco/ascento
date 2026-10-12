{{--
    Ficha CRM del cliente. Todo sale de ClientDossier, que ya filtró por
    empresa y por permisos: una sección en null no se muestra (ni se consultó).
--}}
@php
    $client = $getRecord();
    $d = app(\App\Services\Crm\ClientDossier::class)->for($client, auth()->user());
    $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $date = fn ($v) => $v ? $v->format('d/m/Y') : '—';
    $types = ['hospital' => 'Hospital', 'consorcio' => 'Consorcio', 'empresa' => 'Empresa', 'particular' => 'Particular'];
@endphp
@include('filament.partials.insights-styles')
<div class="asc-stack" data-client-dossier>
    <section>
        <div class="asc-card">
            <div class="asc-row"><span class="asc-muted">Tipo</span><span>{{ $types[$client->type] ?? ($client->type ?: '—') }}</span></div>
            <div class="asc-row"><span class="asc-muted">Contacto</span><span>{{ $client->contact_person ?: '—' }}</span></div>
            <div class="asc-row"><span class="asc-muted">Teléfono</span><span>{{ $client->phone ?: '—' }}</span></div>
            <div class="asc-row"><span class="asc-muted">Email</span><span>{{ $client->email ?: '—' }}</span></div>
            <div class="asc-row"><span class="asc-muted">Estado</span><span>{{ $client->trashed() ? 'Desactivado' : ($client->is_active ? 'Activo' : 'Inactivo') }}</span></div>
        </div>
    </section>

    @if($d['summary'])
        <section data-dossier-section="summary">
            <h2 class="asc-h2">Resumen</h2>
            <div class="asc-grid">
                @foreach($d['summary'] as $key => $card)
                    <div class="asc-card" data-summary="{{ $key }}">
                        <p class="asc-q">{{ $card['label'] }}</p>
                        <p class="asc-v">{{ $card['value'] }}</p>
                        @if($card['hint'])<p class="asc-c">{{ $card['hint'] }}</p>@endif
                    </div>
                @endforeach
            </div>
        </section>

        <section data-dossier-section="activity">
            <h2 class="asc-h2">Actividad reciente</h2>
            <div class="asc-card">
                @forelse($d['activity'] as $e)
                    <div class="asc-row">
                        <span class="asc-muted">{{ $date($e['date']) }} · {{ $e['kind'] }}</span>
                        @if($e['url'])<a href="{{ $e['url'] }}" class="asc-link">{{ $e['text'] }}</a>@else<span>{{ $e['text'] }}</span>@endif
                    </div>
                @empty
                    <p class="asc-muted asc-small">Todavía no hay actividad registrada para este cliente.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if($d['buildings'])
        <section data-dossier-section="buildings">
            <h2 class="asc-h2">Edificios y ascensores</h2>
            <div class="asc-card">
                @forelse($d['buildings']['items'] as $b)
                    <div class="asc-row">
                        <span>
                            @if($b['url'])<a href="{{ $b['url'] }}" class="asc-link"><strong>{{ $b['name'] }}</strong></a>@else<strong>{{ $b['name'] }}</strong>@endif
                            @if(! $b['active'])<span class="asc-pill is-pending">Inactivo</span>@endif
                            <br><span class="asc-muted asc-small">{{ $b['address'] ?: 'Sin dirección' }}</span>
                        </span>
                        <span class="asc-small">
                            @forelse($b['elevators'] as $e)
                                @if($e['url'])<a href="{{ $e['url'] }}" class="asc-link">{{ $e['label'] }}</a>@else{{ $e['label'] }}@endif{{ $loop->last ? '' : ' · ' }}
                            @empty
                                <span class="asc-muted">Sin ascensores cargados</span>
                            @endforelse
                        </span>
                    </div>
                @empty
                    <p class="asc-muted asc-small">Este cliente no tiene edificios cargados.</p>
                @endforelse
            </div>
        </section>
    @endif

    @php
        $lists = [
            'quotes' => ['Presupuestos', 'No hay presupuestos para este cliente.'],
            'work_orders' => ['Órdenes de trabajo', 'No hay órdenes de trabajo en sus edificios.'],
            'delivery_notes' => ['Remitos', 'No hay remitos en sus edificios.'],
            'receivables' => ['Cobranzas', 'No hay cobranzas registradas.'],
            'tasks' => ['Tareas abiertas', 'No hay tareas abiertas.'],
            'contacts' => ['Últimos contactos', 'Todavía no se registraron contactos.'],
        ];
    @endphp
    @foreach($lists as $key => [$heading, $empty])
        @if($d[$key])
            <section data-dossier-section="{{ $key }}">
                <h2 class="asc-h2">{{ $heading }}
                    @if($key === 'receivables')<span class="asc-muted asc-small" style="font-weight:400">· saldo {{ $money($d[$key]['balance']) }}@if($d[$key]['overdue'] > 0), vencido {{ $money($d[$key]['overdue']) }}@endif</span>@endif
                </h2>
                <div class="asc-card">
                    @forelse($d[$key]['items'] as $i)
                        <div class="asc-row">
                            <span>
                                @if(! empty($i['url']))<a href="{{ $i['url'] }}" class="asc-link">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif
                                <br><span class="asc-muted asc-small">
                                    {{ $date($i['date']) }}
                                    @isset($i['assignee']) · {{ $i['assignee'] ?: 'Sin asignar' }} @endisset
                                    @isset($i['by']) · {{ $i['by'] }} @endisset
                                    @if(! empty($i['notes'])) · {{ \Illuminate\Support\Str::limit($i['notes'], 120) }} @endif
                                </span>
                            </span>
                            <span class="asc-small" style="text-align:right">
                                @isset($i['amount']){{ $money($i['amount']) }}<br>@endisset
                                @isset($i['status'])<span class="asc-pill {{ ($i['overdue'] ?? false) || in_array($i['status'], ['Vencido', 'Vencida', 'No realizado']) ? 'is-overdue' : '' }}">{{ $i['status'] }}</span>@endisset
                            </span>
                        </div>
                    @empty
                        <p class="asc-muted asc-small">{{ $empty }}</p>
                    @endforelse
                </div>
            </section>
        @endif
    @endforeach
</div>
