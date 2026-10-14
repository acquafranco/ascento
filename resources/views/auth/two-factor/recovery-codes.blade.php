<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Seguridad</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">{{ $first ? 'Segundo factor activado' : 'Códigos nuevos' }}</h1>
    <p class="mt-1.5 text-sm text-ink/50 leading-relaxed">
        Guardá estos códigos de recuperación fuera del teléfono (en un gestor de contraseñas o impresos). Cada uno sirve <strong>una sola vez</strong> si perdés el teléfono. <strong>No se vuelven a mostrar.</strong>
        {{ $first ? '' : 'Los códigos anteriores ya no sirven.' }}
    </p>
    <ul class="mt-6 grid grid-cols-2 gap-2 font-mono text-ink" data-recovery-codes>
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>
    <a href="{{ \App\Support\HomeRedirect::url() }}" class="mt-8 inline-flex font-semibold text-amber-600">Ya los guardé, continuar</a>
</x-guest-layout>
