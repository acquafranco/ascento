<x-filament-panels::page>
    @php($problems = $this->gatewayProblems())
    <div role="status" data-sales-readiness class="rounded-xl p-4 text-sm" style="border:1px solid {{ $problems ? 'rgba(220,38,38,.35)' : 'rgba(22,163,74,.35)' }}">
        @if($problems)
            <p class="font-semibold">Todavía no se puede vender Premium ni destacados. Falta en el servidor:</p>
            <ul style="list-style:disc; padding-left:1.2rem; margin-top:.3rem">@foreach($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
        @else
            <p class="font-semibold">Credenciales de Mercado Pago cargadas. Las ventas se encienden con su interruptor, con precio y país con cobro.</p>
        @endif
    </div>
    {{ $this->form }}
    <div><x-filament::button wire:click="save" icon="heroicon-m-check">Guardar configuración</x-filament::button></div>
    <p class="text-sm" style="opacity:.7">Los proveedores ven precios y botones de pago solo cuando están cargados y habilitados. Una cuenta suspendida por moderación no se habilita pagando. Detalle en docs/marketplace-comercial.md.</p>
</x-filament-panels::page>
