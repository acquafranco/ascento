<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Acceso</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Iniciá sesión</h1>
    <p class="mt-1.5 text-sm text-ink/50">Entrá a tu cuenta para administrar tu empresa.</p>

    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="vos@empresa.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Contraseña')" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer select-none">
                <input id="remember_me" type="checkbox" class="rounded border-ink/20" name="remember">
                <span class="text-sm text-ink/60">Recordarme</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm font-medium text-rail-500 hover:text-rail-600 transition-colors" href="{{ route('password.request') }}">
                    ¿Olvidaste tu contraseña?
                </a>
            @endif
        </div>

        <div class="flex items-center justify-between gap-4 pt-2">
            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="text-sm font-medium text-ink/50 hover:text-ink transition-colors">
                    Crear cuenta
                </a>
            @else
                <span></span>
            @endif

            <x-primary-button>
                Entrar
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
