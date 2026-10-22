<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Un paso más</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Verificá tu email</h1>
    <p class="mt-3 text-sm text-ink/50 leading-relaxed">
        Confirmá tu dirección de email con el enlace que te enviamos a <strong class="text-ink">{{ auth()->user()->email }}</strong>. El enlace vence en {{ config('auth.verification.expire', 60) }} minutos; si no te llegó o venció, pedí otro.
    </p>

    @if (session('status') == 'verification-link-expired')
        <div class="mt-5 rounded-xl border border-amber-500/30 bg-amber-100 px-4 py-3 text-sm font-medium text-amber-700" role="alert">
            El enlace venció o no es válido. Pedí uno nuevo con el botón de abajo.
        </div>
    @endif

    @if (session('status') == 'verification-throttled')
        <div class="mt-5 rounded-xl border border-amber-500/30 bg-amber-100 px-4 py-3 text-sm font-medium text-amber-700" role="alert">
            Ya te mandamos varios enlaces hace poco. Esperá unos minutos y volvé a pedirlo.
        </div>
    @endif

    @if (session('status') == 'verification-email-changed')
        <div class="mt-5 rounded-xl border border-rail-100 bg-rail-100/60 px-4 py-3 text-sm font-medium text-rail-600" role="status">
            Listo: actualizamos tu correo y te mandamos el enlace a <strong>{{ auth()->user()->email }}</strong>.
        </div>
    @endif

    @if (session('status') == 'verification-link-sent')
        <div class="mt-5 flex items-center gap-2 rounded-xl border border-rail-100 bg-rail-100/60 px-4 py-3 text-sm font-medium text-rail-600">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Te enviamos un nuevo enlace de verificación al email que registraste.
        </div>
    @endif

    @if (\App\Support\Auth\EmailGate::appliesTo(auth()->user()))
        <p class="mt-3 text-sm text-ink/60 leading-relaxed" data-verify-required>
            Para usar Ascento tenés que confirmarlo: hasta entonces no se puede entrar al panel. Lo que ya cargaste en la configuración inicial queda guardado.
        </p>
    @endif

    <div class="mt-5">
        <a href="{{ \App\Support\HomeRedirect::url() }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800 underline underline-offset-4" data-verify-continue>Ya lo confirmé, seguir</a>
    </div>

    <div class="mt-7 flex items-center justify-between gap-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>
                Reenviar email de verificación
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm font-medium text-ink/50 hover:text-ink transition-colors">
                Cerrar sesión
            </button>
        </form>
    </div>

    <details class="mt-7 rounded-xl border border-ink/10 px-4 py-3" data-verify-change-email @if($errors->has('email')) open @endif>
        <summary class="cursor-pointer text-sm font-semibold text-ink">¿Escribiste mal tu correo?</summary>
        <form method="POST" action="{{ route('verification.change-email') }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <x-input-label for="email" value="Tu correo correcto" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required autocomplete="email" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <x-primary-button>Cambiar y mandar el enlace</x-primary-button>
        </form>
    </details>

    <p class="mt-5 text-xs text-ink/50 leading-relaxed">
        ¿Sigue sin llegar? Revisá la carpeta de spam o escribinos a <a href="mailto:{{ config('app.support_email') }}" class="underline">{{ config('app.support_email') }}</a>.
    </p>
</x-guest-layout>
