<x-filament-panels::page>
    @include('filament.pages.marketplace.styles')
    <div class="mk-stack">
        <section class="mk-card">
            <dl class="mk-dl">
                <dt>Perfil</dt><dd>{{ $row->profile_name }}</dd>
                <dt>Empresa</dt><dd>{{ $row->buyerLabel() }}</dd>
                <dt>Estado</dt><dd>{{ $row->statusLabel() }}@if($row->closed_by) (cerró: {{ ['company' => 'la empresa', 'professional' => 'el profesional'][$row->closed_by] ?? $row->closed_by }})@endif</dd>
                <dt>Creada</dt><dd>{{ $row->created_at->format('d/m/Y H:i') }}</dd>
            </dl>
        </section>
        @if($unlocked)
            <section class="mk-card" data-admin-content>
                <p class="mk-h2">{{ $row->subject }}</p>
                <p style="white-space:pre-line; font-size:.9rem">{{ $row->need }}</p>
                <p class="mk-muted">Contacto: {{ $row->contact_name }} · {{ $row->contact_email }}@if($row->contact_phone) · {{ $row->contact_phone }}@endif</p>
            </section>
            <section class="mk-card">
                <p class="mk-h2">Mensajes</p>
                @forelse($row->messages as $m)
                    <div style="border-top:1px solid rgba(0,0,0,.06); padding:.5rem 0"><strong style="font-size:.85rem">{{ $m->sideLabel() }}</strong> <span class="mk-muted">· {{ $m->created_at->format('d/m/Y H:i') }}</span><br><span style="white-space:pre-line; font-size:.875rem">{{ $m->body }}</span></div>
                @empty
                    <p class="mk-muted">Sin mensajes.</p>
                @endforelse
            </section>
        @else
            <div class="mk-note" data-admin-locked>El contenido es privado entre las partes. Para verlo, usá «Abrir contenido» e indicá el motivo administrativo (queda en la auditoría).</div>
        @endif
    </div>
</x-filament-panels::page>
