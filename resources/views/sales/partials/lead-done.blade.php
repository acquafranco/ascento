<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-5 text-sm text-emerald-900" role="status" data-lead-done>
    @if($email)
        <p class="font-semibold text-base">¡Listo! Recibimos tu pedido.</p>
        <p class="mt-1">El equipo de Ascento lo va a revisar y te va a escribir a <strong>{{ $email }}</strong>. Mientras tanto, podés empezar la prueba gratis cuando quieras.</p>
    @else
        <p class="font-semibold">No pudimos registrar el pedido. Probá de nuevo en unos minutos.</p>
    @endif
</div>
