{{-- Avisos de un reporte (panel): el intento registrado y, solo si pasó, que la persona lo abrió. --}}
@php $rows = $getRecord()->deliveries()->with(['user', 'notification'])->get(); @endphp
<ul style="list-style:none; margin:0; padding:0; display:grid; gap:.4rem" data-claim-deliveries>
    @foreach($rows as $row)
        <li style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:.3rem .75rem; font-size:.9rem; border-bottom:1px solid rgba(148,163,184,.25); padding:.35rem 0">
            <span><strong>{{ $row->user?->name ?? 'Usuario eliminado' }}</strong> <span style="opacity:.7">· {{ \App\Models\ClientClaimDelivery::AUDIENCES[$row->audience] ?? $row->audience }}</span></span>
            <span style="opacity:.85">{{ $row->stateLabel() }}</span>
        </li>
    @endforeach
</ul>
