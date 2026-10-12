{{--
    Una visita (mantenimiento o inspección, en curso o con remito) o una
    orden de trabajo, con sus fotos de antes y después. Las fotos se cargan
    acá antes, durante o después del trabajo, de a una: cada una queda
    guardada al instante (un error del remito o un corte de señal no las
    pierde) y no hacen falta para firmar el remito.
    Si la empresa no habilitó las fotos para este tipo de trabajo, solo se
    ven las que ya existían (sin controles de carga).
--}}
@php
    $slug = $company->slug;
    $typeLabel = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Orden de trabajo'][$target->type];
    $inProgress = $visit?->in_progress;
    $back = $target->type === 'work_order'
        ? route('work-orders.show', ['company' => $slug, 'workOrder' => $workOrder->id])
        : route('buildings.index', ['company' => $slug, 'month' => $visit->month, 'year' => $visit->year]);
@endphp
<x-app-layout>
<div class="t-page max-w-2xl lg:max-w-2xl">
    <a href="{{ $back }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $target->type === 'work_order' ? 'Orden de trabajo' : 'Visitas' }}
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">{{ $building->name }}</h1>
        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            <span class="t-chip-progress">{{ $typeLabel }}{{ $visit && $target->type !== 'work_order' && $period && $period->expected > 1 ? ' · visita '.$visit->slot.' de '.$period->expected : '' }}</span>
            @if($inProgress)
                <span class="t-chip-pending" data-visit-state="in_progress">En curso · sin remito</span>
            @elseif($deliveryNote)
                <span class="{{ $deliveryNote->performed === false ? 't-chip-failed' : 't-chip-done' }}" data-visit-state="signed">Remito #{{ $deliveryNote->number }}{{ $deliveryNote->performed === false ? ' · no realizado' : '' }}</span>
            @elseif($workOrder)
                <span class="t-chip-neutral" data-visit-state="work_order">Orden #{{ $workOrder->id }}</span>
            @endif
        </div>
        @if($period)
            <p class="mt-1 text-xs text-ink-500">Período {{ $period->label() }} · {{ $period->frequencyLabel() }}</p>
        @endif
    </header>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="t-alert-error mb-4" role="alert">{{ $errors->first() }}</div>
    @endif


    @include('photos.manager')

    @if($inProgress)
        <section class="t-card mt-4 p-4">
            <p class="text-sm text-ink-700">Sacá fotos cuando quieras: antes, durante o después del trabajo. Cuando termines, hacé el remito: completa esta misma visita y sus fotos quedan con él. Las fotos no son obligatorias.</p>
            <a href="{{ route('delivery-notes.building', ['company' => $slug, 'building' => $building->id, 'month' => $visit->month, 'year' => $visit->year, 'assignment_type' => $target->type]) }}"
               class="t-btn-primary mt-3 w-full" data-make-remito>Hacer el remito</a>
        </section>
    @endif

    @if($inProgress && ! $deliveryNote)
        <form method="POST" action="{{ route('visits.discard', ['company' => $slug, 'visit' => $visit->id]) }}" class="mt-4"
              onsubmit="return confirm('¿Descartar esta visita? Se borran también sus fotos. No se puede deshacer.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="t-btn-secondary w-full text-red-700" data-discard-visit>Descartar visita en curso</button>
        </form>
    @endif
</div>

</x-app-layout>
