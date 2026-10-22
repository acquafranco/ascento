{{-- Perfil público de un proveedor aprobado (lo ven los compradores). El contacto no se oculta. --}}
<section class="mk-card" data-mk-supplier="{{ $supplier->id }}">
    <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap">
        <div>
            <p class="mk-h2" style="margin:0">{{ $supplier->name }}</p>
            <p class="mk-muted" style="margin:.15rem 0 0">{{ \App\Support\Countries::name($supplier->country) }} · {{ $supplier->coverage }}</p>
        </div>
        <span class="mk-pill ok" style="align-self:flex-start; white-space:nowrap" title="Operaciones que los compradores confirmaron como completadas en Ascento">{{ $operations }} {{ $operations === 1 ? 'operación completada' : 'operaciones completadas' }}</span>
    </div>
    @if($supplier->description)<p style="white-space:pre-line; font-size:.875rem; margin:.6rem 0 0">{{ $supplier->description }}</p>@endif
    <dl class="mk-dl" style="margin-top:.6rem">
        @if($supplier->categories)<dt>Rubros</dt><dd>{{ collect($supplier->categories)->map(fn ($c) => \App\Support\Marketplace\Catalog::category($c))->implode(', ') }}</dd>@endif
        @if($supplier->brands)<dt>Marcas</dt><dd>{{ implode(', ', $supplier->brands) }}</dd>@endif
        @if($supplier->contact_email)<dt>Correo</dt><dd><a class="mk-link" href="mailto:{{ $supplier->contact_email }}">{{ $supplier->contact_email }}</a></dd>@endif
        @if($supplier->contact_phone)<dt>Teléfono</dt><dd><a class="mk-link" href="tel:{{ preg_replace('/[^0-9+]/', '', $supplier->contact_phone) }}">{{ $supplier->contact_phone }}</a></dd>@endif
        @if($supplier->website)<dt>Sitio</dt><dd><a class="mk-link" href="{{ $supplier->website }}" target="_blank" rel="noopener nofollow">{{ preg_replace('#^https?://#', '', $supplier->website) }}</a></dd>@endif
    </dl>
    <p style="margin:.6rem 0 0"><a class="mk-link" href="{{ \App\Filament\Pages\Marketplace\PartsCatalog::getUrl(['proveedor' => $supplier->id]) }}">Ver todos sus productos →</a></p>
</section>
