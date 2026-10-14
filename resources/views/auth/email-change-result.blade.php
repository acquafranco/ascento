<x-guest-layout>
    @if ($result === \App\Services\Auth\PendingEmailChange::CONFIRMED)
        <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Listo: tu correo quedó cambiado</h1>
        <p class="mt-3 text-sm text-ink/50 leading-relaxed">Desde ahora entrás y recuperás tu contraseña con el correo nuevo.</p>
    @elseif ($result === \App\Services\Auth\PendingEmailChange::TAKEN)
        <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Ese correo ya está en uso</h1>
        <p class="mt-3 text-sm text-ink/50 leading-relaxed">Otra cuenta empezó a usar esa dirección. Tu correo no cambió: podés pedir el cambio a otra dirección desde tu perfil.</p>
    @else
        <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">El enlace no es válido</h1>
        <p class="mt-3 text-sm text-ink/50 leading-relaxed">Venció, ya se usó o fue reemplazado por un pedido más nuevo. Tu correo no cambió: si todavía querés cambiarlo, pedilo de nuevo desde tu perfil.</p>
    @endif
    <a href="{{ route('login') }}" class="mt-6 inline-flex font-semibold text-amber-600">Ir al ingreso</a>
</x-guest-layout>
