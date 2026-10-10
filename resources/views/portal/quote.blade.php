<x-portal-layout :title="'Presupuesto '.$quote->numberLabel()">
    <a class="p-back" href="{{ route('portal.documents', ['type' => 'quote']) }}">← Presupuestos</a>
    <div class="p-card" style="padding: 24px;">
        @include('quotes.document', ['quote' => $quote])
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <a class="p-btn" href="{{ route('portal.quote-pdf', $quote) }}" target="_blank" rel="noopener">⬇ Descargar PDF</a>
        @if($quote->company?->phone)<a class="p-btn secondary" href="tel:{{ preg_replace('/[^\d+]/', '', $quote->company->phone) }}">📞 Llamar</a>@endif
    </div>
</x-portal-layout>
