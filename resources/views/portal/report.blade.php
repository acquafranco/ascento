@php
    $priorities = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];
    $statuses = ['pendiente' => 'Pendiente', 'en_revision' => 'En revisión', 'resuelto' => 'Resuelto'];
@endphp
<x-portal-layout :company="$company" title="Reporte">
    <a class="p-back" href="{{ route('portal.building', $report->building) }}">← {{ $report->building?->name }}</a>
    <h1 class="p-h1">Reporte del {{ $report->created_at?->format('d/m/Y') }}</h1>
    <p class="p-sub">{{ $report->elevator_number }} · Prioridad {{ $priorities[$report->priority] ?? $report->priority }} · {{ $statuses[$report->status] ?? $report->status }}</p>

    <div class="p-text">{{ $report->description }}</div>

    <section class="p-sec">
        <h2>Fotos</h2>
        @if($report->photos->isEmpty())
            <div class="p-empty">Este reporte no tiene fotos.</div>
        @else
            <div class="p-photos">
                @foreach($report->photos as $photo)
                    <a href="{{ route('portal.report-photo', [$report, $photo]) }}" target="_blank" rel="noopener">
                        <img src="{{ route('portal.report-photo', [$report, $photo]) }}" alt="Foto {{ $loop->iteration }}" loading="lazy">
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-portal-layout>
