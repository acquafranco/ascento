<x-portal-layout title="Reclamos">
    @include('portal.claims._styles')
    <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; align-items:center">
        <div>
            <h1 class="p-h1">Reclamos</h1>
            <p class="p-sub" style="margin-bottom:0">Contale a {{ $portalCompany?->name }} si un ascensor de tus edificios tiene un problema, y seguí cómo avanza.</p>
        </div>
        <a href="{{ route('portal.claims.create') }}" class="p-btn" data-claim-new>Nuevo reclamo</a>
    </div>
    <div style="height:14px"></div>
    @include('portal.claims._safety')

    @if($claims->isEmpty())
        <div class="p-empty" data-claims-empty>Todavía no presentaste reclamos.</div>
    @else
        <ul class="p-list" data-claims>
            @foreach($claims as $c)
                <li>
                    <a class="p-row" href="{{ route('portal.claims.show', $c->id) }}" data-claim="{{ $c->id }}">
                        <span class="p-row-main" style="display:block">
                            <span class="p-row-title">{{ $c->reference() }} · {{ $c->categoryLabel() }}</span>
                            <span class="p-row-text">{{ $c->placeLabel() }} · {{ $c->created_at->format('d/m/Y H:i') }}</span>
                        </span>
                        <span class="p-row-side"><span class="p-tag {{ in_array($c->status, ['resolved', 'closed'], true) ? 'ok' : ($c->status === 'new' ? 'info' : 'warn') }}">{{ $c->statusLabel() }}</span></span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:12px">{{ $claims->links() }}</div>
    @endif
</x-portal-layout>
