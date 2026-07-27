<x-filament-panels::page>

    {{ $this->form }}

    <x-filament::button
        wire:click="save"
        wire:loading.attr="disabled"
        wire:target="save"
    >
        Guardar cambios
    </x-filament::button>

</x-filament-panels::page>
