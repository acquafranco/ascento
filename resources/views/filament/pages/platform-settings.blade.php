<x-filament-panels::page>
    <div role="status" data-registration-status class="text-sm" style="padding:1rem; border-radius:.75rem; border:1px solid rgba(100,116,139,.3)">
        <p class="font-semibold">Registros nuevos que se aceptan hoy</p>
        <p style="margin-top:.3rem" data-registration-countries>{{ implode(', ', $this->enabledCountries()) }}</p>
        <p style="margin-top:.4rem; opacity:.75">Con un solo país, los formularios de registro no muestran el selector y el servidor asigna Argentina. Un país enviado a mano que no esté en esta lista se rechaza.</p>
    </div>

    <form wire:submit="save" style="display:grid; gap:1rem">
        {{ $this->form }}
        <div><x-filament::button type="submit" icon="heroicon-m-check">Guardar registro por país</x-filament::button></div>
    </form>

    <section data-billing-status class="text-sm" style="padding:1rem; border-radius:.75rem; border:1px solid rgba(100,116,139,.3)">
        <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between">
            <div>
                <p class="font-semibold">Cobros con Mercado Pago</p>
                <p style="margin-top:.2rem" data-mp-mode>{{ $this->accountMode() }}@if($at = $this->accountCheckedAt()) <span style="opacity:.65">· verificada el {{ $at }}</span>@endif</p>
            </div>
            <x-filament::button color="gray" size="sm" wire:click="verifyAccount" icon="heroicon-m-shield-check">Verificar cuenta</x-filament::button>
        </div>
        <p style="margin-top:.4rem; opacity:.75">«Verificar cuenta» le pregunta a Mercado Pago si las credenciales son de un usuario de prueba o de una cuenta real (solo lectura: no cobra ni crea nada). No se deduce del prefijo del token. Nunca se muestran valores secretos.</p>

        <div style="display:grid; gap:.75rem; margin-top:.9rem">
            @foreach($this->billingFlows() as $name => $flow)
                <div data-billing-flow style="padding:.75rem; border-radius:.5rem; border:1px solid {{ $flow['problems'] ? 'rgba(220,38,38,.35)' : ($flow['ok'] ? 'rgba(22,163,74,.35)' : 'rgba(100,116,139,.25)') }}">
                    <p class="font-semibold">{{ $name }}</p>
                    <p style="margin-top:.2rem">{{ $flow['status'] }}</p>
                    @if($flow['problems'])
                        <ul style="list-style:disc; padding-left:1.2rem; margin-top:.35rem">@foreach($flow['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul>
                    @endif
                    @if($flow['warnings'])
                        <ul style="list-style:disc; padding-left:1.2rem; margin-top:.35rem; opacity:.8">@foreach($flow['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
                    @endif
                </div>
            @endforeach
        </div>
        <p style="margin-top:.7rem; opacity:.75">Precios y ventas del marketplace: «Configuración del marketplace». Planes de las empresas: «Planes». Lista completa de variables y pruebas: docs/mercado-pago-produccion.md.</p>
    </section>
</x-filament-panels::page>
