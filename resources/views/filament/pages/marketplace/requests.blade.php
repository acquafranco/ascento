@php
    use App\Filament\Pages\Marketplace\PartsCatalog;
    use App\Filament\Pages\Marketplace\PartsRequests;
    use App\Models\Marketplace\MarketplaceRequest;
    use App\Support\Marketplace\Catalog;
@endphp
<x-filament-panels::page>
    @include('filament.pages.marketplace.styles')

    <div class="mk-stack">
        @error('estado')<div class="mk-note" role="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b">{{ $message }}</div>@enderror
        @error('items')<div class="mk-note" role="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b">{{ $message }}</div>@enderror

        @if($mode === 'detail')
            <a class="mk-link" href="{{ PartsRequests::getUrl() }}" wire:navigate>← Mis pedidos</a>
            <div style="display:flex; justify-content:space-between; gap:.75rem; flex-wrap:wrap; align-items:center">
                <h2 style="font-size:1.25rem; font-weight:700; margin:0">Pedido {{ $row->reference }} · {{ $row->supplier->name }}</h2>
                <span class="mk-pill {{ $row->statusTone() }}" data-mk-request-status="{{ $row->status }}">{{ $row->statusLabel() }}</span>
            </div>
            @if($row->quoteExpired())<div class="mk-note">La cotización venció el {{ $row->quoteExpiresAt()->format('d/m/Y') }}. Pedile al proveedor que la actualice.</div>@endif
            @if($row->status === MarketplaceRequest::ACCEPTED)
                <div class="mk-note">Aceptaste la cotización. <strong>Coordiná el pago y la entrega directamente con el proveedor</strong> (Ascento no cobra). Cuando recibas los productos, marcá el pedido como completado.</div>
            @endif

            <section class="mk-card mk-scroll">
                <table class="mk-table">
                    <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio publicado</th><th>Cotizado (unitario)</th><th>Subtotal</th></tr></thead>
                    <tbody>
                        @foreach($row->items as $item)
                            <tr>
                                <td><strong>{{ $item->name }}</strong><br><span class="mk-muted">{{ collect([$item->brand, $item->part_number ? 'Cód. '.$item->part_number : null])->filter()->implode(' · ') }}</span></td>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ $item->listed_price !== null ? $row->money((float) $item->listed_price) : 'A cotizar' }}</td>
                                <td>{{ $item->quoted_unit_price !== null ? $row->money((float) $item->quoted_unit_price) : ($row->quoted_at ? 'No cotizado' : '—') }}</td>
                                <td>{{ $item->quoted_unit_price !== null ? $row->money((float) $item->quoted_unit_price * $item->quantity) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($row->quoted_total !== null)
                    <p style="margin:.75rem 0 0; font-size:1rem"><strong>Total cotizado: {{ $row->money((float) $row->quoted_total) }}</strong>
                        @if($row->quoteExpiresAt())<span class="mk-muted"> · válida hasta el {{ $row->quoteExpiresAt()->format('d/m/Y') }}</span>@endif</p>
                    <p class="mk-muted" style="margin:.2rem 0 0">Es una cotización del proveedor, no un cobro. No incluye pagos hechos en Ascento.</p>
                @endif
                @if($row->supplier_note)<p style="white-space:pre-line; font-size:.875rem; margin:.6rem 0 0"><strong>Nota del proveedor:</strong> {{ $row->supplier_note }}</p>@endif
            </section>

            @if($canOrder && ($row->canMove('buyer', 'accepted') || $row->canMove('buyer', 'completed') || $row->canMove('buyer', 'cancelled')))
                <section class="mk-card">
                    <label for="mk-note" style="font-size:.8rem; font-weight:600">Nota para el proveedor (opcional)</label>
                    <textarea id="mk-note" wire:model="note" maxlength="2000" rows="2" class="mk-input"></textarea>
                    <div class="mk-actions">
                        @if($row->canMove('buyer', 'accepted') && ! $row->quoteExpired())<button type="button" class="mk-btn" wire:click="move('accepted', {{ $row->quoteVersion() }})" wire:confirm="¿Aceptar la cotización de {{ $row->supplier->name }} por {{ $row->money((float) $row->quoted_total) }}? Después coordinás el pago y la entrega con el proveedor." data-mk-accept>Aceptar cotización</button>@endif
                        @if($row->canMove('buyer', 'completed'))<button type="button" class="mk-btn" wire:click="move('completed')" wire:confirm="¿Confirmás que recibiste este pedido?" data-mk-complete>Recibí el pedido</button>@endif
                        @if($row->canMove('buyer', 'rejected'))<button type="button" class="mk-btn secondary" wire:click="move('rejected')" wire:confirm="¿Rechazar esta cotización?">Rechazar cotización</button>@endif
                        @if($row->canMove('buyer', 'cancelled'))<button type="button" class="mk-btn danger" wire:click="move('cancelled')" wire:confirm="¿Cancelar el pedido {{ $row->reference }}?">Cancelar pedido</button>@endif
                    </div>
                </section>
            @endif

            <div class="mk-two">
                <section class="mk-card">
                    <p class="mk-h2">Lo que compartiste con el proveedor</p>
                    <dl class="mk-dl">
                        <dt>Contacto</dt><dd>{{ $row->contact_name }}</dd>
                        <dt>Correo</dt><dd>{{ $row->contact_email }}</dd>
                        @if($row->contact_phone)<dt>Teléfono</dt><dd>{{ $row->contact_phone }}</dd>@endif
                        @if($row->delivery_area)<dt>Entrega</dt><dd>{{ $row->delivery_area }}</dd>@endif
                    </dl>
                    @if($row->notes)<p style="white-space:pre-line; font-size:.85rem; margin:.5rem 0 0">{{ $row->notes }}</p>@endif
                </section>
                <section class="mk-card">
                    <p class="mk-h2">Historial</p>
                    <ol style="margin:0; padding-left:1.1rem; font-size:.85rem; line-height:1.6">
                        @foreach($row->events as $e)
                            <li><strong>{{ MarketplaceRequest::STATUSES[$e->to] ?? $e->to }}</strong> <span class="mk-muted">· {{ $e->actorLabel() }} · {{ $e->created_at->format('d/m/Y H:i') }}</span>@if($e->note)<br><span class="mk-muted" style="white-space:pre-line">{{ $e->note }}</span>@endif</li>
                        @endforeach
                    </ol>
                </section>
            </div>

            @include('filament.pages.marketplace.supplier-card', ['supplier' => $row->supplier, 'operations' => $operations])
            @if($canOrder)<div><button type="button" class="mk-btn secondary" wire:click="repeat" data-mk-repeat>Repetir este pedido</button></div>@endif

        @else
            {{-- Pedido en armado: uno por proveedor --}}
            <section class="mk-stack" aria-labelledby="mk-cart-title">
                <h2 id="mk-cart-title" class="mk-h2" style="margin:0">Pedido en armado</h2>
                @if($groups->isEmpty())
                    <div class="mk-card mk-muted">No sumaste productos todavía. <a class="mk-link" href="{{ PartsCatalog::getUrl() }}">Ir al catálogo de repuestos</a></div>
                @else
                    @if($canOrder)
                        <section class="mk-card">
                            <p class="mk-h2">Tus datos para el proveedor</p>
                            <p class="mk-muted" style="margin:-.3rem 0 .6rem">Es lo único que ve el proveedor de tu empresa (además del nombre). Nada de tus clientes, edificios ni técnicos.</p>
                            <div class="mk-filters">
                                <div><label for="mk-cn">Nombre</label><input id="mk-cn" class="mk-input" wire:model="contact.contact_name" maxlength="120">@error('contact.contact_name')<p class="mk-err">{{ $message }}</p>@enderror</div>
                                <div><label for="mk-ce">Correo</label><input id="mk-ce" type="email" class="mk-input" wire:model="contact.contact_email">@error('contact.contact_email')<p class="mk-err">{{ $message }}</p>@enderror</div>
                                <div><label for="mk-cp">Teléfono</label><input id="mk-cp" type="tel" class="mk-input" wire:model="contact.contact_phone">@error('contact.contact_phone')<p class="mk-err">{{ $message }}</p>@enderror</div>
                                <div><label for="mk-cd">Zona de entrega</label><input id="mk-cd" class="mk-input" wire:model="contact.delivery_area" maxlength="160"></div>
                                <div style="grid-column:1 / -1"><label for="mk-cnotes">Comentarios para el proveedor</label><textarea id="mk-cnotes" class="mk-input" rows="2" wire:model="contact.notes" maxlength="2000" placeholder="Plazo que necesitás, equipo al que va, forma de pago preferida…"></textarea></div>
                            </div>
                        </section>
                    @endif
                    @foreach($groups as $supplierId => $lines)
                        @php $supplier = $lines->first()['product']->supplier; @endphp
                        <section class="mk-card" wire:key="mk-g-{{ $supplierId }}" data-mk-cart-group="{{ $supplierId }}">
                            <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap; align-items:center">
                                <p class="mk-h2" style="margin:0">{{ $supplier->name }}</p>
                                <a class="mk-link" style="font-size:.8rem" href="{{ PartsCatalog::getUrl(['proveedor' => $supplierId]) }}">Ver proveedor</a>
                            </div>
                            <div class="mk-scroll"><table class="mk-table" style="margin-top:.4rem">
                                <thead><tr><th>Producto</th><th>Precio publicado</th><th>Cantidad</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($lines as $r)
                                        <tr wire:key="mk-ci-{{ $r['product']->id }}">
                                            <td><a class="mk-link" href="{{ PartsCatalog::getUrl(['producto' => $r['product']->id]) }}">{{ $r['product']->name }}</a><br><span class="mk-muted">{{ $r['product']->availabilityLabel() }}</span></td>
                                            <td>{{ $r['product']->priceLabel() }}</td>
                                            <td>@if($canOrder)<label class="sr-only" for="mk-q-{{ $r['product']->id }}">Cantidad</label><input id="mk-q-{{ $r['product']->id }}" type="number" min="1" max="{{ Catalog::MAX_QUANTITY }}" value="{{ $r['quantity'] }}" class="mk-input" style="width:5.5rem" wire:change="setQuantity({{ $r['product']->id }}, $event.target.value)">@else {{ $r['quantity'] }} @endif</td>
                                            <td>@if($canOrder)<button type="button" class="mk-btn danger" wire:click="removeItem({{ $r['product']->id }})" aria-label="Quitar {{ $r['product']->name }}">Quitar</button>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table></div>
                            @if($canOrder)
                                <div class="mk-actions"><button type="button" class="mk-btn" wire:click="send({{ $supplierId }})" wire:loading.attr="disabled" wire:target="send" data-mk-send="{{ $supplierId }}">Pedir cotización a {{ $supplier->name }}</button></div>
                            @else
                                <p class="mk-muted">Para enviar pedidos necesitás permiso para gestionar «Stock».</p>
                            @endif
                        </section>
                    @endforeach
                @endif
            </section>

            <section class="mk-stack" aria-labelledby="mk-list-title">
                <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap; align-items:center">
                    <h2 id="mk-list-title" class="mk-h2" style="margin:0">Mis pedidos</h2>
                    <div class="mk-actions" style="padding:0; margin:0">
                        <button type="button" class="mk-btn {{ $tab === 'abiertos' ? 'dark' : 'secondary' }}" wire:click="$set('estado', 'abiertos')" aria-pressed="{{ $tab === 'abiertos' ? 'true' : 'false' }}">En curso</button>
                        <button type="button" class="mk-btn {{ $tab === 'historial' ? 'dark' : 'secondary' }}" wire:click="$set('estado', 'historial')" aria-pressed="{{ $tab === 'historial' ? 'true' : 'false' }}">Historial</button>
                    </div>
                </div>
                @if($rows->isEmpty())
                    <div class="mk-card mk-muted">{{ $tab === 'abiertos' ? 'No tenés pedidos en curso.' : 'Todavía no hay pedidos terminados.' }}</div>
                @else
                    <div class="mk-card mk-scroll">
                        <table class="mk-table">
                            <thead><tr><th>Pedido</th><th>Proveedor</th><th>Productos</th><th>Fecha</th><th>Cotizado</th><th>Estado</th></tr></thead>
                            <tbody>
                                @foreach($rows as $r)
                                    <tr data-mk-request="{{ $r->id }}">
                                        <td><a class="mk-link" href="{{ PartsRequests::getUrl(['pedido' => $r->id]) }}">{{ $r->reference }}</a></td>
                                        <td>{{ $r->supplier?->name }}</td>
                                        <td>{{ $r->items_count }}</td>
                                        <td>{{ $r->created_at->format('d/m/Y') }}</td>
                                        <td>{{ $r->quoted_total !== null ? $r->money((float) $r->quoted_total) : '—' }}</td>
                                        <td><span class="mk-pill {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
