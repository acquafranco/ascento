<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Seguridad</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Código de verificación</h1>
    <p class="mt-1.5 text-sm text-ink/50 leading-relaxed">
        Escribí el código de 6 dígitos de tu app autenticadora. Si no tenés el teléfono, usá uno de tus códigos de recuperación.
    </p>

    <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <x-input-label for="code" value="Código" />
            <x-text-input id="code" name="code" type="text" autocomplete="one-time-code" maxlength="20" class="mt-1 block w-full" required autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-1.5" />
        </div>
        <div class="flex justify-end"><x-primary-button>Verificar</x-primary-button></div>
    </form>

    @include('auth.two-factor._logout')
</x-guest-layout>
