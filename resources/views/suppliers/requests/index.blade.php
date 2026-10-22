<x-supplier-layout title="Pedidos">
    <h1 class="s-h1">Pedidos de cotización</h1>
    <p class="s-sub">Lo que te piden las empresas de mantenimiento. Una cotización no es una compra: el comprador decide si la acepta.</p>
    @include('suppliers.partials.status-notice')

    <nav class="s-actions" aria-label="Filtrar por estado" style="margin-bottom:12px">
        <a class="s-tag {{ ! $status ? 'info' : '' }}" href="{{ route('suppliers.requests.index') }}" @if(! $status) aria-current="page" @endif>Todos</a>
        @foreach(\App\Models\Marketplace\MarketplaceRequest::STATUSES as $key => $label)
            <a class="s-tag {{ $status === $key ? 'info' : '' }}" href="{{ route('suppliers.requests.index', ['estado' => $key]) }}" @if($status === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if($rows->isEmpty())
        <div class="s-card s-muted">No hay pedidos{{ $status ? ' en este estado' : '' }}.</div>
    @else
        <div class="s-card s-scroll">
            <table class="s-table s-stack-sm">
                <thead><tr><th>Pedido</th><th>Empresa</th><th>Productos</th><th>Fecha</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr data-supplier-request="{{ $r->reference }}">
                            <td><a class="s-link" href="{{ route('suppliers.requests.show', $r->reference) }}">{{ $r->reference }}</a></td>
                            <td>{{ $r->company?->name }}</td>
                            <td>{{ $r->items_count }}</td>
                            <td>{{ $r->created_at->format('d/m/Y') }}</td>
                            <td><span class="s-tag {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">{{ $rows->links() }}</div>
    @endif
</x-supplier-layout>
