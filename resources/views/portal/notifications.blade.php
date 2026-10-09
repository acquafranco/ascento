<x-portal-layout :company="$company" title="Avisos">
    <a class="p-back" href="{{ route('portal.home') }}">← Mis edificios</a>
    <h1 class="p-h1">Avisos</h1>
    <p class="p-sub">Lo que {{ $company?->name }} compartió con vos.</p>

    @if(session('status'))<p class="p-muted">{{ session('status') }}</p>@endif

    @if($unread > 0)
        <form method="POST" action="{{ route('portal.notifications.read-all') }}" style="margin-bottom: 12px">
            @csrf
            <button class="p-out" type="submit">Marcar todos como leídos</button>
        </form>
    @endif

    @forelse($notifications as $notification)
        @if($loop->first)<ul class="p-list">@endif
        <li>
            <a href="{{ route('portal.notifications.open', $notification->id) }}" style="{{ $notification->read_at ? 'font-weight: 400' : '' }}">
                {{ $notification->data['title'] ?? 'Aviso' }}
                <span class="p-muted" style="display: block; font-weight: 400">{{ $notification->data['body'] ?? '' }}</span>
            </a>
            <span class="p-muted" style="white-space: nowrap">
                @unless($notification->read_at)<span class="p-tag">Nuevo</span>@endunless
                {{ $notification->created_at->format('d/m/Y H:i') }}
            </span>
        </li>
        @if($loop->last)</ul>@endif
    @empty
        <div class="p-empty">Todavía no tenés avisos. Cuando {{ $company?->name }} comparta un remito, reporte, presupuesto o documento de tus edificios, lo vas a ver acá y te llega un correo.</div>
    @endforelse

    <div style="margin-top: 12px">{{ $notifications->links() }}</div>
</x-portal-layout>
