{{-- Conectar Telegram (técnico). Canal adicional a las notificaciones push. --}}
@if (\App\Services\Telegram\TelegramService::isConfigured() && auth()->user()?->canReceivePush())
@php($user = auth()->user())
<div id="telegram" class="rounded-3xl border bg-white p-5 shadow-[0_6px_20px_-8px_rgba(20,23,28,0.18)]" style="border-color:#D6E9F8;">
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xl" style="background:#E8F4FD;" aria-hidden="true">✈️</span>

        <div class="min-w-0 flex-1">
            <h2 class="font-bold text-slate-900">Avisos por Telegram</h2>

            @if (session('success') && request()->is('*/profile'))
                <p class="mt-1 text-sm text-emerald-700">{{ session('success') }}</p>
            @endif
            @if (session('error'))
                <p class="mt-1 text-sm text-red-600">{{ session('error') }}</p>
            @endif

            @if ($user->hasTelegram())
                <p class="mt-1 text-sm font-semibold text-emerald-700">✓ Telegram conectado</p>
                <p class="mt-1 text-sm text-slate-500">Te llegan los mismos avisos por Telegram. Para cortarlos, desconectalo o escribile /stop al bot.</p>
                <form method="POST" action="{{ route('telegram.disconnect', ['company' => $user->company->slug]) }}" class="mt-3">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">Desconectar Telegram</button>
                </form>
            @else
                <p class="mt-1 text-sm text-slate-600">
                    Recibí los mismos avisos por Telegram. Funciona en cualquier celular, también en iPhone sin instalar nada más.
                </p>
                <a href="{{ route('telegram.connect', ['company' => $user->company->slug]) }}"
                   class="mt-3 inline-flex rounded-2xl px-5 py-3 font-bold text-white"
                   style="background:#229ED9;">
                    Conectar Telegram
                </a>
                <p class="mt-2 text-xs text-slate-500">Se abre Telegram: tocá <strong>Iniciar</strong> y listo. El link vence en 15 minutos.</p>
            @endif
        </div>
    </div>
</div>
@endif
