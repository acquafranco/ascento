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

    {{-- Ayudas: cada una tiene su propio estado (visto / pendiente). --}}
    <x-filament::section collapsible collapsed icon="heroicon-o-question-mark-circle">
        <x-slot name="heading">Ayuda</x-slot>
        <x-slot name="description">Las ayudas aparecen una vez en cada pantalla. Si querés volver a ver alguna, reactivala acá.</x-slot>

        @include('filament.partials.insights-styles')
        <div>
            @foreach($this->helpTopics() as $key => $topic)
                <div class="asc-row" style="align-items: center; padding: .35rem 0; border-top: 1px solid rgba(127,127,127,.15)">
                    <span>{{ $topic['title'] }} <span class="asc-small {{ $topic['seen'] ? 'asc-muted' : '' }}" style="{{ $topic['seen'] ? '' : 'color: #d97706' }}">· {{ $topic['seen'] ? 'vista' : 'se va a mostrar' }}</span></span>
                    @if($topic['seen'])
                        <x-filament::button size="xs" color="gray" wire:click="resetHelp('{{ $key }}')">Volver a ver</x-filament::button>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="asc-pills" style="margin-top: 1rem">
            <x-filament::button size="sm" color="gray" wire:click="resetAllHelp">Volver a ver todas</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="resetWelcomeGuide">Volver a ver la guía de bienvenida</x-filament::button>
        </div>
    </x-filament::section>

</x-filament-panels::page>
