@php
    $slug = auth()->user()->company->slug;
    $building = $workOrder->building;
    $address = trim(($building?->address ?? '').', '.($building?->locality ?? ''), ', ');
@endphp
<x-app-layout>
<div class="t-page">
    <a href="{{ route('work-orders.index', ['company' => $slug]) }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Trabajos
    </a>
    <h1 class="t-h1 mb-4 mt-1">Orden de trabajo</h1>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif

    @include('work-orders.partials.card', ['workOrder' => $workOrder])

    {{-- Trabajos de esta visita (intervenciones): cada problema por separado, en el mismo remito. --}}
    @php
        $canAddJobs = $workOrder->acceptsJobsFrom(auth()->user());
    @endphp
    @if($workOrder->jobs->isNotEmpty() || $canAddJobs)
        <section class="t-card mt-3 overflow-hidden" data-visit-jobs>
            <div class="px-4 pt-3">
                <h2 class="text-xs font-bold uppercase tracking-wider text-ink-500">Trabajos de esta visita ({{ $workOrder->jobs->count() }})</h2>
            </div>
            @foreach($workOrder->jobs as $i => $job)
                <div class="border-t border-ink-100 px-4 py-3 first:border-t-0" data-visit-job="{{ $job->id }}">
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <span class="font-bold text-ink">Trabajo {{ $i + 1 }}</span>
                        <span class="t-chip-neutral">{{ $job->elevator_number }}</span>
                        @if($job->priority === 'critica')<span class="t-chip-failed">Urgencia</span>@endif
                        @if($job->channelLabel())<span class="text-ink-500">Reclamo: {{ $job->channelLabel() }}</span>@endif
                        @if($job->resultLabel())<span class="{{ $job->result === 'resolved' ? 't-chip-done' : 't-chip-progress' }}">{{ $job->resultLabel() }}</span>@endif
                    </div>
                    <p class="mt-1 whitespace-pre-line text-sm text-ink-700">{{ $job->description }}</p>
                    @if($job->work_done)
                        <p class="mt-1 whitespace-pre-line text-sm text-ink"><span class="font-semibold">Hecho:</span> {{ $job->work_done }}</p>
                    @endif
                    @if($job->photos->isNotEmpty())<p class="mt-1 text-xs text-ink-500">{{ $job->photos->count() }} {{ $job->photos->count() === 1 ? 'foto' : 'fotos' }}</p>@endif
                </div>
            @endforeach
            @if($canAddJobs)
                <div class="border-t border-ink-100 bg-ink-50/60 p-3">
                    <a href="{{ route('interventions.job.create', ['company' => auth()->user()->company->slug, 'workOrder' => $workOrder->id]) }}" class="t-btn-secondary w-full" data-add-job>
                        Agregar otro trabajo de esta visita
                    </a>
                    <p class="mt-2 text-xs text-ink-500">¿Resolviste otro problema en este edificio? Sumalo antes de hacer el remito: va en el mismo, como un trabajo aparte.</p>
                </div>
            @endif
        </section>
    @endif

    {{-- Fotos del trabajo (si la empresa las habilitó para órdenes, o si ya tiene) --}}
    @if($photoManager)
        <div class="mt-3" data-work-order-photos>
            @include('photos.manager', $photoManager)
        </div>
    @endif

    @if($building)
        <section class="t-card mt-3 divide-y divide-ink-100">
            @if($building->client)
                <div class="px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Cliente</div>
                    <div class="font-semibold text-ink">{{ $building->client->name }}</div>
                </div>
            @endif
            @if($building->contact_person || $building->phone)
                <div class="flex items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Contacto en el edificio</div>
                        <div class="truncate font-semibold text-ink">{{ collect([$building->contact_person, $building->phone])->filter()->implode(' · ') }}</div>
                    </div>
                    @if($building->phone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->phone) }}" class="t-btn-secondary t-btn-sm shrink-0">Llamar</a>
                    @endif
                </div>
            @endif
            @if($address !== '')
                <div class="flex items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Dirección</div>
                        <div class="truncate font-semibold text-ink">{{ $address }}</div>
                    </div>
                    <a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($address) }}" target="_blank" rel="noopener" class="t-btn-secondary t-btn-sm shrink-0">Cómo llegar</a>
                </div>
            @endif
        </section>
    @endif
</div>
</x-app-layout>
