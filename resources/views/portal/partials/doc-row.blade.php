{{-- Una fila del centro de documentos ($d: kind, id, building_id, happened_at, ref, summary, status, amount). --}}
@php
    $types = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Trabajo'];
    $quoteTags = ['sent' => 'info', 'approved' => 'ok', 'rejected' => 'bad', 'void' => 'bad', 'expired' => 'warn'];
    $reportTags = ['pendiente' => 'warn', 'en_revision' => 'info', 'resuelto' => 'ok'];
    $date = \Illuminate\Support\Carbon::parse($d->happened_at);
    [$icon, $title, $text, $tag, $tagClass, $url, $download] = match ($d->kind) {
        'delivery_note' => ['📄', 'Remito '.$d->ref, $d->summary, $types[$d->status] ?? 'Trabajo', '', route('portal.delivery-note', $d->ref), false],
        'report' => ['🛠️', 'Reporte técnico'.($d->ref ? ' · '.$d->ref : ''), $d->summary, \App\Models\Report::STATUS_LABELS[$d->status] ?? $d->status, $reportTags[$d->status] ?? '', route('portal.report', $d->id), false],
        'quote' => ['💲', $d->summary, 'Total $'.number_format((float) $d->amount, 2, ',', '.'), \App\Models\Quote::STATUSES[$d->status] ?? $d->status, $quoteTags[$d->status] ?? '', route('portal.quote', $d->id), false],
        default => ['📎', $d->summary, $d->ref, \App\Models\ElevatorDocument::TYPES[$d->status] ?? 'Documento', '', route('portal.document', $d->id), true],
    };
    $isNew = in_array($d->kind.':'.$d->id, $newKeys ?? [], true);
@endphp
<li>
    <a class="p-row" href="{{ $url }}" @if($download) target="_blank" rel="noopener" @endif>
        <span class="p-row-main">
            <span class="p-icon" aria-hidden="true">{{ $icon }}</span>
            <span style="min-width:0">
                <span class="p-row-title">{{ $title }} @if($isNew)<span class="p-tag warn">Nuevo</span>@endif</span>
                <span class="p-row-text">{{ \Illuminate\Support\Str::limit((string) $text, 140) }}</span>
                <span class="p-muted">{{ $buildingNames[$d->building_id] ?? '' }}</span>
            </span>
        </span>
        <span class="p-row-side">
            <span class="p-tag {{ $tagClass }}">{{ $tag }}</span>
            <span class="p-muted" style="display:block;margin-top:4px">{{ $date->format('d/m/Y') }}</span>
        </span>
    </a>
</li>
