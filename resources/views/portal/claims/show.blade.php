@php $kind = $claim->isFromQr() ? 'Reporte' : 'Reclamo'; @endphp
<x-portal-layout title="{{ $kind }} {{ $claim->reference() }}">
    @include('portal.claims._styles')
    <p style="margin:0 0 6px"><a href="{{ route('portal.claims.index') }}">← Reclamos e incidencias</a></p>
    @if(session('claim_created'))
        <p class="pc-ok" role="status" data-claim-registered>Tu reclamo quedó registrado con el número <strong>{{ $claim->reference() }}</strong>. {{ $portalCompany?->name }} lo va a ver cuando revise sus reclamos; acá vas a ver su estado y las respuestas.</p>
    @endif
    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between">
        <h1 class="p-h1" style="margin:0">{{ $kind }} {{ $claim->reference() }}</h1>
        <span class="p-tag {{ in_array($claim->status, ['resolved', 'closed'], true) ? 'ok' : ($claim->status === 'new' ? 'info' : 'warn') }}" data-claim-status>{{ $claim->statusLabel() }}</span>
    </div>
    <div style="height:14px"></div>
    @include('portal.claims._safety')

    <section class="p-card" style="display:block">
        <dl class="pc-dl">
            <dt>Lugar</dt><dd>{{ $claim->placeLabel() }}</dd>
            <dt>Qué pasó</dt><dd>{{ $claim->categoryLabel() }}</dd>
            <dt>Detalle</dt><dd style="white-space:pre-line">{{ $claim->description }}</dd>
            @if($claim->occurred_at)<dt>Cuándo</dt><dd>{{ $claim->occurred_at->format('d/m/Y H:i') }}</dd>@endif
            <dt>Presentado</dt><dd>{{ $claim->created_at->format('d/m/Y H:i') }}{{ $claim->isFromQr() ? ' · por una persona sin cuenta, desde el QR del edificio' : '' }}</dd>
        </dl>
        @if($claim->photos->isNotEmpty())
            <div class="pc-photos" style="margin-top:12px">
                @foreach($claim->photos as $photo)
                    <a href="{{ route('portal.claims.photo', [$claim->id, $photo->id]) }}" target="_blank" rel="noopener"><img src="{{ route('portal.claims.photo', [$claim->id, $photo->id]) }}" alt="Foto {{ $loop->iteration }} del reclamo" loading="lazy"></a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="p-sec">
        <div class="p-sec-head"><h2>Novedades</h2></div>
        <ol class="pc-timeline" data-claim-timeline>
            @foreach($claim->publicEvents as $event)
                <li data-claim-event="{{ $event->type }}">
                    <time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('d/m/Y H:i') }}</time>
                    @switch($event->type)
                        @case('created') Registraste el reclamo. @break
                        @case('qr_created') Se reportó desde el QR del edificio. @break
                        @case('status') Estado: <strong>{{ \App\Models\ClientClaim::STATUSES[$event->to] ?? $event->to }}</strong>.@if($event->body) <span style="white-space:pre-line">{{ $event->body }}</span>@endif @break
                        @case('reply') <strong>Respuesta de {{ $portalCompany?->name }}:</strong> <span style="white-space:pre-line">{{ $event->body }}</span> @break
                    @endswitch
                </li>
            @endforeach
        </ol>
        <p class="p-sub" style="margin-top:10px; font-size:13px">{{ \App\Models\ClientClaim::STATUS_NOTE }}</p>
    </section>
</x-portal-layout>
