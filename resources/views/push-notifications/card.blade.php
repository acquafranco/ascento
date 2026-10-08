{{--
    Notificaciones del técnico (componente Alpine pushNotifications,
    resources/js/push-notifications.js).

    $compact = true: barra fina del inicio. Solo aparece cuando hay algo para
    hacer (activar, o abrir en otro navegador) y lleva al Perfil para el resto.
--}}
@php($compact = $compact ?? false)

@if (auth()->user()?->canReceiveWorkOrderPush())
@if ($compact)
<div
    x-data="pushNotifications"
    x-show="['default', 'error', 'denied', 'ios-safari', 'ios-other'].includes(state)"
    x-cloak
    class="flex items-center gap-3 rounded-2xl border bg-white px-4 py-3"
    style="border-color:#FFE1CC;"
    data-push-card
>
    <span class="text-xl" aria-hidden="true">🔔</span>

    <p class="min-w-0 flex-1 text-sm leading-snug text-slate-700">
        <span x-show="state === 'default' || state === 'error'">Activá los avisos y enterate al instante de las órdenes nuevas.</span>
        <span x-show="state === 'ios-safari'">En iPhone, agregá Ascento a la pantalla de inicio para recibir avisos.</span>
        <span x-show="state === 'ios-other'">En iPhone los avisos funcionan solo desde Safari.</span>
        <span x-show="state === 'denied'">Los avisos están bloqueados en este navegador.</span>
    </p>

    <button
        type="button"
        x-show="state === 'default' || state === 'error'"
        @click="enable()"
        :disabled="busy"
        class="shrink-0 rounded-xl px-3 py-2 text-sm font-bold text-white disabled:opacity-60"
        style="background:#FF6A1A;"
        data-push-enable
    >
        <span x-show="!busy">Activar</span>
        <span x-show="busy">…</span>
    </button>

    <a
        x-show="['ios-safari', 'ios-other', 'denied'].includes(state)"
        href="{{ route('profile.edit', ['company' => auth()->user()->company->slug]) }}#notificaciones"
        class="shrink-0 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700"
    >
        Ver cómo
    </a>
</div>
@else
<div
    x-data="pushNotifications"
    class="rounded-3xl border bg-white p-5 shadow-[0_6px_20px_-8px_rgba(20,23,28,0.18)]"
    style="border-color:#FFE1CC;"
    data-push-card
>
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xl" style="background:#FFF1E8;" aria-hidden="true">🔔</span>

        <div class="min-w-0 flex-1">
            <h2 class="font-bold text-slate-900">Avisos de órdenes nuevas</h2>

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
                    <span x-show="busy" x-cloak>Esperando tu respuesta…</span>
                </button>

                <p x-show="promptHint" x-cloak class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800" role="status">
                    ¿No te apareció el cartel para permitir? Tocá el <strong>candado 🔒</strong> (o la
                    <strong>campanita</strong>) al lado de la dirección, arriba, y elegí
                    <strong>Notificaciones → Permitir</strong>.
                </p>
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

            {{-- Bloqueadas --}}
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

            {{-- iPhone/iPad en Safari, sin instalar --}}
            <div x-show="state === 'ios-safari'" x-cloak>
                <p class="mt-1 text-sm text-slate-600">
                    En iPhone las notificaciones funcionan con Ascento <strong>agregado a la pantalla de inicio</strong>
                    (iOS 16.4 o más nuevo):
                </p>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
                    <li>Tocá el botón <strong>Compartir</strong> (el cuadrado con la flecha hacia arriba).</li>
                    <li>Elegí <strong>Agregar a inicio</strong> y confirmá.</li>
                    <li>Abrí Ascento desde el ícono nuevo y activá las notificaciones desde ahí.</li>
                </ol>
                @if (\App\Services\Telegram\TelegramService::isConfigured())
                    <p class="mt-2 text-sm text-slate-600">¿Más fácil? Conectá <strong>Telegram</strong> acá abajo y te llegan ahí, sin instalar nada.</p>
                @endif
            </div>

            {{-- iPhone/iPad en Chrome, app de Google, etc. --}}
            <div x-show="state === 'ios-other'" x-cloak>
                <p class="mt-1 text-sm text-slate-600">
                    En iPhone, Chrome y la app de Google no pueden mostrar notificaciones de páginas web.
                    Abrí Ascento en <strong>Safari</strong>, agregalo a la pantalla de inicio
                    (Compartir → Agregar a inicio) y activalas desde el ícono nuevo.
                </p>
                @if (\App\Services\Telegram\TelegramService::isConfigured())
                    <p class="mt-2 text-sm text-slate-600">¿Más fácil? Conectá <strong>Telegram</strong> acá abajo y te llegan ahí, sin instalar nada.</p>
                @endif
            </div>

            {{-- No soportado --}}
            <p x-show="state === 'unsupported'" x-cloak class="mt-1 text-sm text-slate-600">
                Este navegador no permite notificaciones. En Android usá <strong>Chrome</strong>.
                Mientras tanto, revisá tus órdenes en <strong>Trabajos</strong>.
            </p>

            {{-- Servidor sin configurar --}}
            <p x-show="state === 'unconfigured'" x-cloak class="mt-1 text-sm text-slate-500">
                Los avisos todavía no están disponibles en tu empresa. Avisale al administrador.
            </p>

            <p x-show="message" x-text="message" x-cloak role="status" aria-live="polite"
               class="mt-3 text-sm" :class="state === 'error' ? 'text-red-600' : 'text-slate-600'"></p>
        </div>
    </div>
</div>
@endif
@endif
