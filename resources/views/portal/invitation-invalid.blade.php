<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Portal de clientes</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">El enlace no es válido</h1>
    <p class="mt-1.5 text-sm text-ink/60">Puede haber vencido (dura 72 horas) o ya se usó. Pedile a tu empresa de mantenimiento que te reenvíe la invitación.</p>
    <p class="mt-6 text-sm"><a class="font-medium text-rail-500 hover:text-rail-600" href="{{ route('portal.login') }}">Ir al ingreso del portal</a> · <a class="font-medium text-rail-500 hover:text-rail-600" href="{{ route('password.request') }}">Olvidé mi contraseña</a></p>
</x-guest-layout>
