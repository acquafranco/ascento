{{--
    Respuesta a un envío del pedido de ayuda RETIRADO (página vieja abierta en
    un teléfono). No se registró nada: se dice claro y se indica qué hacer.
--}}
<section class="qr-result qr-bad" data-help-result="discontinued" role="status">
    <h2>Esta opción ya no está disponible</h2>
    <p>Desde esta página no se pueden pedir rescates ni asistencia. <strong>No se registró ningún pedido y no se avisó a nadie.</strong></p>
    <p>Si hay alguien atrapado o en peligro, usá la alarma o el intercomunicador del ascensor y llamá al <strong>911</strong>.</p>
    @if(! empty($back))<p><a href="{{ $back }}" class="qr-btn">Ver la información del ascensor o reportar un problema</a></p>@endif
</section>
