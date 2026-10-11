{{--
    Confirmación del correo del admin de empresa (no bloquea el uso). Se ve
    hasta que lo confirma; después de confirmar, una vez, el "listo". El texto
    no afirma que ya se mandó un enlace: las cuentas anteriores a la
    verificación nunca recibieron uno.
--}}
@php
    $user = auth()->user();
    $status = session('status');
@endphp
<div role="status" style="margin-bottom: 1rem; padding: .85rem 1rem; border-radius: .75rem; font-size: .875rem; display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between;
    {{ $user->hasVerifiedEmail() ? 'border:1px solid rgba(34,197,94,.35); background: rgba(34,197,94,.1); color: #16a34a;' : 'border:1px solid rgba(245,158,11,.4); background: rgba(245,158,11,.1); color: #d97706;' }}">
    @if($user->hasVerifiedEmail())
        <span><strong>Correo confirmado.</strong> Gracias: tu cuenta quedó verificada.</span>
    @else
        <span style="flex: 1 1 18rem;">
            <strong>Confirmá tu correo.</strong>
            Usá el enlace de confirmación enviado a <strong>{{ $user->email }}</strong>.
            @if($status === 'verification-link-sent')
                Listo: te mandamos uno nuevo (revisá también spam).
            @elseif($status === 'verification-throttled')
                Ya te mandamos varios hace poco: esperá unos minutos y volvé a pedirlo.
            @elseif($status === 'verification-link-expired')
                El enlace que abriste venció o no es válido: pedí uno nuevo.
            @else
                Si no lo tenés o venció, pedí uno nuevo.
            @endif
        </span>
        <form method="POST" action="{{ route('verification.send') }}" style="margin:0">
            @csrf
            <button type="submit" style="cursor:pointer; padding:.45rem .9rem; border-radius:.5rem; border:0; background:#FF6600; color:#11161D; font-weight:700; font-size:.8rem;">Enviar enlace</button>
        </form>
    @endif
</div>
