<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Recuperar acceso</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">¿Olvidaste tu contraseña?</h1>
    <p class="mt-1.5 text-sm text-ink/50 leading-relaxed">
        No hay problema. Dejanos tu email y te enviamos un enlace para elegir una nueva contraseña.
    </p>

    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus placeholder="vos@empresa.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div class="flex items-center justify-between gap-4 pt-2">
            <a href="{{ route('login') }}" class="text-sm font-medium text-ink/50 hover:text-ink transition-colors">
                Volver a iniciar sesión
            </a>

            <x-primary-button>
                Enviar enlace
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
