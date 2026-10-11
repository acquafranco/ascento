@php
    $quote = $getRecord()->loadMissing(['events.user', 'receivables', 'creator']);
    $labels = \App\Models\QuoteEvent::LABELS;
    $active = $quote->receivables->firstWhere(fn ($r) => $r->status !== \App\Models\Receivable::VOID);
@endphp
{{-- Estilos en línea: el panel no compila utilidades de Tailwind propias. --}}
<style>
    .asc-qm { display: grid; gap: 1rem; font-size: .875rem; }
    .asc-qm dl { display: grid; grid-template-columns: max-content 1fr; gap: .45rem .9rem; margin: 0; }
    .asc-qm dt, .asc-qm .asc-qm-muted { color: var(--gray-500); }
    .asc-qm dd { margin: 0; }
    .asc-qm .asc-qm-strong { font-weight: 600; }
    .asc-qm .asc-qm-closed { border-radius: .5rem; padding: .75rem; background: rgba(245, 158, 11, .12); color: rgb(217, 119, 6); }
    .asc-qm ol { list-style: none; margin: 0; padding-left: .75rem; border-left: 2px solid rgba(127, 127, 127, .25); display: grid; gap: .55rem; }
    .asc-qm .asc-qm-small { font-size: .75rem; color: var(--gray-500); }
</style>
<div class="asc-qm">
    <dl>
        <dt>Estado</dt>
        <dd class="asc-qm-strong">{{ $quote->displayStatusLabel() }}</dd>
        <dt>Creado por</dt>
        <dd>{{ $quote->creator?->name ?? '—' }}</dd>
        <dt>Último envío</dt>
        <dd>{{ $quote->sent_at ? $quote->sent_at->format('d/m/Y H:i').' · '.$quote->sent_to : 'No se envió por correo' }}</dd>
        <dt>Cobro</dt>
        <dd>
            {{ $active ? 'Generado ($ '.number_format((float) $active->amount, 2, ',', '.').')' : ($quote->status === 'approved' ? 'Pendiente de generar' : 'No corresponde (no está aprobado)') }}
        </dd>
    </dl>

    @if(filled($quote->notes))
        <div>
            <div class="asc-qm-muted">Observaciones internas (el cliente no las ve)</div>
            <div style="white-space: pre-line">{{ $quote->notes }}</div>
        </div>
    @endif

    @unless($quote->isEditable())
        <div class="asc-qm-closed">
            Este presupuesto está cerrado ({{ $quote->displayStatusLabel() }}{{ $quote->hasActiveReceivable() ? ', con cobro' : '' }}): no se edita para no perder la trazabilidad. Usá "Duplicar" para hacer uno nuevo.
        </div>
    @endunless

    <div>
        <div class="asc-qm-strong" style="margin-bottom: .5rem">Historial</div>
        <ol>
            @forelse($quote->events->reverse() as $event)
                <li>
                    <div style="font-weight: 500">{{ $labels[$event->action] ?? $event->action }}{{ $event->detail ? ': '.$event->detail : '' }}</div>
                    <div class="asc-qm-small">{{ $event->created_at?->format('d/m/Y H:i') }}{{ $event->user ? ' · '.$event->user->name : '' }}</div>
                </li>
            @empty
                <li class="asc-qm-muted">Sin movimientos registrados (presupuesto anterior al historial).</li>
            @endforelse
        </ol>
    </div>
</div>
