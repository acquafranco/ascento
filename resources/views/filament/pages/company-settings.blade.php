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

    {{-- Copia de los datos de negocio (ver "Exportar datos"). --}}
    <x-filament::section icon="heroicon-o-arrow-down-tray">
        <x-slot name="heading">Copia de tus datos</x-slot>
        <x-slot name="description">Descargá un Excel con todos los datos de tu empresa y sus fotos y documentos.</x-slot>
        <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\CompanyExports::getUrl()">Ir a Exportar datos</x-filament::button>
    </x-filament::section>

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
