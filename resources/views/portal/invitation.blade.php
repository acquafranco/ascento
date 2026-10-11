<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Portal de clientes</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Activá tu cuenta</h1>
    <p class="mt-1.5 text-sm text-ink/50">Hola {{ $name }}. Elegí la contraseña con la que vas a ingresar al portal.</p>

    <form method="POST" action="{{ route('portal.invitation.store') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="$email" readonly autocomplete="username" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Contraseña')" />
            <x-password-input id="password" name="password" required autofocus autocomplete="new-password" aria-describedby="password-hint" />
            <p id="password-hint" class="mt-1.5 text-xs text-ink/50">{{ \App\Support\Auth\PasswordPolicy::HINT }}</p>
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Repetir contraseña')" />
            <x-password-input id="password_confirmation" name="password_confirmation" required autocomplete="new-password" />
        </div>

        <div class="flex items-center justify-end pt-2">
            <x-primary-button>Activar mi cuenta</x-primary-button>
        </div>
    </form>
</x-guest-layout>
