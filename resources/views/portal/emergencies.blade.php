<x-portal-layout title="Pedidos de ayuda">
    <h1 class="p-h1">Pedidos de ayuda</h1>
    <p class="p-sub">Pedidos por posible atrapamiento hechos desde el QR de tus edificios. Son avisos sin confirmar: la empresa de mantenimiento los verifica y los cierra.</p>

    @if($requests->isEmpty())
        <div class="p-empty" data-portal-emergencies-empty>No hay pedidos de ayuda registrados en tus edificios.</div>
    @else
        <ul class="p-list" data-portal-emergencies>
            @foreach($requests as $r)
                <li class="p-row">
                    <span class="p-row-main" style="display:block">
                        <span class="p-row-title">{{ $r->placeLabel() }}</span>
                        <span class="p-row-text">{{ $r->created_at->format('d/m/Y H:i') }} · Código {{ $r->reference }}@if($r->repeat_count) · {{ $r->repeat_count }} {{ $r->repeat_count === 1 ? 'pedido repetido' : 'pedidos repetidos' }}@endif</span>
                    </span>
                    <span class="p-row-side"><span class="p-tag">{{ $r->statusLabel() }}</span></span>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:12px">{{ $requests->links() }}</div>
    @endif
</x-portal-layout>
