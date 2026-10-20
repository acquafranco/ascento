@php
    $test = $getRecord()->loadMissing(['elevator', 'building', 'performedBy', 'recorder', 'files', 'deliveryNote']);
    $note = $test->deliveryNote && (int) $test->deliveryNote->company_id === (int) $test->company_id ? $test->deliveryNote : null;
@endphp
@include('filament.partials.insights-styles')
<div class="asc-card" data-parachute-test>
    <div class="asc-row"><span class="asc-muted">Ascensor</span><span>{{ $test->building?->name }} · {{ $test->elevator?->label }}</span></div>
    <div class="asc-row"><span class="asc-muted">Fecha</span><span>{{ $test->performed_on->format('d/m/Y') }}</span></div>
    <div class="asc-row"><span class="asc-muted">Resultado</span><span class="asc-pill {{ $test->isSatisfactory() ? 'is-done' : 'is-overdue' }}">{{ \App\Models\ParachuteTest::RESULTS[$test->result] ?? $test->result }}</span></div>
    <div class="asc-row"><span class="asc-muted">La realizó</span><span>{{ $test->performerName() ?? '—' }}</span></div>
    <div class="asc-row"><span class="asc-muted">Inspector o profesional</span><span>{{ $test->inspector_name ?? '—' }}@if($test->inspector_registration) · {{ $test->inspector_registration }}@endif</span></div>
    <div class="asc-row"><span class="asc-muted">Registrada por</span><span>{{ $test->recorder?->name ?? '—' }} · {{ $test->created_at->format('d/m/Y H:i') }}</span></div>
    <div class="asc-row" data-parachute-origin><span class="asc-muted">Origen</span><span>
        @if($note)
            @if(\App\Filament\Resources\DeliveryNotes\DeliveryNoteResource::canView($note))
                <a href="{{ \App\Filament\Resources\DeliveryNotes\DeliveryNoteResource::getUrl('view', ['record' => $note]) }}" class="asc-link" style="color:#d97706">Remito {{ $note->number }}</a>
            @else
                Remito {{ $note->number }}
            @endif
        @else
            Cargada desde el panel
        @endif
    </span></div>
    @if($test->observations)
        <p class="asc-q" style="margin-top:.75rem">Observaciones</p>
        <p class="asc-c" style="white-space:pre-line">{{ $test->observations }}</p>
    @endif
    <p class="asc-q" style="margin-top:.75rem">Documentación</p>
    @forelse($test->files as $file)
        <p class="asc-row"><a href="{{ $file->url() }}" target="_blank" rel="noopener" class="asc-link" style="color:#d97706">{{ $file->original_name ?: 'Archivo' }}</a><span class="asc-muted asc-small">{{ number_format($file->size / 1024, 0, ',', '.') }} KB</span></p>
    @empty
        <p class="asc-c">Sin archivos.</p>
    @endforelse
</div>
