{{-- Aviso (no bloquea): el alcance ya tiene otra contratación que cubre visitas. ServiceAssignmentForm::overlapping --}}
<div data-overlap-warning role="status" style="padding: .75rem 1rem; border-radius: .75rem; background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.45); font-size: .875rem;">
    <p style="margin: 0 0 .35rem; font-weight: 600;">{{ $overlaps->count() === 1 ? 'Ya hay una contratación activa de este tipo en este alcance' : 'Ya hay '.$overlaps->count().' contrataciones activas de este tipo en este alcance' }}</p>
    <ul style="margin: 0 0 .4rem; padding-left: 1.1rem;">
        @foreach($overlaps as $s)
            <li style="overflow-wrap: anywhere;">«{{ $s->description }}» · {{ $s->scopeLabel() }} · {{ $s->amountLabel() }} · desde {{ $s->start_date?->format('d/m/Y') }}</li>
        @endforeach
    </ul>
    <p style="margin: 0; opacity: .8;">Si es un servicio distinto (por ejemplo, otros equipos o un servicio adicional), podés continuar. Las visitas no se duplican: cada remito se vincula a una sola contratación.</p>
</div>
