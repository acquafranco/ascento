{{-- Uso del límite de publicaciones del plan (productos publicados a la vez, no unidades de stock). --}}
<div class="s-card" data-usage data-usage-published="{{ $usage['published'] }}" data-usage-limit="{{ $usage['limit'] }}" style="margin-bottom:14px">
    <div class="s-row">
        <strong style="font-size:15px">{{ $usage['premium'] ? 'Premium' : 'Plan gratuito' }}: {{ $usage['published'] }} de {{ number_format($usage['limit'], 0, ',', '.') }} productos publicados</strong>
        <span class="s-tag {{ $usage['remaining'] > 0 ? 'ok' : 'warn' }}">{{ $usage['remaining'] }} {{ $usage['remaining'] === 1 ? 'lugar libre' : 'lugares libres' }}</span>
    </div>
    <div style="height:8px; background:#EEF0F4; border-radius:99px; margin:10px 0 8px; overflow:hidden" aria-hidden="true">
        <div style="height:100%; width:{{ min(100, $usage['limit'] ? round($usage['published'] * 100 / $usage['limit']) : 0) }}%; background:{{ $usage['remaining'] > 0 ? '#FF6600' : '#B34700' }}"></div>
    </div>
    <p class="s-muted" style="margin:0">
        {{ $usage['drafts'] }} en borrador · {{ $usage['paused'] }} pausados @if($usage['over_limit'])· <a class="s-link" href="{{ route('suppliers.products.index', ['estado' => 'over_limit']) }}">{{ $usage['over_limit'] }} fuera del límite</a>@endif.
        Cuenta cada producto publicado, sin importar su stock. Borradores, pausados, retirados y ocultos no cuentan.
        @unless($usage['premium']) <a class="s-link" href="{{ route('suppliers.billing') }}">¿Necesitás más? Ver Premium</a>@endunless
    </p>
</div>
