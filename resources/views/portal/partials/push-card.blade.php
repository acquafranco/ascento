{{-- Avisos en el celular: el permiso se pide solo al tocar "Activar" (componente pushNotifications). --}}
<div class="p-push" x-data="pushNotifications" x-cloak x-show="!['loading', 'unconfigured', 'unsupported'].includes(state)" data-portal-push>
    <div style="min-width:0;flex:1">
        <strong style="display:block">Avisos en este dispositivo</strong>
        <span class="p-muted" x-show="state === 'default' || state === 'error'">Recibí una notificación cuando {{ $company?->name }} comparta un remito, reporte, presupuesto o documento de tus edificios.</span>
        <span class="p-muted" x-show="state === 'subscribed'">Activados en este dispositivo.</span>
        <span class="p-muted" x-show="state === 'denied'">Están bloqueados en este navegador. Habilitalos desde la configuración del sitio (candado de la barra de direcciones) y recargá.</span>
        <span class="p-muted" x-show="state === 'ios-safari'">En iPhone: tocá Compartir → "Agregar a inicio", abrí Ascento desde ese ícono y activalos ahí.</span>
        <span class="p-muted" x-show="state === 'ios-other'">En iPhone los avisos funcionan solo desde Safari.</span>
        <span class="p-muted" x-show="message" x-text="message" role="status" aria-live="polite"></span>
    </div>
    <div style="display:flex;gap:8px">
        <button type="button" class="p-btn" x-show="state === 'default' || state === 'error'" @click="enable()" :disabled="busy" data-push-enable>
            <span x-show="!busy">Activar</span><span x-show="busy">Esperando…</span>
        </button>
        <button type="button" class="p-btn secondary" x-show="state === 'subscribed'" @click="test()" :disabled="busy">Probar</button>
        <button type="button" class="p-btn secondary" x-show="state === 'subscribed'" @click="disable()" :disabled="busy">Desactivar</button>
    </div>
</div>
