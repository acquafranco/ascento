{{--
    Visita técnica abierta del técnico (SiteVisits): dónde está, desde cuándo,
    qué trabajos y remitos lleva y el control final «Finalizar visita y
    retirarme». Los trabajos sin remito se muestran, pero no bloquean la salida
    (siguen en curso). Requiere $siteVisit (abierta, del usuario).
--}}
@php
    use App\Services\WorkOrders\AttendanceTimes;
    use App\Services\WorkOrders\SiteVisits;
    $slug = auth()->user()->company->slug;
    $svOrders = $siteVisit->workOrders()->withoutGlobalScopes()->whereNull('deleted_at')->with('deliveryNote')->orderBy('id')->get();
    $svPending = $svOrders->filter(fn ($o) => $o->status === 'in_progress' && ! $o->deliveryNote);
    $svRemitos = $siteVisit->buildingVisits()->withoutGlobalScopes()->whereHas('deliveryNote')->count();
    $svCanLeave = SiteVisits::canLeave(auth()->user(), $siteVisit);
@endphp
<section class="t-card mt-3 border-brand-200 p-4" data-site-visit="{{ $siteVisit->id }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-xs font-bold uppercase tracking-wider text-ink-500">Visita en curso</h2>
            <p class="mt-1 font-bold text-ink">{{ $siteVisit->building?->name }}</p>
            <p class="text-sm text-ink-700">Llegaste: <strong data-site-visit-arrived>{{ AttendanceTimes::time($siteVisit->arrived_at) }}</strong></p>
        </div>
        <span class="t-chip-progress shrink-0">En el edificio</span>
    </div>

    <p class="mt-2 text-sm text-ink-700" data-site-visit-summary>
        {{ $svRemitos === 1 ? '1 remito' : $svRemitos.' remitos' }} en esta visita{{ $svOrders->isNotEmpty() ? ' · '.($svOrders->count() === 1 ? '1 orden' : $svOrders->count().' órdenes') : '' }}.
    </p>

    @if($svPending->isNotEmpty())
        <div class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900" data-site-visit-pending>
            <p class="font-semibold">{{ $svPending->count() === 1 ? 'Queda un trabajo sin remito:' : 'Quedan '.$svPending->count().' trabajos sin remito:' }}</p>
            <ul class="mt-1 space-y-1">
                @foreach($svPending as $pendingOrder)
                    <li>
                        <a class="font-semibold underline" href="{{ route('delivery-notes.work-order', ['company' => $slug, 'workOrder' => $pendingOrder->id]) }}">Orden #{{ $pendingOrder->id }}{{ $pendingOrder->unit ? ' · '.$pendingOrder->unit : '' }}</a>
                    </li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs">Si te vas igual, siguen en curso (no se cierran): las podés terminar en otra visita.</p>
        </div>
    @endif

    @if($svCanLeave)
        <div class="mt-4">
            @include('partials.slide-confirm', ['action' => route('site-visits.leave', ['company' => $slug, 'siteVisit' => $siteVisit->id]), 'label' => 'Finalizar visita y retirarme', 'name' => 'leave-visit-'.$siteVisit->id])
            <p class="mt-1.5 text-xs text-ink-500">Cuando termines todo y te vayas del edificio. Guarda la hora de salida; no cierra órdenes ni cambia los remitos.</p>
        </div>
    @else
        <p class="mt-3 text-sm font-semibold text-amber-800">Pasaron más de {{ SiteVisits::LEAVE_WINDOW_HOURS }} h desde la llegada: la salida ya no se registra. Cerrá la visita con el motivo.</p>
    @endif

    <details class="mt-3 text-sm" @if(! $svCanLeave || $errors->has('close_note')) open @endif data-site-visit-close>
        <summary class="cursor-pointer text-ink-500">No puedo registrar la salida</summary>
        <form method="POST" action="{{ route('site-visits.close', ['company' => $slug, 'siteVisit' => $siteVisit->id]) }}" class="mt-2 space-y-2">
            @csrf
            <label for="close-note-{{ $siteVisit->id }}" class="block text-xs text-ink-700">Motivo (la oficina lo ve). La salida queda «Sin registrar»: no se inventa una hora.</label>
            <textarea id="close-note-{{ $siteVisit->id }}" name="close_note" rows="2" maxlength="500" minlength="10" required class="t-input" placeholder="Ej.: me olvidé de registrarla al irme"></textarea>
            <button type="submit" class="t-btn-secondary t-btn-sm w-full">Cerrar visita sin salida</button>
        </form>
    </details>
</section>
