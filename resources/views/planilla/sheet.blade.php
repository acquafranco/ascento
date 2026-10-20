{{--
    Planilla del mes de un técnico (TechnicianSheet::month): la misma en la app
    del técnico y en el panel. Estilos propios (ps-*), claros por defecto y
    oscuros dentro de .dark (panel), así no depende del CSS de cada layout.

    $sheet      array del servicio
    $navUrl     fn(int $month, int $year): string
    $remitoUrl  fn(DeliveryNote): ?string   (null = sin enlace)
    $dayUrl     ?fn(Carbon): string         (detalle del día; opcional)
    $self       bool: la ve el propio técnico («vos»)
--}}
@php
    $s = $sheet['summary'];
    $plural = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
    $chip = ['maintenance' => 'is-maint', 'inspection' => 'is-insp', 'work_order' => 'is-order'];
@endphp
@once
<style>
    .ps { --ps-bg: #fff; --ps-line: #E7E9EE; --ps-soft: #F6F7F9; --ps-ink: #11161D; --ps-muted: #6B7280; --ps-accent: #FF6600;
          font-family: inherit; color: var(--ps-ink); display: grid; gap: 14px; }
    .dark .ps { --ps-bg: rgba(255,255,255,.03); --ps-line: rgba(255,255,255,.1); --ps-soft: rgba(255,255,255,.05); --ps-ink: #F3F4F6; --ps-muted: #9CA3AF; }
    .ps * { box-sizing: border-box; }
    .ps-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .ps-nav a, .ps-nav span.ps-off { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; border-radius: 12px; border: 1px solid var(--ps-line); background: var(--ps-bg); color: inherit; text-decoration: none; font-size: 20px; }
    .ps-nav span.ps-off { opacity: .35; }
    .ps-nav h2 { margin: 0; font-size: 20px; font-weight: 800; text-align: center; }
    .ps-sum { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    @media (min-width: 640px) { .ps-sum { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
    .ps-stat { border: 1px solid var(--ps-line); background: var(--ps-bg); border-radius: 14px; padding: 10px 12px; }
    .ps-stat b { display: block; font-size: 22px; line-height: 1.1; font-weight: 800; }
    .ps-stat span { font-size: 12px; color: var(--ps-muted); font-weight: 600; }
    .ps-stat.is-main b { color: var(--ps-accent); }
    .ps-week { border: 1px solid var(--ps-line); background: var(--ps-bg); border-radius: 16px; overflow: hidden; }
    .ps-week-h { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; padding: 12px 14px; background: var(--ps-soft); font-weight: 700; font-size: 14px; }
    .ps-week-h small { color: var(--ps-muted); font-weight: 600; font-size: 12px; }
    .ps-empty { padding: 12px 14px; color: var(--ps-muted); font-size: 14px; }
    .ps-day { border-top: 1px solid var(--ps-line); padding: 10px 14px 12px; }
    .ps-day-h { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 6px; }
    .ps-day-h strong { font-size: 15px; }
    .ps-day-h a { font-size: 13px; font-weight: 600; color: var(--ps-muted); text-decoration: underline; }
    .ps-job { display: grid; grid-template-columns: 52px minmax(0, 1fr); gap: 4px 10px; padding: 8px 0; border-top: 1px dashed var(--ps-line); }
    .ps-job:first-of-type { border-top: 0; }
    .ps-time { font-weight: 700; font-size: 14px; font-variant-numeric: tabular-nums; padding-top: 2px; }
    .ps-main { min-width: 0; }
    .ps-b { font-weight: 700; font-size: 15px; overflow-wrap: anywhere; }
    .ps-meta { font-size: 13px; color: var(--ps-muted); overflow-wrap: anywhere; }
    .ps-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; align-items: center; }
    .ps-chip { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 9px; font-size: 12px; font-weight: 700; }
    .ps-chip.is-maint { background: #DBEAFE; color: #1E3A8A; }
    .ps-chip.is-insp { background: #EDE9FE; color: #4C1D95; }
    .ps-chip.is-order { background: #FFEDD5; color: #9A3412; }
    .ps-chip.is-role { background: var(--ps-soft); color: inherit; border: 1px solid var(--ps-line); font-weight: 600; }
    .ps-remito { display: inline-flex; align-items: center; min-height: 32px; padding: 4px 10px; border-radius: 10px; border: 1px solid var(--ps-line); font-size: 12px; font-weight: 700; color: inherit; text-decoration: none; background: var(--ps-bg); }
    .ps-remito:hover { border-color: var(--ps-accent); }
    .ps-foot { font-size: 12px; color: var(--ps-muted); text-align: center; }
</style>
@endonce
<div class="ps" data-technician-sheet>
    <nav class="ps-nav" aria-label="Cambiar de mes">
        <a href="{{ $navUrl($sheet['prev']['month'], $sheet['prev']['year']) }}" aria-label="Mes anterior" rel="prev">‹</a>
        <h2>{{ $sheet['label'] }}</h2>
        @if($sheet['next'])
            <a href="{{ $navUrl($sheet['next']['month'], $sheet['next']['year']) }}" aria-label="Mes siguiente" rel="next">›</a>
        @else
            <span class="ps-off" aria-hidden="true">›</span>
        @endif
    </nav>

    <div class="ps-sum" data-sheet-summary>
        <div class="ps-stat is-main"><b>{{ $s['total'] }}</b><span>{{ $s['total'] === 1 ? 'trabajo' : 'trabajos' }}</span></div>
        <div class="ps-stat"><b>{{ $s['days'] }}</b><span>{{ $s['days'] === 1 ? 'día trabajado' : 'días trabajados' }}</span></div>
        <div class="ps-stat"><b>{{ $s['maintenance'] }}</b><span>{{ $s['maintenance'] === 1 ? 'mantenimiento' : 'mantenimientos' }}</span></div>
        <div class="ps-stat"><b>{{ $s['inspection'] }}</b><span>{{ $s['inspection'] === 1 ? 'inspección' : 'inspecciones' }}</span></div>
        <div class="ps-stat"><b>{{ $s['work_order'] }}</b><span>{{ $s['work_order'] === 1 ? 'orden de trabajo' : 'órdenes de trabajo' }}</span></div>
    </div>

    @if($s['total'] === 0)
        <div class="ps-week"><p class="ps-empty" style="text-align:center; padding:22px 14px">
            <strong style="display:block; color:var(--ps-ink); margin-bottom:4px">No hay trabajos en {{ mb_strtolower($sheet['label']) }}</strong>
            {{ $self ? 'Cuando firmes remitos, los vas a ver acá día por día.' : 'Cuando el técnico firme remitos, aparecen acá día por día.' }}
        </p></div>
    @else
        @foreach($sheet['weeks'] as $week)
            <section class="ps-week" data-sheet-week>
                <div class="ps-week-h">
                    <span>Semana del {{ $week['start']->format('d/m') }} al {{ $week['end']->format('d/m') }}</span>
                    <small>{{ $week['count'] ? $plural($week['count'], 'trabajo', 'trabajos') : 'Sin trabajos' }}</small>
                </div>
                @foreach($week['days']->filter(fn ($d) => $d['jobs']->isNotEmpty()) as $day)
                    <div class="ps-day" data-sheet-day="{{ $day['date']->toDateString() }}">
                        <div class="ps-day-h">
                            <strong>{{ ucfirst($day['date']->locale('es')->translatedFormat('l d')) }}</strong>
                            @if(! empty($dayUrl))<a href="{{ $dayUrl($day['date']) }}">Ver día</a>@endif
                        </div>
                        @foreach($day['jobs'] as $job)
                            <div class="ps-job" data-sheet-job="{{ $job['id'] }}">
                                <span class="ps-time">{{ $job['time'] }}</span>
                                <div class="ps-main">
                                    <div class="ps-b">{{ $job['building'] }}</div>
                                    <div class="ps-meta">{{ collect([$job['address'], $job['client']])->filter()->implode(' · ') }}</div>
                                    <div class="ps-tags">
                                        <span class="ps-chip {{ $chip[$job['kind']] ?? 'is-maint' }}">{{ $job['label'] }}</span>
                                        <span class="ps-chip is-role">{{ $job['role'] === 'Solo' ? ($self ? 'Lo hiciste vos' : 'Lo hizo solo') : $job['role'] }}</span>
                                        @if($job['note'] && ($url = $remitoUrl($job['note'])))
                                            <a class="ps-remito" href="{{ $url }}" @if(str_contains($url, '/public/')) target="_blank" rel="noopener" @endif>Remito {{ $job['note']->number }}</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endforeach
    @endif

    <p class="ps-foot">Se cuentan los trabajos con remito: mantenimientos y órdenes de trabajo con participación, e inspecciones realizadas.</p>
</div>
