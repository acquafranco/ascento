{{-- Estado de la cuenta del proveedor que decide el SuperAdmin (moderación). El plan (gratuito o Premium) no bloquea a nadie. --}}
@if($supplier->status === \App\Models\Marketplace\Supplier::PENDING)
    <div class="s-notice warn" data-supplier-status="pending"><strong>Estamos revisando tu cuenta.</strong> Mientras tanto podés completar tu perfil y armar tu catálogo; las empresas lo van a ver cuando la aprobemos.</div>
@elseif($supplier->status === \App\Models\Marketplace\Supplier::REJECTED)
    <div class="s-notice bad" data-supplier-status="rejected"><strong>No pudimos aprobar tu cuenta.</strong> @if($supplier->status_reason) Motivo: {{ $supplier->status_reason }}. @endif Si querés revisarlo, escribinos a {{ config('legal.email') }}.</div>
@elseif($supplier->status === \App\Models\Marketplace\Supplier::SUSPENDED)
    <div class="s-notice bad" data-supplier-status="suspended"><strong>Tu cuenta está suspendida por moderación.</strong> Tu catálogo no se ve y no podés cotizar; tus datos se conservan. @if($supplier->status_reason) Motivo: {{ $supplier->status_reason }}. @endif Pagar no levanta la suspensión: escribinos a {{ config('legal.email') }}.</div>
@endif
