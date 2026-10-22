<x-filament-panels::page>
    {{ $this->form }}
    <div><x-filament::button wire:click="save" icon="heroicon-m-check">Guardar configuración</x-filament::button></div>
    <p class="text-sm" style="opacity:.7">Los proveedores ven precios y botones de pago solo cuando están cargados y habilitados. Una cuenta suspendida por moderación no se habilita pagando. Detalle en docs/marketplace-comercial.md.</p>
</x-filament-panels::page>
