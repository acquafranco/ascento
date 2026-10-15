{{-- Resumen del cálculo de una contratación (ServiceAssignmentForm::preview): la misma regla que al guardar. --}}
@php $money = fn (float $v) => \App\Services\Catalog\ServicePricing::money($v); @endphp
<div data-pricing-summary style="border: 1px solid rgba(128,128,128,.25); border-radius: .75rem; padding: .85rem 1rem; font-size: .875rem;">
    @if(! ($summary['ready'] ?? false))
        <p style="margin: 0; opacity: .7;">{{ $summary['hint'] ?? 'Elegí el cliente, el alcance y el precio para ver el importe.' }}</p>
    @else
        <p style="margin: 0 0 .5rem; font-weight: 600;">Cómo se calcula</p>
        <ul style="margin: 0; padding: 0; list-style: none; display: grid; gap: .55rem;">
            @foreach($summary['rows'] as $row)
                <li data-pricing-row style="min-width: 0;">
                    @if($summary['several'] || ! empty($row['lines']))
                        <div style="font-weight: 600; overflow-wrap: anywhere;">{{ $row['building'] }}</div>
                    @endif
                    @if($row['error'])
                        <div data-pricing-error role="alert" style="color: #dc2626; overflow-wrap: anywhere;">{{ $row['error'] }}</div>
                    @else
                        @foreach($row['lines'] as $line)
                            @if(count($row['lines']) > 1)<div style="opacity: .75; overflow-wrap: anywhere;">{{ $line['building'] }}: {{ $line['detail'] }}</div>@else<div style="opacity: .75; overflow-wrap: anywhere;">{{ $line['detail'] }}</div>@endif
                        @endforeach
                        <div style="overflow-wrap: anywhere;">{{ $row['explanation'] }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
        @php $hasError = collect($summary['rows'])->contains(fn ($r) => $r['error']); @endphp
        @unless($hasError)
            <p data-pricing-total style="margin: .75rem 0 0; padding-top: .6rem; border-top: 1px solid rgba(128,128,128,.25); font-weight: 700; font-size: 1rem;">
                {{ $summary['several'] ? 'Total de las '.count($summary['rows']).' contrataciones' : 'Importe' }}: {{ $money($summary['total']) }}@if($summary['frequency']) <span style="font-weight: 400; opacity: .7;">· cobro {{ mb_strtolower($summary['frequency']) }}</span>@endif
            </p>
            @if(! empty($summary['change']))
                <div data-pricing-change role="alert" style="margin-top: .6rem; padding: .6rem .75rem; border-radius: .5rem; background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.45);">
                    <div style="font-weight: 600;">El importe cambia: {{ $money($summary['change']['old']) }} → {{ $money($summary['change']['new']) }}</div>
                    <div style="overflow-wrap: anywhere;">Causa: {{ implode('; ', $summary['change']['reasons']) }}.</div>
                    <div style="opacity: .75;">Rige para los cobros que se generen desde ahora; los ya generados no cambian.</div>
                </div>
            @endif
            @if($summary['several'])
                <p style="margin: .35rem 0 0; opacity: .7;">Se crea una contratación por edificio, cada una con su importe.</p>
            @endif
        @endunless
    @endif
</div>
