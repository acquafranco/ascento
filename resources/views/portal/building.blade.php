@php
    $types = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Trabajo'];
    $docTypes = \App\Models\ElevatorDocument::TYPES;
    $priorities = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];
@endphp
<x-portal-layout :company="$company" :title="$building->name">
    <a class="p-back" href="{{ route('portal.home') }}">← Mis edificios</a>
    <h1 class="p-h1">{{ $building->name }}</h1>
    <p class="p-sub">{{ trim($building->address.' '.$building->locality) }}</p>

    <section class="p-sec">
        <h2>Equipos</h2>
        @if($elevators->isEmpty())
            <div class="p-empty">No hay equipos cargados para este edificio.</div>
        @else
            <ul class="p-list">
                @foreach($elevators as $elevator)
                    <li>
                        <span>{{ $elevator->label }}</span>
                        <span class="p-muted">{{ trim($elevator->manufacturer.' '.$elevator->model) ?: '—' }}{{ $elevator->year ? ' · '.$elevator->year : '' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="p-sec">
        <h2>Mantenimientos, inspecciones y trabajos</h2>
        @forelse($deliveryNotes as $note)
            @if($loop->first)<ul class="p-list">@endif
            <li>
                <a href="{{ route('portal.delivery-note', $note) }}">Remito {{ $note->number }}</a>
                <span><span class="p-tag">{{ $types[$note->assignment_type] ?? 'Trabajo' }}</span> <span class="p-muted">{{ $note->created_at?->format('d/m/Y') }}</span></span>
            </li>
            @if($loop->last)</ul>@endif
        @empty
            <div class="p-empty">Todavía no hay remitos compartidos. Cuando {{ $company->name }} comparta uno, lo vas a ver acá.</div>
        @endforelse
    </section>

    <section class="p-sec">
        <h2>Reportes</h2>
        @forelse($reports as $report)
            @if($loop->first)<ul class="p-list">@endif
            <li>
                <a href="{{ route('portal.report', $report) }}">{{ \Illuminate\Support\Str::limit($report->description, 70) }}</a>
                <span><span class="p-tag {{ in_array($report->priority, ['alta', 'critica']) ? 'warn' : '' }}">{{ $priorities[$report->priority] ?? $report->priority }}</span> <span class="p-muted">{{ $report->created_at?->format('d/m/Y') }}</span></span>
            </li>
            @if($loop->last)</ul>@endif
        @empty
            <div class="p-empty">No hay reportes compartidos.</div>
        @endforelse
    </section>

    <section class="p-sec">
        <h2>Presupuestos</h2>
        @forelse($quotes as $quote)
            @if($loop->first)<ul class="p-list">@endif
            <li>
                <a href="{{ route('portal.quote', $quote) }}">{{ $quote->title }}</a>
                <span><span class="p-tag">{{ $quote->displayStatusLabel() }}</span> <span class="p-muted">${{ number_format((float) $quote->amount, 0, ',', '.') }}</span></span>
            </li>
            @if($loop->last)</ul>@endif
        @empty
            <div class="p-empty">No hay presupuestos compartidos.</div>
        @endforelse
    </section>

    <section class="p-sec">
        <h2>Documentación técnica</h2>
        @forelse($documents as $document)
            @if($loop->first)<ul class="p-list">@endif
            <li>
                <a href="{{ route('portal.document', $document) }}" target="_blank" rel="noopener">{{ $document->title }}</a>
                <span><span class="p-tag">{{ $docTypes[$document->type] ?? $document->type }}</span> <span class="p-muted">{{ $document->elevator?->label }}{{ $document->expires_at ? ' · vence '.$document->expires_at->format('d/m/Y') : '' }}</span></span>
            </li>
            @if($loop->last)</ul>@endif
        @empty
            <div class="p-empty">No hay documentación compartida (planos, manuales o certificados).</div>
        @endforelse
    </section>
</x-portal-layout>
