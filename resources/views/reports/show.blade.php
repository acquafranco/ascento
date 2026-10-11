{{-- Detalle de un reporte propio: datos, fotos, video y PDF. --}}
@php
    $priority = ['critica' => ['Crítica', 't-chip-failed'], 'alta' => ['Alta', 't-chip-progress'], 'media' => ['Media', 't-chip-pending'], 'baja' => ['Baja', 't-chip-neutral']];
    $status = ['pendiente' => ['Pendiente', 't-chip-pending'], 'en_revision' => ['En revisión', 't-chip-progress'], 'resuelto' => ['Resuelto', 't-chip-done']];
@endphp
<x-app-layout>
<div class="t-page">
    <a href="{{ route('reports.index', ['company' => $company->slug]) }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Reportes
    </a>

    @if(session('success') || session('status'))
        <div class="t-alert-success my-3" role="status">{{ session('success') ?? session('status') }}</div>
    @endif

    <article class="t-card mt-1 overflow-hidden">
        <div class="p-4">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="{{ $status[$report->status][1] ?? 't-chip-neutral' }}">{{ $status[$report->status][0] ?? ucfirst($report->status) }}</span>
                <span class="{{ $priority[$report->priority][1] ?? 't-chip-neutral' }}">Prioridad {{ mb_strtolower($priority[$report->priority][0] ?? $report->priority) }}</span>
                <span class="ml-auto text-xs font-semibold text-ink-500">{{ $report->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <h1 class="mt-2.5 text-xl font-bold leading-snug text-ink">{{ $report->building?->name ?? 'Edificio sin asignar' }}</h1>
            <p class="text-sm text-ink-500">{{ $report->building?->address ?? 'Sin dirección' }} · <span class="font-semibold text-ink-700">{{ $report->elevator_number ?? 'Equipo no indicado' }}</span></p>
            @if($report->component)
                <p class="mt-1 text-sm text-ink-500">Componente: <span class="font-semibold text-ink-700">{{ \App\Support\ElevatorComponents::LIST[$report->component] ?? $report->component }}</span></p>
            @endif

            <div class="mt-4 rounded-xl bg-ink-50 px-3 py-2.5">
                <div class="text-[11px] font-bold uppercase tracking-wider text-ink-500">Descripción</div>
                <p class="mt-0.5 whitespace-pre-line text-[15px] leading-6 text-ink">{{ $report->description }}</p>
            </div>

            @if($report->photos->isNotEmpty())
                <h2 class="t-section">Fotos ({{ $report->photos->count() }})</h2>
                <div class="grid grid-cols-3 gap-2">
                    @foreach($report->photos as $photo)
                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener" class="block aspect-square overflow-hidden rounded-xl border border-ink-100 bg-ink-50">
                            <img src="{{ $photo->url() }}?thumb=1" loading="lazy" class="block h-full w-full object-cover" alt="Foto {{ $loop->iteration }} del reporte">
                        </a>
                    @endforeach
                </div>
            @endif

            @if($report->video)
                <h2 class="t-section">Video</h2>
                @include('partials.report-video', ['video' => $report->video, 'src' => route('reports.video', $report), 'statusUrl' => route('reports.video.status', $report)])
            @endif
        </div>
        <div class="border-t border-ink-100 bg-ink-50/60 p-3">
            <a href="{{ route('reports.pdf', $report) }}" target="_blank" rel="noopener" class="t-btn-secondary w-full">Descargar PDF</a>
        </div>
    </article>
</div>
</x-app-layout>
