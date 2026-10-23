<x-supplier-layout :title="$row->reference">
    <p><a class="s-link" href="{{ route('suppliers.service-requests.index') }}">← Consultas</a></p>
    <div class="s-row"><h1 class="s-h1">{{ $row->subject }}</h1><span class="s-tag {{ $row->statusTone() }}">{{ $row->statusLabel() }}</span></div>
    <p class="s-sub">{{ $row->reference }} · {{ $row->buyerLabel() }} · {{ $row->created_at->format('d/m/Y H:i') }}</p>
    @foreach(['mensaje', 'solicitud'] as $field)@if($errors->has($field))<div class="s-notice bad" role="alert">{{ $errors->first($field) }}</div>@endif @endforeach

    <section class="s-card">
        <div style="white-space:pre-line">{{ $row->need }}</div>
        <table class="s-table" style="margin-top:10px"><tbody>
            <tr><td class="s-muted">Contacto</td><td>{{ $row->contact_name }} · {{ $row->contact_email }}@if($row->contact_phone) · {{ $row->contact_phone }}@endif</td></tr>
            @if($row->modality)<tr><td class="s-muted">Modalidad</td><td>{{ \App\Models\Marketplace\ServiceProfile::MODALITIES[$row->modality] ?? $row->modality }}</td></tr>@endif
            @if($row->location)<tr><td class="s-muted">Lugar</td><td>{{ $row->location }}</td></tr>@endif
        </tbody></table>
        <p class="s-muted" style="margin-bottom:0">Usá estos datos solo para esta consulta. Ascento no participa de la contratación ni de los pagos.</p>
    </section>

    <section class="s-card">
        <h2 class="s-h2">Conversación</h2>
        @forelse($row->messages as $m)
            <div class="s-event {{ $m->side === 'professional' ? 'resolved' : 'note' }}" data-message-side="{{ $m->side }}"><strong style="font-size:14px">{{ $m->side === 'professional' ? 'Vos' : $row->buyerLabel() }}</strong> <span class="s-muted">· {{ $m->created_at->format('d/m/Y H:i') }}</span><br><span style="white-space:pre-line">{{ $m->body }}</span></div>
        @empty
            <p class="s-muted">Todavía no respondiste.</p>
        @endforelse
        @if($row->isOpen())
            <form method="POST" action="{{ route('suppliers.service-requests.reply', $row->reference) }}" class="s-form" style="margin-top:10px">@csrf
                <label class="s-label" for="mensaje">Respuesta</label><textarea id="mensaje" name="mensaje" maxlength="3000" required></textarea>
                <div class="s-actions" style="margin-top:8px"><button class="s-btn" type="submit" data-reply>Responder</button></div>
            </form>
            <form method="POST" action="{{ route('suppliers.service-requests.close', $row->reference) }}" style="margin-top:8px" onsubmit="return confirm('¿Cerrar la consulta? Queda en tu historial.')">@csrf<button class="s-btn secondary" type="submit">Cerrar consulta</button></form>
        @else
            <p class="s-muted">Consulta cerrada el {{ $row->closed_at?->format('d/m/Y') }}.</p>
        @endif
    </section>
</x-supplier-layout>
