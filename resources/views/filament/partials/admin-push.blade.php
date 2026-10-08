{{--
    Avisos al celular/compu del ADMIN (trabajo terminado, reportes nuevos).
    $compact = true: barra del Inicio que solo aparece si falta activarlos.
--}}
@php($compact = $compact ?? false)

<style>
    .ap-card { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; padding: .875rem 1rem; border-radius: .75rem;
        border: 1px solid color-mix(in oklab, var(--primary-500) 35%, transparent); background: color-mix(in oklab, var(--primary-500) 8%, transparent); }
    .ap-text { flex: 1; min-width: 12rem; font-size: .875rem; color: var(--gray-700); }
    .dark .ap-text { color: var(--gray-200); }
    .ap-text strong { color: var(--gray-950); }
    .dark .ap-text strong { color: #fff; }
    .ap-hint { width: 100%; font-size: .8125rem; color: var(--gray-500); }
    .ap-ok { color: var(--success-600); font-weight: 600; }
    .ap-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
    .ap-card--compact { margin: 1rem 0 .5rem; }
</style>

<div
    x-data="pushNotifications"
    @if ($compact) x-show="!dismissed && ['default', 'error', 'denied'].includes(state)" x-cloak @endif
    class="ap-card {{ $compact ? 'ap-card--compact' : '' }}"
    data-admin-push
>
    <span aria-hidden="true" style="font-size:1.25rem">🔔</span>

    <div class="ap-text">
        <span x-show="state === 'loading'">Revisando este dispositivo…</span>
        <span x-show="state === 'default' || state === 'error'">
            <strong>Recibí avisos en este dispositivo</strong> cuando un técnico termina un trabajo o carga un reporte, aunque no tengas Ascento abierto.
        </span>
        <span x-show="state === 'subscribed'" class="ap-ok">✓ Avisos activados en este dispositivo.</span>
        <span x-show="state === 'denied'">Los avisos están <strong>bloqueados</strong> en este navegador: tocá el candado junto a la dirección → Notificaciones → Permitir, y recargá.</span>
        <span x-show="state === 'ios-safari'">En iPhone/iPad: Compartir → <strong>Agregar a inicio</strong>, abrí Ascento desde ese ícono y activá los avisos ahí.</span>
        <span x-show="state === 'ios-other'">En iPhone/iPad los avisos funcionan solo desde <strong>Safari</strong> con Ascento agregado a la pantalla de inicio.</span>
        <span x-show="state === 'unsupported'">Este navegador no permite avisos. Probá con Chrome, Edge o Safari.</span>
        <span x-show="state === 'unconfigured'">Los avisos todavía no están configurados en el servidor.</span>
    </div>

    <div class="ap-actions">
        <x-filament::button x-show="state === 'default' || state === 'error'" x-on:click="enable()" x-bind:disabled="busy" size="sm" icon="heroicon-m-bell-alert">
            <span x-show="!busy">Activar avisos</span>
            <span x-show="busy" x-cloak>Esperando tu respuesta…</span>
        </x-filament::button>

        @if ($compact)
            <x-filament::button x-on:click="dismiss()" size="sm" color="gray">Ahora no</x-filament::button>
        @endif

        @unless ($compact)
            <x-filament::button x-show="state === 'subscribed'" x-cloak x-on:click="test()" x-bind:disabled="busy" size="sm" color="gray">Enviar prueba</x-filament::button>
            <x-filament::button x-show="state === 'subscribed'" x-cloak x-on:click="disable()" x-bind:disabled="busy" size="sm" color="gray">Desactivar</x-filament::button>
        @endunless
    </div>

    <p class="ap-hint" x-show="promptHint" x-cloak role="status">
        ¿No apareció el cartel para permitir? Tocá el candado 🔒 (o la campanita) junto a la dirección y elegí Notificaciones → Permitir.
    </p>
    <p class="ap-hint" x-show="message" x-text="message" x-cloak role="status"></p>
</div>
