{{-- Aviso de seguridad común a todas las pantallas del reporte (nunca promete asistencia). --}}
<p class="qr-safety" data-qr="safety">
    Este formulario <strong>no es un servicio de emergencias</strong> y no garantiza una respuesta inmediata.
    Si hay peligro o alguien necesita asistencia urgente, usá la alarma o el intercomunicador del ascensor y llamá al <strong>911</strong>.
    @if(! empty($phone)) También podés llamar a la empresa de mantenimiento al <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" style="color:#B84A00;font-weight:700">{{ $phone }}</a>. @endif
</p>
