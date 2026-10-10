@php
    $quote = $getRecord()->loadMissing(['events.user', 'receivables', 'creator']);
    $labels = \App\Models\QuoteEvent::LABELS;
    $active = $quote->receivables->firstWhere(fn ($r) => $r->status !== \App\Models\Receivable::VOID);
@endphp
<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-x-3 gap-y-2">
        <dt class="text-gray-500 dark:text-gray-400">Estado</dt>
        <dd class="font-semibold">{{ $quote->displayStatusLabel() }}</dd>
        <dt class="text-gray-500 dark:text-gray-400">Creado por</dt>
        <dd>{{ $quote->creator?->name ?? '—' }}</dd>
        <dt class="text-gray-500 dark:text-gray-400">Último envío</dt>
        <dd>{{ $quote->sent_at ? $quote->sent_at->format('d/m/Y H:i').' · '.$quote->sent_to : 'No se envió por correo' }}</dd>
        <dt class="text-gray-500 dark:text-gray-400">Cobro</dt>
        <dd>
            {{ $active ? 'Generado ($ '.number_format((float) $active->amount, 2, ',', '.').')' : ($quote->status === 'approved' ? 'Pendiente de generar' : 'No corresponde (no está aprobado)') }}
        </dd>
    </dl>

    @if(filled($quote->notes))
        <div>
            <div class="text-gray-500 dark:text-gray-400">Observaciones internas (el cliente no las ve)</div>
            <div class="whitespace-pre-line">{{ $quote->notes }}</div>
        </div>
    @endif

    @unless($quote->isEditable())
        <div class="rounded-lg bg-amber-50 p-3 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
            Este presupuesto está cerrado ({{ $quote->displayStatusLabel() }}{{ $quote->hasActiveReceivable() ? ', con cobro' : '' }}): no se edita para no perder la trazabilidad. Usá "Duplicar" para hacer uno nuevo.
        </div>
    @endunless

    <div>
        <div class="mb-2 font-semibold">Historial</div>
        <ol class="space-y-2 border-l border-gray-200 pl-3 dark:border-white/10">
            @forelse($quote->events->reverse() as $event)
                <li>
                    <div class="font-medium">{{ $labels[$event->action] ?? $event->action }}{{ $event->detail ? ': '.$event->detail : '' }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $event->created_at?->format('d/m/Y H:i') }}{{ $event->user ? ' · '.$event->user->name : '' }}</div>
                </li>
            @empty
                <li class="text-gray-500">Sin movimientos registrados (presupuesto anterior al historial).</li>
            @endforelse
        </ol>
    </div>
</div>
