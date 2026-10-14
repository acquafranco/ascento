<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Seguridad</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Segundo factor</h1>
    <p class="mt-1.5 text-sm text-ink/50 leading-relaxed">
        Activo desde el {{ $confirmedAt?->format('d/m/Y') }}. Te quedan {{ $remaining }} códigos de recuperación.
    </p>
    @if (session('status'))
        <p class="mt-3 text-sm font-medium text-ink">{{ session('status') }}</p>
    @endif

    @foreach ([
        ['two-factor.regenerate', 'Generar códigos de recuperación nuevos', 'Los anteriores dejan de servir.', false],
        ['two-factor.reconfigure', 'Configurar con otro teléfono', 'Se quita el actual y lo configurás de nuevo enseguida. Se cierran tus otras sesiones.', false],
        ['two-factor.reset-other', 'Restablecer el de otro SuperAdmin', 'Si otro SuperAdmin perdió su teléfono y sus códigos. Se cierran sus sesiones y lo configura al volver a entrar.', true],
    ] as [$route, $title, $help, $withEmail])
        <form method="POST" action="{{ route($route) }}" class="mt-8 space-y-3 border-t border-ink/10 pt-6">
            @csrf
            <h2 class="font-semibold text-ink">{{ $title }}</h2>
            <p class="text-sm text-ink/50">{{ $help }}</p>
            @if ($withEmail)
                <div>
                    <x-input-label for="email-{{ $loop->index }}" value="Correo del otro SuperAdmin" />
                    <x-text-input id="email-{{ $loop->index }}" name="email" type="email" class="mt-1 block w-full" required />
                </div>
            @endif
            <div>
                <x-input-label for="password-{{ $loop->index }}" value="Tu contraseña" />
                <x-password-input id="password-{{ $loop->index }}" name="password" required autocomplete="current-password" />
            </div>
            <div>
                <x-input-label for="code-{{ $loop->index }}" value="Tu código de la app" />
                <x-text-input id="code-{{ $loop->index }}" name="code" type="text" autocomplete="one-time-code" maxlength="20" class="mt-1 block w-full" required />
            </div>
            <x-primary-button>Confirmar</x-primary-button>
        </form>
    @endforeach
    <x-input-error :messages="$errors->all()" class="mt-4" />

    <a href="/admin" class="mt-8 inline-flex font-semibold text-amber-600">Volver al panel</a>
</x-guest-layout>
