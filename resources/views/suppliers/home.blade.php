<x-supplier-layout title="Inicio">
    <h1 class="s-h1">Hola, {{ auth()->user()->name }}</h1>
    <p class="s-sub">Tu catálogo y los pedidos de cotización de empresas de mantenimiento.</p>

    @include('suppliers.partials.status-notice')
    @include('suppliers.partials.usage', ['usage' => $usage])

    <div class="s-grid">
        <a class="s-card s-stat" href="{{ route('suppliers.requests.index', ['estado' => 'sent']) }}" style="text-decoration:none"><b>{{ $open }}</b><span class="s-muted">Pedidos para cotizar</span></a>
        <a class="s-card s-stat" href="{{ route('suppliers.requests.index', ['estado' => 'accepted']) }}" style="text-decoration:none"><b>{{ $accepted }}</b><span class="s-muted">Cotizaciones aceptadas para coordinar</span></a>
        <a class="s-card s-stat" href="{{ route('suppliers.products.index', ['estado' => 'published']) }}" style="text-decoration:none"><b>{{ $products['published'] ?? 0 }}</b><span class="s-muted">Productos publicados</span></a>
        <div class="s-card s-stat"><b>{{ $completed }}</b><span class="s-muted">Operaciones completadas (confirmadas por compradores)</span></div>
    </div>
    @if($hidden > 0)
        <div class="s-notice bad" style="margin-top:14px">{{ $hidden }} {{ $hidden === 1 ? 'producto está oculto' : 'productos están ocultos' }} por moderación. Abrilos en <a class="s-link" href="{{ route('suppliers.products.index') }}">Mi catálogo</a> para ver el motivo.</div>
    @endif

    <section class="s-card" style="margin-top:18px">
        <div class="s-row"><h2 class="s-h2" style="margin:0">Avisos</h2><a class="s-link" href="{{ route('suppliers.notifications.index') }}">Ver todos</a></div>
        @forelse($notifications as $n)
            <div style="border-top:1px solid #F0EEE7; padding:10px 0; display:flex; justify-content:space-between; gap:10px">
                <span><strong style="font-size:14px">{{ $n->data['title'] ?? '' }}</strong><br><span class="s-muted">{{ $n->data['body'] ?? '' }}</span></span>
                <span style="text-align:right; flex-shrink:0">
                    <span class="s-muted">{{ $n->created_at->format('d/m H:i') }}</span>
                    <br><a class="s-link" href="{{ route('suppliers.notifications.open', $n->id) }}">{{ $n->read_at ? 'Ver' : 'Ver (nuevo)' }}</a>
                </span>
            </div>
        @empty
            <p class="s-muted">Todavía no tenés avisos. Cuando una empresa te pida una cotización, aparece acá y te llega por correo.</p>
        @endforelse
    </section>

    <section class="s-card">
        <h2 class="s-h2">Cómo funciona</h2>
        <ol class="s-muted" style="margin:0; padding-left:18px; font-size:14px; line-height:1.7">
            <li>Cargás tus productos con fotos, precio (o «a cotizar») y la compatibilidad que declarás.</li>
            <li>Las empresas de mantenimiento te piden cotizaciones; vos respondés con precios y validez.</li>
            <li>Si aceptan, coordinan directamente el pago, el envío y la entrega. Ascento no cobra comisiones ni procesa esos pagos.</li>
            <li>Cuando el comprador confirma que recibió el pedido, suma a tus operaciones completadas.</li>
        </ol>
        <p class="s-muted" style="margin-top:10px">Tu plan (gratuito o Premium) está en <a class="s-link" href="{{ route('suppliers.billing') }}">Plan</a>; la visibilidad adicional para un producto, en <a class="s-link" href="{{ route('suppliers.featured') }}">Destacados</a>.</p>
    </section>
</x-supplier-layout>
