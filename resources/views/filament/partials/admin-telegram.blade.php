{{-- Conectar Telegram (admin), en "Mi empresa". --}}
@php($user = auth()->user())
<x-filament::section icon="heroicon-o-paper-airplane" id="telegram">
    <x-slot name="heading">Avisos por Telegram</x-slot>
    <x-slot name="description">Los mismos avisos (trabajo terminado, reportes nuevos) también por Telegram.</x-slot>

    @if ($user->hasTelegram())
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem">
            <x-filament::badge color="success">Telegram conectado</x-filament::badge>
            <form method="POST" action="{{ route('telegram.disconnect', ['company' => $user->company->slug]) }}">
                @csrf
                @method('DELETE')
                <x-filament::button type="submit" color="gray" size="sm">Desconectar</x-filament::button>
            </form>
        </div>
    @else
        <x-filament::button tag="a" :href="route('telegram.connect', ['company' => $user->company->slug])" target="_blank" icon="heroicon-m-paper-airplane">
            Conectar Telegram
        </x-filament::button>
        <p style="margin-top:.5rem;font-size:.8125rem;color:var(--gray-500)">Se abre Telegram: tocá <strong>Iniciar</strong>. Después recargá esta página.</p>
    @endif
</x-filament::section>
