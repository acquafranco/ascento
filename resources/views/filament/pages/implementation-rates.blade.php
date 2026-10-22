<x-filament-panels::page>
    {{ $this->form }}
    <div><x-filament::button wire:click="save" icon="heroicon-m-check">Guardar tarifas</x-filament::button></div>
    <p class="text-sm" style="opacity:.7">Fórmula: tarifa base + unidades adicionales × su importe × factor del estado de los datos + opcionales. Detalle en docs/calculadora-y-repuestos.md.</p>
</x-filament-panels::page>
