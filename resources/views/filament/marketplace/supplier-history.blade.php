{{-- Ficha del proveedor (SuperAdmin): cobros confirmados, destacados y acciones administrativas registradas. --}}
@php
    use App\Models\Marketplace\FeaturedPlacement;
    use App\Models\PlatformAuditLog;
    use App\Support\Marketplace\Money;
    $record = $getRecord();
    $payments = $record->payments()->with('placement')->limit(30)->get();
    $placements = FeaturedPlacement::where('supplier_id', $record->id)->latest('id')->limit(30)->get();
    $audit = PlatformAuditLog::where('subject_type', 'Supplier')->where('subject_id', $record->id)->latest('id')->limit(30)->get();
@endphp
<div style="display:grid; gap:1.25rem; font-size:.875rem">
    <div>
        <p style="font-weight:600; margin:0 0 .4rem">Cobros ({{ $payments->count() }})</p>
        @forelse($payments as $p)
            <div style="display:flex; justify-content:space-between; gap:1rem; border-top:1px solid rgba(127,127,127,.2); padding:.35rem 0">
                <span>{{ ($p->paid_at ?? $p->created_at)->format('d/m/Y H:i') }} · {{ $p->kind === 'featured' ? 'Destacado: '.($p->placement?->product_name ?? '—') : 'Suscripción' }}</span>
                <span>{{ Money::format($p->amount !== null ? (float) $p->amount : null, $p->currency) }} · <strong>{{ $p->statusLabel() }}</strong></span>
            </div>
        @empty
            <p style="opacity:.7; margin:0">Sin cobros.</p>
        @endforelse
    </div>
    <div>
        <p style="font-weight:600; margin:0 0 .4rem">Destacados ({{ $placements->count() }})</p>
        @forelse($placements as $f)
            <div style="display:flex; justify-content:space-between; gap:1rem; border-top:1px solid rgba(127,127,127,.2); padding:.35rem 0">
                <span>{{ $f->product_name }} · {{ $f->sourceLabel() }} · {{ $f->days }} días{{ $f->starts_at ? ' · '.$f->starts_at->format('d/m/Y').' al '.$f->ends_at->format('d/m/Y') : '' }}</span>
                <span><strong>{{ $f->statusLabel() }}</strong>{{ $f->status_reason ? ' · '.$f->status_reason : '' }}</span>
            </div>
        @empty
            <p style="opacity:.7; margin:0">Sin destacados.</p>
        @endforelse
    </div>
    <div>
        <p style="font-weight:600; margin:0 0 .4rem">Acciones administrativas</p>
        @forelse($audit as $a)
            <div style="border-top:1px solid rgba(127,127,127,.2); padding:.35rem 0">
                {{ $a->created_at->format('d/m/Y H:i') }} · {{ $a->actor_name }} · <code>{{ $a->action }}</code>
                @if($a->changes)<span style="opacity:.75"> · {{ collect($a->changes)->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'sí' : 'no') : $v))->implode(' · ') }}</span>@endif
            </div>
        @empty
            <p style="opacity:.7; margin:0">Sin acciones registradas.</p>
        @endforelse
    </div>
</div>
