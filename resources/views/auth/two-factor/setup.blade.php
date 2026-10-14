<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Seguridad</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Configurá el segundo factor</h1>
    <p class="mt-1.5 text-sm text-ink/50 leading-relaxed">
        La cuenta de SuperAdmin necesita un código de 6 dígitos de una app autenticadora (Google Authenticator, Microsoft Authenticator, 1Password u otra) además de la contraseña.
    </p>
    @if (session('status'))
        <p class="mt-3 text-sm font-medium text-ink">{{ session('status') }}</p>
    @endif

    @if (! $inProgress)
        <form method="POST" action="{{ route('two-factor.start') }}" class="mt-8 space-y-5">
            @csrf
            <div>
                <x-input-label for="password" value="Contraseña actual" />
                <x-password-input id="password" name="password" required autocomplete="current-password" autofocus />
                <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
            </div>
            <div class="flex justify-end"><x-primary-button>Empezar</x-primary-button></div>
        </form>
    @else
        <div class="mt-6 space-y-3" data-two-factor-setup>
            <p class="text-sm text-ink/70">1. Escaneá este código con la app.</p>
            <img src="{{ $qr }}" alt="Código QR para la app autenticadora" width="200" height="200" class="rounded bg-white p-2">
            <p class="text-sm text-ink/70">Si no podés escanearlo, cargá esta clave a mano: <code class="font-mono text-ink">{{ $secret }}</code></p>
            <p class="text-sm text-ink/70">2. Escribí el código de 6 dígitos que muestra la app.</p>
        </div>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4 space-y-5">
            @csrf
            <div>
                <x-input-label for="code" value="Código de la app" />
                <x-text-input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="mt-1 block w-full" required autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-1.5" />
            </div>
            <div class="flex justify-end"><x-primary-button>Activar</x-primary-button></div>
        </form>
    @endif

    @include('auth.two-factor._logout')
</x-guest-layout>
