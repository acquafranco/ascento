{{--
    Resultado del reporte. Nunca afirma que alguien lo vio, que va un técnico
    ni que el problema se va a resolver: solo lo que el servidor registró.
--}}
<section class="qr-result {{ in_array($state, ['registered', 'duplicate'], true) ? 'qr-ok' : 'qr-bad' }}" data-report-result="{{ $state }}" role="status">
    @switch($state)
        @case('registered')
            <h2>Tu reporte fue registrado</h2>
            <p>Código <strong>{{ $reference }}</strong> · {{ $date }} {{ $at }}.</p>
            <p>La empresa responsable podrá revisarlo a través de Ascento. Enviar este formulario no garantiza una respuesta inmediata ni que alguien ya lo haya visto.</p>
            @break
        @case('duplicate')
            <h2>Este reporte ya estaba registrado</h2>
            <p>Código <strong>{{ $reference }}</strong> · {{ $date }} {{ $at }}. No se registró dos veces.</p>
            <p>La empresa responsable podrá revisarlo a través de Ascento. Enviar este formulario no garantiza una respuesta inmediata.</p>
            @break
        @case('expired')
            <h2>No pudimos registrar el reporte</h2>
            <p>La página quedó vencida o se envió demasiado rápido. Volvé a abrir el formulario y envialo otra vez.</p>
            @break
        @case('limited')
            <h2>No pudimos registrar el reporte ahora</h2>
            <p>Se recibieron muchos reportes de este edificio en poco tiempo. Probá de nuevo más tarde.</p>
            @break
        @default
            <h2>No pudimos registrar el reporte desde acá</h2>
            <p>Este código no recibe reportes en este momento.</p>
    @endswitch
    @if(! empty($back))<p style="margin-top:.8rem"><a href="{{ $back }}" class="qr-btn">Volver a la información del ascensor</a></p>@endif
</section>
