{{-- Resultado REAL del pedido de ayuda (lo decide el servidor). Nunca afirma que alguien lo vio o lo atendió. --}}
@php $ok = in_array($state, ['registered', 'registered_unnotified'], true); @endphp
<section class="qr-result {{ $state === 'registered' ? 'qr-ok' : 'qr-bad' }}" data-help-result="{{ $state }}" role="status">
    @switch($state)
        @case('registered')
            <h2>{{ $repeat ? 'Tu pedido se sumó al que ya estaba registrado' : 'Registramos tu pedido de ayuda' }}</h2>
            <p>Código <strong>{{ $reference }}</strong>{{ $repeat ? ', registrado el '.$date.' a las '.$at : ' · '.$date.' '.$at }}.</p>
            <p>Avisamos por Ascento a la empresa de mantenimiento y a la administración del edificio. Ascento no puede confirmar que ya lo hayan visto.</p>
            @break
        @case('registered_unnotified')
            <h2>Registramos tu pedido, pero no pudimos avisar a nadie</h2>
            <p>Código <strong>{{ $reference }}</strong> · {{ $date }} {{ $at }}. No encontramos a quién avisar en este momento.</p>
            @break
        @case('expired')
            <h2>No pudimos registrar el pedido</h2>
            <p>La página quedó vencida. Volvé a abrirla y tocá otra vez el botón.</p>
            @if($back)<p><a href="{{ $back }}" class="qr-btn qr-btn-light" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Volver a abrir la página</a></p>@endif
            @break
        @default
            <h2>No pudimos registrar el pedido desde acá</h2>
            <p>Este código no permite pedir ayuda en este momento.</p>
    @endswitch

    <p class="qr-safety">@if($phone)Podés llamar a la empresa de mantenimiento al <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}"><strong>{{ $phone }}</strong></a>. @endif Usá el botón de alarma o el intercomunicador del ascensor y, si hay peligro inmediato, llamá al <strong>911</strong>.</p>
</section>
