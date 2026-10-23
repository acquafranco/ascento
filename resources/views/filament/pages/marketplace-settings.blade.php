<x-filament-panels::page>
    @php
        $problems = $this->gatewayProblems();
        $status = $this->salesStatus();
    @endphp
    <div role="status" data-sales-readiness class="rounded-xl p-4 text-sm" style="border:1px solid {{ $problems ? 'rgba(220,38,38,.35)' : 'rgba(22,163,74,.35)' }}">
        <p class="font-semibold">Estado de las ventas</p>
        <dl style="display:grid; grid-template-columns:max-content 1fr; gap:.25rem .9rem; margin-top:.4rem" data-sales-status>
            @foreach($status as $label => $value)<dt style="opacity:.7">{{ $label }}</dt><dd style="margin:0">{{ $value }}</dd>@endforeach
        </dl>
        @if($problems)
            <p class="font-semibold" style="margin-top:.7rem">Para poder cobrar falta en el servidor (variables de entorno; nunca se muestran sus valores):</p>
            <ul style="list-style:disc; padding-left:1.2rem; margin-top:.3rem">@foreach($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
            <p style="margin-top:.5rem; opacity:.75">Mientras falte, ningún proveedor ve opciones de pago ni puede empezar un cobro, aunque una venta esté encendida. Cancelar un Premium ya pagado sigue funcionando.</p>
        @else
            <p style="margin-top:.6rem; opacity:.75">Credenciales de Mercado Pago cargadas. Una venta se ofrece solo encendida, con precio válido y con un país con cobro en su moneda. Apagarla no cancela las suscripciones Premium que ya existen.</p>
        @endif
    </div>
    {{ $this->form }}
    <div><x-filament::button wire:click="save" icon="heroicon-m-check">Guardar configuración</x-filament::button></div>
    <p class="text-sm" style="opacity:.7">Los proveedores ven precios y botones de pago solo cuando están cargados y habilitados. Una cuenta suspendida por moderación no se habilita pagando. Detalle en docs/marketplace-comercial.md.</p>
</x-filament-panels::page>
