{{--
    Ubicación del técnico: voluntaria, transparente y revocable.
    Dos condiciones distintas: (1) activarla en Ascento (este interruptor, se
    guarda en el servidor) y (2) el permiso del navegador del celular (lo da
    el técnico al tocar «Permitir ubicación»). Activar y desactivar funcionan
    sin JavaScript (formularios); el permiso del navegador necesita JavaScript.
--}}
@php
    $loc = app(\App\Services\Location\TechnicianLocationService::class)->of($user);
    $enabled = (bool) $loc?->tracking_enabled;
    $slug = $user->company?->slug;
    $freshMin = intdiv((int) config('location.fresh_seconds', 300), 60);
    $every = (int) config('location.send_interval', 60);
@endphp
<section id="ubicacion" data-location-settings x-data="{
        enabled: @js($enabled),
        perm: 'checking',
        busy: false,
        note: '',
        async refresh() { this.perm = await window.AscentoLocation?.permissionState?.() ?? 'unsupported' },
        async allow() {
            this.busy = true; this.note = ''
            const result = await window.AscentoLocation.requestPermission()
            await this.refresh()
            if (result === 'granted') { this.note = 'Listo: este celular ya puede mandar tu ubicación mientras Ascento esté abierto.'; window.AscentoLocation.startTracking() }
            else if (result === 'denied') { this.note = 'El celular no dio permiso. Seguí las instrucciones de abajo para habilitarlo.'; this.report('denied') }
            else { this.note = 'No se pudo obtener la ubicación ahora (sin señal o sin GPS). Probá de nuevo en un rato.' }
            this.busy = false
        },
        report(permission) {
            fetch(@js(route('location.report', ['company' => $slug])), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ permission }) }).catch(() => {})
        },
    }" x-init="refresh()">
    <h2 class="text-base font-bold text-ink">Ubicación</h2>
    @if(session('location_status'))
        <p class="t-alert-success mt-2" role="status">{{ session('location_status') }}</p>
    @endif
    <p class="mt-1 text-sm text-ink-700">
        Si la activás, la oficina de {{ $user->company?->name }} ve en su mapa dónde estás, para coordinar mejor los trabajos y las guardias. Es voluntaria y la podés desactivar cuando quieras.
    </p>

    <ul class="mt-3 space-y-1.5 text-sm text-ink-700">
        <li><strong>Qué se comparte:</strong> solo tu última posición y su hora. No se guarda un recorrido.</li>
        <li><strong>Cuándo se actualiza:</strong> como mucho cada {{ $every }} segundos, <strong>solo mientras Ascento está abierto en la pantalla</strong>. Con el celular bloqueado o la app en segundo plano no se actualiza: la oficina ve la última posición marcada como desactualizada (después de {{ $freshMin }} minutos).</li>
        <li><strong>Qué puede impedirlo:</strong> no dar permiso al navegador, estar sin señal o sin GPS, el ahorro de batería o tener Ascento cerrado.</li>
        <li>Al desactivarla o cerrar sesión, se borra tu última posición.</li>
    </ul>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-ink/10 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">1. En Ascento</p>
            <p class="mt-1 font-semibold {{ $enabled ? 'text-emerald-700' : 'text-ink' }}" data-location-enabled="{{ $enabled ? '1' : '0' }}">{{ $enabled ? 'Activada' : 'Desactivada' }}</p>
            @if($enabled && $loc?->located_at)
                <p class="mt-1 text-xs text-ink-500">Última posición enviada: {{ $loc->located_at->translatedFormat('d/m H:i') }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-ink/10 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">2. Permiso de este celular</p>
            <p class="mt-1 font-semibold text-ink" data-location-permission>
                <span x-show="perm === 'checking'">Revisando…</span>
                <span x-show="perm === 'granted'" x-cloak class="text-emerald-700">Permitido</span>
                <span x-show="perm === 'prompt' || perm === 'unknown'" x-cloak>Sin permiso todavía</span>
                <span x-show="perm === 'denied'" x-cloak class="text-red-700">Bloqueado</span>
                <span x-show="perm === 'insecure' || perm === 'unsupported'" x-cloak>No disponible en este navegador</span>
            </p>
            <noscript><p class="mt-1 text-xs text-ink-500">Necesita JavaScript.</p></noscript>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        @if($enabled)
            <button type="button" class="t-btn-primary" x-show="perm !== 'granted' && perm !== 'denied' && perm !== 'insecure' && perm !== 'unsupported'" x-cloak x-on:click="allow()" x-bind:disabled="busy" data-location-allow>Permitir ubicación en este celular</button>
            <form method="POST" action="{{ route('location.disable', ['company' => $slug]) }}" x-on:submit="window.AscentoLocation?.stopTracking?.()">
                @csrf
                <button type="submit" class="t-btn-dark" data-location-disable>Desactivar ubicación</button>
            </form>
        @else
            <form method="POST" action="{{ route('location.enable', ['company' => $slug]) }}">
                @csrf
                <button type="submit" class="t-btn-primary" data-location-enable>Activar ubicación</button>
            </form>
        @endif
    </div>
    <p class="mt-2 text-sm text-ink-700" x-show="note" x-text="note" role="status"></p>

    <details class="mt-4 text-sm text-ink-700">
        <summary class="cursor-pointer font-semibold text-ink">Cómo dar o quitar el permiso del celular</summary>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li><strong>Android (Chrome):</strong> tocá el candado a la izquierda de la dirección → Permisos → Ubicación → Permitir. Revisá también que la ubicación del celular esté encendida.</li>
            <li><strong>iPhone (Safari o Ascento en la pantalla de inicio):</strong> Ajustes → Privacidad y seguridad → Localización → activala, y en Safari (o en «Sitios web de Safari») elegí «Preguntar» o «Permitir».</li>
            <li>Activar la ubicación en Ascento y dar permiso al celular son dos cosas distintas: hacen falta las dos.</li>
        </ul>
    </details>
</section>
