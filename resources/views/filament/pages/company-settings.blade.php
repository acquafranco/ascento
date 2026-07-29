<x-filament-panels::page>

    {{ $this->form }}

    <div class="flex justify-end pt-6 border-t">
    <x-filament::button
        wire:click="save"
        icon="heroicon-o-check"
        size="lg"
    >
        Guardar cambios
    </x-filament::button>
</div>

</x-filament-panels::page>
