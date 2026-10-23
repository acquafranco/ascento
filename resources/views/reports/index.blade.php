{{-- Reportes del técnico (los propios), del más nuevo al más viejo. --}}
@php
    use App\Support\TechNav;
    $priority = ['critica' => ['Crítica', 't-chip-failed'], 'alta' => ['Alta', 't-chip-progress'], 'media' => ['Media', 't-chip-pending'], 'baja' => ['Baja', 't-chip-neutral']];
    $status = ['pendiente' => ['Pendiente', 't-chip-pending'], 'en_revision' => ['En revisión', 't-chip-progress'], 'resuelto' => ['Resuelto', 't-chip-done']];
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h1 class="t-h1">Reportes</h1>
            <p class="t-sub">Fallas que cargaste e intervenciones que atendiste.</p>
        </div>
        <a href="{{ route('reports.create', ['company' => $company->slug]) }}" class="t-btn-secondary shrink-0">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">{!! TechNav::icon('plus') !!}</svg>
            Reportar falla
        </a>
    </header>

    {{-- Urgencias y trabajos no programados: el técnico abre la orden y hace el remito sin esperar a la oficina. --}}
    @if(\App\Services\Interventions\InterventionService::canUse(auth()->user()))
        <a href="{{ route('interventions.create', ['company' => $company->slug]) }}" class="t-card mb-4 flex items-center gap-3 border-brand-200 bg-brand-50 px-4 py-3 hover:bg-brand-100" data-new-intervention>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand text-white">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">{!! TechNav::icon('plus') !!}</svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-semibold text-ink">Registrar intervención o urgencia</span>
                <span class="block text-sm text-ink-700">Un reclamo por WhatsApp, por teléfono o del encargado: lo documentás y hacés el remito firmado sin esperar a la oficina.</span>
            </span>
        </a>
    @endif

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif

    @if($reports->isEmpty())
        <div class="t-empty">
            <p class="font-semibold text-ink">Todavía no cargaste reportes</p>
            <p class="mt-1">Si encontrás una falla, cargala con fotos o un video y la oficina recibe el aviso. Si atendés una urgencia, registrala como intervención.</p>
        </div>
    @else
        <div class="t-card t-divide overflow-hidden">
            @foreach($reports as $report)
                {{-- Una intervención abre su orden (trabajos de la visita y remito); una falla, su detalle. --}}
                <a href="{{ $report->isIntervention() && $report->work_order_id ? route('work-orders.show', ['company' => $company->slug, 'workOrder' => $report->work_order_id]) : route('reports.show', ['company' => $company->slug, 'report' => $report->id]) }}" class="t-row hover:bg-ink-50">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-1.5">
                            @if($report->isIntervention())<span class="t-chip-progress">Intervención</span>@endif
                            <span class="{{ $status[$report->status][1] ?? 't-chip-neutral' }}">{{ $status[$report->status][0] ?? ucfirst($report->status) }}</span>
                            <span class="{{ $priority[$report->priority][1] ?? 't-chip-neutral' }}">{{ $priority[$report->priority][0] ?? ucfirst($report->priority) }}</span>
                        </span>
                        <span class="mt-1 block truncate font-semibold text-ink">{{ $report->building?->name ?? 'Edificio sin asignar' }}</span>
                        <span class="block truncate text-sm text-ink-500">{{ $report->elevator_number ?? 'Equipo no indicado' }} · {{ Str::limit($report->description, 60) }}</span>
                    </span>
                    <span class="shrink-0 text-right text-xs text-ink-500">
                        <span class="block font-semibold text-ink">{{ $report->created_at->format('d/m') }}</span>
                        {{ $report->created_at->format('H:i') }}
                    </span>
                </a>
            @endforeach
        </div>
        @if($reports->hasPages())
            <div class="mt-4">{{ $reports->links() }}</div>
        @endif
    @endif
</div>
</x-app-layout>
