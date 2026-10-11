<x-portal-layout title="Avisos">
    <a class="p-back" href="{{ route('portal.home') }}">← Mis edificios</a>
    <h1 class="p-h1">Avisos</h1>
    <p class="p-sub">Lo que {{ $portalCompany?->name }} compartió con vos.</p>

    @include('portal.partials.push-card', ['company' => $portalCompany])
    <div style="height:12px"></div>

    @if(session('status'))<p class="p-muted">{{ session('status') }}</p>@endif

    @if($unread > 0)
        <form method="POST" action="{{ route('portal.notifications.read-all') }}" style="margin-bottom: 12px">
            @csrf
            <button class="p-btn secondary" type="submit">Marcar todos como leídos</button>
        </form>
    @endif

    @forelse($notifications as $notification)
        @if($loop->first)<ul class="p-list">@endif
        <li>
            <a class="p-row" href="{{ route('portal.notifications.open', $notification->id) }}">
                <span class="p-row-main">
                    <span class="p-icon" aria-hidden="true">🔔</span>
                    <span style="min-width:0">
                        <span class="p-row-title" style="{{ $notification->read_at ? 'font-weight:500' : '' }}">{{ $notification->data['title'] ?? 'Aviso' }}</span>
                        <span class="p-row-text">{{ $notification->data['body'] ?? '' }}</span>
                    </span>
                </span>
                <span class="p-row-side">
                    @unless($notification->read_at)<span class="p-tag warn">Nuevo</span>@endunless
                    <span class="p-muted" style="display:block;margin-top:4px">{{ $notification->created_at->format('d/m/Y H:i') }}</span>
                </span>
            </a>
        </li>
        @if($loop->last)</ul>@endif
    @empty
        <div class="p-empty">Todavía no tenés avisos. Cuando {{ $portalCompany?->name }} comparta un remito, reporte, presupuesto o documento de tus edificios, lo vas a ver acá y te llega un correo.</div>
    @endforelse

    @include('portal.partials.pager', ['paginator' => $notifications])
</x-portal-layout>
