{{--
    Tarjeta "Notificaciones" del técnico (componente Alpine pushNotifications,
    resources/js/push-notifications.js).

    $compact = true: versión del inicio; se oculta sola cuando ya están
    activadas o el servidor no tiene push configurado.
--}}
@php($compact = $compact ?? false)

@if (auth()->user()?->canReceiveWorkOrderPush())
<div
    x-data="pushNotifications"
    @if ($compact)
        x-show="!['loading', 'subscribed', 'unconfigured'].includes(state)"
        x-cloak
    @endif
    class="rounded-3xl border bg-white p-5 shadow-[0_6px_20px_-8px_rgba(20,23,28,0.18)]"
    style="border-color:#FFE1CC;"
    data-push-card
>
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xl" style="background:#FFF1E8;" aria-hidden="true">🔔</span>

        <div class="min-w-0 flex-1">
            <h2 class="font-bold text-slate-900">Avisos de órdenes nuevas</h2>

            {{-- Cargando --}}
            <p x-show="state === 'loading'" class="mt-1 text-sm text-slate-500">Revisando este dispositivo…</p>

            {{-- Sin activar --}}
            <div x-show="state === 'default' || state === 'error'" x-cloak>
                <p class="mt-1 text-sm text-slate-600">
                    Activalas y te avisamos en el celular apenas te asignen una orden de trabajo,
                    aunque no tengas Ascento abierto.
                </p>
                <button
                    type="button"
                    @click="enable()"
                    :disabled="busy"
                    class="mt-3 w-full rounded-2xl px-5 py-3 font-bold text-white transition disabled:opacity-60 sm:w-auto"
                    style="background:#FF6A1A;"
                    data-push-enable
                >
                    <span x-show="!busy">Activar notificaciones</span>
                    <span x-show="busy" x-cloak>Activando…</span>
                </button>
            </div>

            {{-- Activadas --}}
            <div x-show="state === 'subscribed'" x-cloak>
                <p class="mt-1 text-sm font-semibold text-emerald-700" data-push-status-on>
                    ✓ Notificaciones activadas en este dispositivo
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    Si usás otro celular o tablet, activalas también ahí.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" @click="test()" :disabled="busy"
                        class="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-60">
                        Enviar prueba
                    </button>
                    <button type="button" @click="disable()" :disabled="busy"
                        class="rounded-2xl px-4 py-2 text-sm font-semibold text-slate-500 underline-offset-2 hover:underline disabled:opacity-60">
                        Desactivar
                    </button>
                </div>
            </div>

            {{-- Bloqueadas por el usuario --}}
            <div x-show="state === 'denied'" x-cloak>
                <p class="mt-1 text-sm text-slate-600">
                    Las notificaciones están <strong>bloqueadas</strong> para Ascento en este navegador.
                    Para volver a activarlas:
                </p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
                    <li><strong>Android (Chrome):</strong> tocá el candado junto a la dirección → Permisos → Notificaciones → Permitir.</li>
                    <li><strong>iPhone:</strong> Ajustes → Notificaciones → Ascento → Permitir notificaciones.</li>
                    <li><strong>Computadora:</strong> clic en el candado de la barra de direcciones → Notificaciones → Permitir.</li>
                </ul>
                <button type="button" @click="window.location.reload()"
                    class="mt-3 rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                    Ya lo cambié, revisar de nuevo
                </button>
            </div>

            {{-- iPhone / iPad sin instalar --}}
            <div x-show="state === 'ios-install'" x-cloak>
                <p class="mt-1 text-sm text-slate-600">
                    En iPhone las notificaciones solo funcionan si Ascento está
                    <strong>agregado a la pantalla de inicio</strong> (iOS 16.4 o más nuevo):
                </p>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
                    <li>Abrí esta página en <strong>Safari</strong>.</li>
                    <li>Tocá el botón <strong>Compartir</strong> (el cuadrado con la flecha hacia arriba).</li>
                    <li>Elegí <strong>Agregar a inicio</strong> y confirmá.</li>
                    <li>Abrí Ascento desde el ícono nuevo y volvé acá para activarlas.</li>
                </ol>
            </div>

            {{-- No soportado --}}
            <p x-show="state === 'unsupported'" x-cloak class="mt-1 text-sm text-slate-600">
                Este navegador no permite notificaciones. En Android usá <strong>Chrome</strong>;
                en iPhone, Safari con Ascento agregado a la pantalla de inicio.
                Mientras tanto, revisá tus órdenes en <strong>Trabajos</strong>.
            </p>

            {{-- Servidor sin configurar --}}
            <p x-show="state === 'unconfigured'" x-cloak class="mt-1 text-sm text-slate-500">
                Los avisos todavía no están disponibles en tu empresa.
            </p>

            <p x-show="message" x-text="message" x-cloak role="status" aria-live="polite"
               class="mt-3 text-sm" :class="state === 'error' ? 'text-red-600' : 'text-slate-600'"></p>
        </div>
    </div>
</div>
@endif
