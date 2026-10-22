<x-supplier-layout title="Avisos">
    <h1 class="s-h1">Avisos</h1>
    <p class="s-sub">Pedidos, cotizaciones y novedades de tu cuenta. Los importantes también te llegan por correo.</p>

    @include('suppliers.partials.push-card')

    <section class="s-card">
        @if($unread > 0)
            <form method="POST" action="{{ route('suppliers.notifications.read-all') }}" style="margin-bottom:8px">@csrf<button class="s-btn secondary" type="submit">Marcar todos como leídos</button></form>
        @endif
        @forelse($notifications as $n)
            @if($loop->first)<ul class="s-list">@endif
            <li>
                <a class="s-item" href="{{ route('suppliers.notifications.open', $n->id) }}" data-notification-id="{{ $n->id }}" @unless($n->read_at) data-notification-unread @endunless>
                    <span style="min-width:0">
                        <strong style="font-size:14px; {{ $n->read_at ? 'font-weight:500' : '' }}">{{ $n->data['title'] ?? 'Aviso' }}</strong><br>
                        <span class="s-muted">{{ $n->data['body'] ?? '' }}</span>
                    </span>
                    <span style="text-align:right; flex-shrink:0">
                        @unless($n->read_at)<span class="s-tag warn">Nuevo</span><br>@endunless
                        <span class="s-muted">{{ $n->created_at->format('d/m/Y H:i') }}</span>
                    </span>
                </a>
            </li>
            @if($loop->last)</ul>@endif
        @empty
            <p class="s-muted">Todavía no tenés avisos. Cuando una empresa te pida una cotización, aparece acá y te llega por correo.</p>
        @endforelse
        @if($notifications->hasPages())<div style="margin-top:10px">{{ $notifications->links() }}</div>@endif
    </section>
    @include('partials.notifications-seen-inbox', ['seenUrl' => route('suppliers.notifications.seen')])
</x-supplier-layout>
