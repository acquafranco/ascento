{{--
    Reporte del QR para el técnico asignado al edificio (TechnicianQrReportController).
    Solo lectura; la gestión la hace la oficina.
--}}
@php $slug = auth()->user()->company->slug; @endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <p class="text-sm font-semibold text-ink-500">Reporte desde el QR · {{ $claim->reference() }}</p>
        <h1 class="t-h1">{{ \App\Models\ClientClaim::QR_CATEGORIES[$claim->category] ?? $claim->categoryLabel() }}</h1>
        <p class="t-sub">{{ $claim->placeLabel() }}{{ $claim->building?->address ? ' · '.$claim->building->address : '' }}</p>
    </header>

    <article class="t-card space-y-3 p-4">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="t-chip-neutral">{{ $claim->created_at->format('d/m/Y H:i') }}</span>
            <span class="t-chip-progress">{{ $claim->statusLabel() }}</span>
        </div>
        <p class="whitespace-pre-line text-[15px] text-ink">{{ $claim->description }}</p>
        @if($claim->photos->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach($claim->photos as $photo)
                    <a href="{{ route('qr-reports.technician.photo', ['company' => $slug, 'claim' => $claim->id, 'photo' => $photo->id]) }}" target="_blank" rel="noopener">
                        <img src="{{ route('qr-reports.technician.photo', ['company' => $slug, 'claim' => $claim->id, 'photo' => $photo->id]) }}" alt="Foto del reporte" class="h-28 w-28 rounded-xl border border-ink-100 object-cover" loading="lazy">
                    </a>
                @endforeach
            </div>
        @endif
        <p class="text-sm text-ink-500">Lo informó una persona sin cuenta desde el QR del edificio: describe lo que observó, no es un diagnóstico. La oficina decide si corresponde una orden de trabajo.</p>
    </article>

    <p class="mt-3 text-xs text-ink-500">{{ \App\Models\ClientClaim::STATUS_NOTE }}</p>
</div>
</x-app-layout>
