{{--
    Resumen previo de la asignación masiva (BulkAssignmentService::plan):
    qué cambia en cada edificio, cuáles ya tienen técnico y cuáles se
    saltean y por qué. No escribe nada: al confirmar, el servidor vuelve a
    calcular todo antes de aplicar.
    $plan (array) o $error (string)
--}}
@php
    $types = \App\Services\Insights\MaintenanceAgenda::TYPES;
@endphp
<div class="asc-bulk" data-bulk-preview>
    <style>
        .asc-bulk { font-size: .875rem; }
        .asc-bulk-summary { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .75rem; }
        .asc-bulk-chip { border-radius: 999px; padding: .15rem .65rem; font-weight: 600; font-size: .8rem; background: #f3f4f6; color: #374151; }
        .asc-bulk-chip.is-change { background: #dcfce7; color: #166534; }
        .asc-bulk-chip.is-skipped { background: #fee2e2; color: #991b1b; }
        .asc-bulk-list { max-height: 22rem; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: .75rem; }
        .asc-bulk-row { padding: .6rem .8rem; border-top: 1px solid #f3f4f6; }
        .asc-bulk-row:first-child { border-top: 0; }
        .asc-bulk-row strong { overflow-wrap: anywhere; }
        .asc-bulk-muted { color: #6b7280; font-size: .8rem; overflow-wrap: anywhere; }
        .asc-bulk-warn { color: #92400e; font-size: .8rem; }
        .asc-bulk-skip { color: #991b1b; font-size: .8rem; font-weight: 600; }
        .dark .asc-bulk-list { border-color: rgba(255,255,255,.1); }
        .dark .asc-bulk-row { border-color: rgba(255,255,255,.06); }
        .dark .asc-bulk-chip { background: rgba(255,255,255,.08); color: #d1d5db; }
    </style>

    @if(isset($error))
        <p class="asc-bulk-skip" role="alert">{{ $error }}</p>
    @else
        <p style="margin-bottom: .5rem">
            <strong>{{ $types[$plan['type']] }}</strong> ·
            {{ \App\Services\Buildings\BulkAssignmentService::MODES[$plan['mode']] }}
            @if($plan['technicians']->isNotEmpty()) · {{ $plan['technicians']->pluck('name')->implode(', ') }} @endif
            @if($plan['frequency']) · Frecuencia: {{ $plan['frequency']['label'] }} desde {{ $plan['frequency']['start_label'] }} @endif
        </p>
        <div class="asc-bulk-summary">
            <span class="asc-bulk-chip is-change">{{ $plan['counts']['change'] }} con cambios de técnicos</span>
            <span class="asc-bulk-chip">{{ $plan['counts']['unchanged'] }} sin cambios de técnicos</span>
            @if($plan['counts']['skipped'])<span class="asc-bulk-chip is-skipped">{{ $plan['counts']['skipped'] }} se saltean</span>@endif
        </div>
        <div class="asc-bulk-list">
            @foreach($plan['rows'] as $row)
                <div class="asc-bulk-row" data-outcome="{{ $row['outcome'] }}">
                    <strong>{{ $row['name'] }}</strong>
                    @if($row['address'])<span class="asc-bulk-muted"> · {{ $row['address'] }}</span>@endif
                    @if($row['outcome'] === 'skipped')
                        <div class="asc-bulk-skip">Se saltea: {{ $row['reason'] }}</div>
                    @else
                        @if($row['current'] !== [])
                            <div class="asc-bulk-warn">Ya tiene: {{ implode(', ', $row['current']) }}</div>
                        @endif
                        @if($row['outcome'] === 'change')
                            <div class="asc-bulk-muted">Queda: {{ implode(', ', $row['result']) ?: '—' }}</div>
                        @elseif($row['reason'])
                            <div class="asc-bulk-muted">{{ $row['reason'] }}</div>
                        @endif
                        @if($row['frequency'])
                            <div class="asc-bulk-muted">Frecuencia: {{ $plan['frequency']['label'] }} desde {{ $row['frequency_from'] ? ucfirst(\Carbon\Carbon::createFromFormat('!Y-m', $row['frequency_from'])->locale('es')->translatedFormat('F Y')) : $plan['frequency']['start_label'] }}</div>
                            @if($row['frequency_note'] ?? null)
                                <div class="asc-bulk-muted" data-frequency-note>{{ $row['frequency_note'] }}</div>
                            @endif
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
        <p class="asc-bulk-muted" style="margin-top: .5rem">Al confirmar se aplica todo junto: si algo falla, no se guarda nada. Los técnicos reciben el aviso después de guardar.</p>
    @endif
</div>
