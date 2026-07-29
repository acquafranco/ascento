<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Un paso más</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Verificá tu email</h1>
    <p class="mt-3 text-sm text-ink/50 leading-relaxed">
        ¡Gracias por registrarte! Antes de empezar, confirmá tu dirección de email haciendo clic en el enlace que te enviamos. Si no lo recibiste, te mandamos otro con gusto.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mt-5 flex items-center gap-2 rounded-xl border border-rail-100 bg-rail-100/60 px-4 py-3 text-sm font-medium text-rail-600">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Te enviamos un nuevo enlace de verificación al email que registraste.
        </div>
    @endif

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
</x-guest-layout>
