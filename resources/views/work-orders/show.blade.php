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
    @if(session('warning'))
        <div class="t-alert-warning mb-4" role="status">{{ session('warning') }}</div>
    @endif

    @include('work-orders.partials.card', ['workOrder' => $workOrder])

    {{-- Tiempos de atención: aviso, llegada y salida (hora del servidor; no se editan). Sin dato: «Sin registrar». --}}
    @php
        $times = \App\Services\WorkOrders\AttendanceTimes::summary($workOrder);
        // Visita técnica: la llegada y la salida son de la visita al edificio (SiteVisits).
        // La llegada se ofrece a quien tiene la orden en curso y no está en el edificio
        // ahora (también si quedó pendiente de una visita anterior); nunca a una orden
        // con llegada propia anterior a las visitas.
        $openVisit = \App\Services\WorkOrders\SiteVisits::openFor(auth()->user());
        $visitHere = \App\Services\WorkOrders\SiteVisits::openIn(auth()->user(), (int) $workOrder->building_id);
        $otherVisit = $openVisit && ! $visitHere ? $openVisit : null; // otro edificio, u olvidada de otro día
        $canArrive = ! $visitHere && $workOrder->status === 'in_progress' && ! $workOrder->trashed() && ! $workOrder->deliveryNote
            && ! \App\Services\WorkOrders\SiteVisits::hasLegacyArrival($workOrder) && $workOrder->users->contains(auth()->id());
        $canLegacyLeave = $workOrder->site_visit_id === null && \App\Services\WorkOrders\AttendanceTimes::canLeave(auth()->user(), $workOrder);
        $fmt = fn ($at) => \App\Services\WorkOrders\AttendanceTimes::time($at);
        $dur = fn ($m) => \App\Services\WorkOrders\AttendanceTimes::duration($m);
    @endphp
    <section class="t-card mt-3 p-4" data-attention-times>
        <h2 class="text-xs font-bold uppercase tracking-wider text-ink-500">Tiempos de atención</h2>
        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-ink-500">Aviso</dt>
            <dd class="font-semibold text-ink" data-time="call">{{ $fmt($times['call']) }}@if($times['call_source'])<span class="block text-xs font-normal text-ink-500">{{ ucfirst($times['call_source']) }}{{ $times['call_by'] ? ' · '.$times['call_by'] : '' }}</span>@endif</dd>
            <dt class="text-ink-500">Llegada</dt>
            <dd class="font-semibold text-ink" data-time="arrived">{{ $fmt($times['arrived']) }}@if($times['arrived_by'])<span class="block text-xs font-normal text-ink-500">{{ $times['arrived_by'] }}</span>@endif</dd>
            <dt class="text-ink-500">Salida</dt>
            <dd class="font-semibold text-ink" data-time="left">{{ $fmt($times['left']) }}@if($times['left_by'])<span class="block text-xs font-normal text-ink-500">{{ $times['left_by'] }}</span>@endif</dd>
        </dl>
        <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-ink-100 pt-3 text-center text-xs">
            <div><dt class="text-ink-500">Respuesta</dt><dd class="mt-0.5 font-bold text-ink" data-duration="response">{{ $dur($times['response']) }}</dd></div>
            <div><dt class="text-ink-500">En el lugar</dt><dd class="mt-0.5 font-bold text-ink" data-duration="on_site">{{ $dur($times['on_site']) }}</dd></div>
            <div><dt class="text-ink-500">Total</dt><dd class="mt-0.5 font-bold text-ink" data-duration="total">{{ $dur($times['total']) }}</dd></div>
        </dl>
        @if($canArrive && $otherVisit)
            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900" data-other-visit>
                Tenés una visita abierta en <strong>{{ $otherVisit->building?->name }}</strong> (desde {{ $fmt($otherVisit->arrived_at) }}).
                <a class="font-semibold underline" href="{{ route('dashboard', ['company' => auth()->user()->company->slug]) }}">{{ \App\Services\WorkOrders\SiteVisits::isStale($otherVisit) ? 'Cerrala' : 'Finalizala' }}</a> antes de registrar la llegada acá.
            </p>
        @elseif($canArrive)
            <div class="mt-4" id="llegada">
                @include('partials.slide-confirm', ['action' => route('work-orders.arrive', ['company' => auth()->user()->company->slug, 'workOrder' => $workOrder->id]), 'label' => 'Llegué al edificio', 'name' => 'arrive'])
                <p class="mt-1.5 text-xs text-ink-500">Deslizalo cuando estés en el edificio. Se guarda la hora de ahora y no se puede cambiar. Después vas a poder hacer los trabajos y los remitos.</p>
            </div>
        @elseif($visitHere && $workOrder->status === 'in_progress' && ! $workOrder->deliveryNote)
            <p class="mt-4 flex items-center gap-1.5 text-sm font-semibold text-emerald-800" data-arrival-registered>
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
                Llegada registrada · {{ $visitHere->arrived_at->format('H:i') }}
            </p>
            <p class="mt-1 text-sm text-ink-700" data-next-step-hint>Siguiente paso: hacé el trabajo y su remito (botón de arriba). La salida se registra al final de la visita, con «Finalizar visita y retirarme».</p>
        @elseif($canLegacyLeave)
            {{-- Orden con llegada propia, anterior a las visitas técnicas. --}}
            <div class="mt-4">
                @include('partials.slide-confirm', ['action' => route('work-orders.leave', ['company' => auth()->user()->company->slug, 'workOrder' => $workOrder->id]), 'label' => 'Terminé y me retiro', 'name' => 'leave'])
                <p class="mt-1.5 text-xs text-ink-500">Cuando termines y te vayas del edificio. No cierra la orden ni reemplaza el remito con la firma.</p>
            </div>
        @elseif($workOrder->status === 'pending' && $workOrder->users->contains(auth()->id()))
            <p class="mt-3 text-xs text-ink-500">Tomá el trabajo para poder registrar la llegada.</p>
        @endif
    </section>

    {{-- Visita en curso: con el remito de esta orden ya hecho, el paso final. --}}
    @if($visitHere && $workOrder->status !== 'in_progress')
        @include('partials.site-visit-card', ['siteVisit' => $visitHere])
    @endif

    {{-- Trabajos de esta visita (intervenciones): cada problema por separado, en el mismo remito. --}}
    @php
        // Los trabajos se suman después de la llegada (paso 3 de la visita).
        $canAddJobs = $workOrder->acceptsJobsFrom(auth()->user()) && $workOrder->arrived_at !== null;
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
                    @if($job->elevatorStateLabel())<p class="mt-1 text-sm text-ink"><span class="font-semibold">Equipo:</span> {{ $job->elevatorStateLabel() }}</p>@endif
                    @if($job->pending_work)<p class="mt-1 whitespace-pre-line text-sm text-amber-800"><span class="font-semibold">Pendiente:</span> {{ $job->pending_work }}</p>@endif
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
