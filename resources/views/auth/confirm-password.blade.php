<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Confirmación</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Confirmá tu contraseña</h1>
    <p class="mt-1.5 text-sm text-ink/50 leading-relaxed">
        Esta es un área segura de la aplicación. Confirmá tu contraseña antes de continuar.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="password" :value="__('Contraseña')" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" autofocus />
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div class="flex items-center justify-end pt-2">
            <x-primary-button>
                Confirmar
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
