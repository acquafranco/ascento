<x-portal-layout title="Reporte">
    <a class="p-back" href="{{ route('portal.building', $report->building) }}">← {{ $report->building?->name }}</a>
    <h1 class="p-h1">Reporte técnico del {{ $report->created_at?->format('d/m/Y') }}</h1>
    <p class="p-sub">
        {{ $report->elevator_number }}
        · <span class="p-tag {{ ['pendiente' => 'warn', 'en_revision' => 'info', 'resuelto' => 'ok'][$report->status] ?? '' }}">{{ \App\Models\Report::STATUS_LABELS[$report->status] ?? $report->status }}</span>
        · Prioridad {{ \App\Models\Report::PRIORITY_LABELS[$report->priority] ?? $report->priority }}
    </p>

    <div class="p-text">{{ $report->description }}</div>
    @if(filled($report->observations))
        <section class="p-sec"><h2>Observaciones</h2><div class="p-text" style="margin-top:8px">{{ $report->observations }}</div></section>
    @endif

    <section class="p-sec">
        <h2 style="margin-bottom:8px">Fotos</h2>
        @if($report->photos->isEmpty())
            <div class="p-empty">Este reporte no tiene fotos.</div>
        @else
            <div class="p-photos">
                @foreach($report->photos as $photo)
                    <a href="{{ route('portal.report-photo', [$report, $photo]) }}" target="_blank" rel="noopener">
                        <img src="{{ route('portal.report-photo', [$report, $photo]) }}" alt="Foto {{ $loop->iteration }} del reporte" loading="lazy">
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-portal-layout>
