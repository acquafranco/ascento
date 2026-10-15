{{-- Historial del reclamo (panel). Distingue lo que ve el cliente de lo interno. --}}
@php $events = $getRecord()->events()->with('user')->get(); @endphp
<ol style="list-style:none; margin:0; padding:0; display:grid; gap:.6rem" data-claim-history>
    @foreach($events as $event)
        <li style="border:1px solid rgba(148,163,184,.3); border-radius:.6rem; padding:.6rem .75rem" data-claim-event="{{ $event->type }}" data-public="{{ $event->public ? '1' : '0' }}">
            <div style="display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; font-size:.85rem">
                <strong>{{ $event->label() }}</strong>
                <span style="font-size:.7rem; font-weight:700; padding:.1rem .45rem; border-radius:9999px; {{ $event->public ? 'background:rgba(34,197,94,.15); color:#15803d' : 'background:rgba(148,163,184,.2)' }}">{{ $event->public ? 'Visible para el cliente' : 'Interno' }}</span>
                <span style="opacity:.65">{{ $event->created_at?->format('d/m/Y H:i') }} · {{ $event->user?->name ?? 'Sistema' }}</span>
            </div>
            @if($event->body)<p style="margin:.35rem 0 0; white-space:pre-line; font-size:.9rem">{{ $event->body }}</p>@endif
        </li>
    @endforeach
</ol>
