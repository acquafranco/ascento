<x-filament-panels::page>
    @if($accessKey)
        {{-- Administrativos: detecta cambios de permisos para retirar bloques (Dashboard::checkAccess). --}}
        <div wire:poll.30s="checkAccess" hidden data-access-poll></div>
    @endif
</x-filament-panels::page>
