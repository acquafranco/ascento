<x-supplier-layout title="Consultas">
    <h1 class="s-h1">Consultas de empresas</h1>
    <p class="s-sub">Solicitudes de contacto a tu perfil profesional. Son gratis y aparte de los pedidos de repuestos.</p>
    @if(! $profile)
        <div class="s-card">Todavía no tenés un perfil profesional. <a class="s-link" href="{{ route('suppliers.service-profile.edit') }}">Crealo gratis</a> para recibir consultas.</div>
    @else
        <div class="s-actions" style="margin-bottom:12px">
            <a class="s-btn {{ $tab === 'abiertas' ? '' : 'secondary' }}" href="{{ route('suppliers.service-requests.index') }}">En curso</a>
            <a class="s-btn {{ $tab === 'cerradas' ? '' : 'secondary' }}" href="{{ route('suppliers.service-requests.index', ['estado' => 'cerradas']) }}">Cerradas</a>
        </div>
        <div class="s-card">
            @if($rows->isEmpty())
                <p class="s-muted" style="margin:0">{{ $tab === 'abiertas' ? 'No hay consultas en curso.' : 'No hay consultas cerradas.' }}</p>
            @else
                <ul class="s-list">
                    @foreach($rows as $r)
                        <li><a class="s-item" href="{{ route('suppliers.service-requests.show', $r->reference) }}" data-service-request="{{ $r->reference }}">
                            <span><strong>{{ $r->subject }}</strong><br><span class="s-muted">{{ $r->reference }} · {{ $r->buyerLabel() }}</span></span>
                            <span style="text-align:right"><span class="s-tag {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span><br><span class="s-muted">{{ $r->last_message_at?->format('d/m H:i') }}</span></span>
                        </a></li>
                    @endforeach
                </ul>
                {{ $rows->links() }}
            @endif
        </div>
    @endif
</x-supplier-layout>
