{{-- Estado de la cuenta del proveedor: moderación (la decide el SuperAdmin) y estado comercial (prueba, suscripción). --}}
@php
    use App\Models\Marketplace\Supplier;
    use App\Support\Marketplace\SupplierStanding;
    $standing = $supplier->standing();
@endphp
@if($supplier->status === Supplier::PENDING)
    <div class="s-notice warn" data-supplier-status="pending"><strong>Estamos revisando tu cuenta.</strong> Mientras tanto podés completar tu perfil y armar tu catálogo; las empresas lo van a ver cuando la aprobemos. Tu prueba gratuita empieza con la aprobación.</div>
@elseif($supplier->status === Supplier::REJECTED)
    <div class="s-notice bad" data-supplier-status="rejected"><strong>No pudimos aprobar tu cuenta.</strong> @if($supplier->status_reason) Motivo: {{ $supplier->status_reason }}. @endif Si querés revisarlo, escribinos a {{ config('legal.email') }}.</div>
@elseif($supplier->status === Supplier::SUSPENDED)
    <div class="s-notice bad" data-supplier-status="suspended"><strong>Tu cuenta está suspendida por moderación.</strong> Tu catálogo no se ve y no podés cotizar; tus datos se conservan. @if($supplier->status_reason) Motivo: {{ $supplier->status_reason }}. @endif Pagar una suscripción no levanta la suspensión: escribinos a {{ config('legal.email') }}.</div>
@elseif($standing === SupplierStanding::TRIAL)
    <div class="s-notice ok" data-supplier-standing="trial"><strong>Prueba gratuita hasta el {{ $supplier->trial_ends_at->format('d/m/Y') }}.</strong> Tus productos publicados se ven y recibís pedidos. <a class="s-link" href="{{ route('suppliers.billing') }}">Ver suscripción</a></div>
@elseif(! in_array($standing, SupplierStanding::OPERATING, true))
    <div class="s-notice bad" data-supplier-standing="{{ $standing }}"><strong>{{ SupplierStanding::label($standing) }}.</strong> Tu catálogo no se ve y no recibís pedidos nuevos. No borramos nada: tus productos, fotos y pedidos se conservan. <a class="s-link" href="{{ route('suppliers.billing') }}">Ver cómo reactivarla</a></div>
@endif
