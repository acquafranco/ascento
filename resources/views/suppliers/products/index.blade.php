<x-supplier-layout title="Mi catálogo">
    <div class="s-row" style="margin-bottom:14px">
        <div><h1 class="s-h1">Mi catálogo</h1><p class="s-sub" style="margin:0">Lo publicado lo ven las empresas de mantenimiento{{ $supplier->isOperating() ? '' : ' cuando tu cuenta esté habilitada' }}.</p></div>
        @unless(in_array($supplier->status, ['rejected', 'suspended'], true))<a class="s-btn" href="{{ route('suppliers.products.create') }}">+ Nuevo producto</a>@endunless
    </div>
    @include('suppliers.partials.status-notice')

    <nav class="s-actions" aria-label="Filtrar por estado" style="margin-bottom:12px">
        <a class="s-tag {{ ! $status ? 'info' : '' }}" href="{{ route('suppliers.products.index') }}" @if(! $status) aria-current="page" @endif>Todos</a>
        @foreach(\App\Models\Marketplace\SupplierProduct::STATUSES as $key => $label)
            <a class="s-tag {{ $status === $key ? 'info' : '' }}" href="{{ route('suppliers.products.index', ['estado' => $key]) }}" @if($status === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if($products->isEmpty())
        <div class="s-card s-muted">Todavía no hay productos{{ $status ? ' en este estado' : '' }}.</div>
    @else
        <div class="s-card s-scroll">
            <table class="s-table s-stack-sm">
                <thead><tr><th>Producto</th><th>Rubro</th><th>Precio</th><th>Disponibilidad</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach($products as $p)
                        <tr data-supplier-product="{{ $p->id }}">
                            <td><a class="s-link" href="{{ route('suppliers.products.edit', $p->id) }}">{{ $p->name }}</a><br><span class="s-muted">{{ collect([$p->brand, $p->model, $p->part_number ? 'Cód. '.$p->part_number : null])->filter()->implode(' · ') }} · {{ $p->photos_count }} {{ $p->photos_count === 1 ? 'foto' : 'fotos' }}</span></td>
                            <td>{{ $p->categoryLabel() }}</td>
                            <td>{{ $p->priceLabel() }}</td>
                            <td>{{ $p->availabilityLabel() }}</td>
                            <td>
                                <span class="s-tag {{ $p->status === 'published' ? 'ok' : ($p->status === 'retired' ? 'bad' : '') }}">{{ $p->statusLabel() }}</span>
                                @if($p->hidden_by_admin)<br><span class="s-tag bad" style="margin-top:4px">Oculto por moderación</span>@endif
                                @if($p->isFeatured())<br><span class="s-tag warn" style="margin-top:4px">Destacado</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">{{ $products->links() }}</div>
    @endif
</x-supplier-layout>
