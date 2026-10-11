{{--
    Confirmación del correo de técnicos y clientes del portal. No bloquea nada:
    se puede seguir trabajando y confirmar después (por ejemplo, desde la
    computadora). Se ve hasta que lo confirma; al confirmar, una vez, el "listo".
    Estilos en línea: se usa en la app del técnico y en el portal.
--}}
@php
    $verifyUser = auth()->user();
    $verifyStatus = session('status');
@endphp
@if ($verifyUser && $verifyUser instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $verifyUser->isSuperAdmin() && (! $verifyUser->hasVerifiedEmail() || request()->boolean('verified')))
    <div role="status" data-verify-email-notice
         style="margin: 12px auto 0; max-width: 64rem; padding: 12px 16px; border-radius: 14px; font-size: 14px; line-height: 1.45; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;
         {{ $verifyUser->hasVerifiedEmail() ? 'border:1px solid rgba(34,197,94,.35); background: rgba(34,197,94,.08); color: #15803d;' : 'border:1px solid rgba(245,158,11,.45); background: #FFF8EB; color: #92400e;' }}">
        @if ($verifyUser->hasVerifiedEmail())
            <span><strong>Correo confirmado.</strong> Gracias.</span>
        @else
            <span style="flex: 1 1 16rem;">
                <strong>Confirmá tu correo</strong> ({{ $verifyUser->email }}) con el enlace que te mandamos. Podés seguir usando Ascento mientras tanto.
                @if ($verifyStatus === 'verification-link-sent')
                    <br>Listo: te mandamos uno nuevo (revisá también spam).
                @elseif ($verifyStatus === 'verification-throttled')
                    <br>Ya te mandamos varios hace poco. Esperá unos minutos y volvé a pedirlo.
                @elseif ($verifyStatus === 'verification-link-expired')
                    <br>El enlace que abriste venció o no es válido: pedí uno nuevo.
                @endif
            </span>
            <form method="POST" action="{{ route('verification.send') }}" style="margin: 0">
                @csrf
                <button type="submit" style="cursor: pointer; min-height: 40px; padding: 8px 14px; border-radius: 10px; border: 0; background: #FF6600; color: #11161D; font-weight: 700; font-size: 13px;">Enviar enlace</button>
            </form>
        @endif
    </div>
@endif
