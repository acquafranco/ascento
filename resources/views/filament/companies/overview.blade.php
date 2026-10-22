{{--
    Ficha de empresa para el SuperAdmin. Cuatro estados que NO son lo mismo:
    registrada, correo confirmado, cuenta habilitada y suscripción. Todo lo
    que se muestra sale de la base (CompanyHealth).
--}}
@php
    $company = $getRecord();
    $h = \App\Support\CompanyHealth::for($company);
    $pill = fn (string $key) => match ($key) {
        'done', 'active', 'trial', 'canceled_period' => 'is-done',
        'pending', 'awaiting_payment', 'paused' => 'is-pending',
        'overdue', 'trial_ended', 'past_due', 'canceled', 'expired', 'company_disabled' => 'is-overdue',
        default => '',
    };
@endphp
@include('filament.partials.insights-styles')
<div class="asc-stack">
    <section>
        <h2 class="asc-h2">Estado</h2>
        <div class="asc-grid">
            <div class="asc-card">
                <p class="asc-q">Registrada</p>
                <p class="asc-v" style="font-size:1.15rem">{{ $h['registered_at']?->format('d/m/Y H:i') ?? '—' }}</p>
                <p class="asc-c">{{ $h['registered_at']?->diffForHumans() }}</p>
            </div>
            <div class="asc-card">
                <p class="asc-q">Correo del responsable</p>
                <p style="margin:.4rem 0 0"><span class="asc-pill {{ $h['email_verified'] ? 'is-done' : 'is-pending' }}">{{ $h['email_verified'] ? 'Confirmado' : 'Sin confirmar' }}</span></p>
                <p class="asc-c">{{ $h['email_verified'] ? 'El '.$h['email_verified_at']->format('d/m/Y H:i') : 'No abrió el enlace de confirmación' }}</p>
            </div>
            <div class="asc-card">
                <p class="asc-q">Cuenta</p>
                <p style="margin:.4rem 0 0"><span class="asc-pill {{ $pill($h['account']['key']) }}">{{ $h['account']['label'] }}</span></p>
                <p class="asc-c">Si puede ingresar a Ascento</p>
            </div>
            <div class="asc-card">
                <p class="asc-q">Suscripción</p>
                <p style="margin:.4rem 0 0"><span class="asc-pill {{ $pill($h['subscription']['key']) }}">{{ $h['subscription']['label'] }}</span></p>
                <p class="asc-c">
                    Plan {{ $company->plan()->shortName() }}
                    @if($company->trial_ends_at) · prueba hasta {{ $company->trial_ends_at->format('d/m/Y') }} @endif
                </p>
            </div>
        </div>
    </section>

    <section>
        <h2 class="asc-h2">Responsable y contacto</h2>
        <div class="asc-card">
            <div class="asc-row"><span class="asc-muted">Responsable</span><strong>{{ $h['owner']?->name ?? '—' }}</strong></div>
            <div class="asc-row"><span class="asc-muted">Correo de registro</span><span>{{ $h['owner']?->email ?? '—' }}</span></div>
            <div class="asc-row"><span class="asc-muted">Teléfono de la empresa</span><span>{{ $company->phone ?: '—' }}</span></div>
            <div class="asc-row"><span class="asc-muted">{{ $company->taxIdLabel() }}</span><span>{{ $company->cuit ?: '—' }}</span></div>
            <div class="asc-row"><span class="asc-muted">País</span><span>{{ \App\Support\Countries::name($company->country) }}</span></div>
            <div class="asc-row"><span class="asc-muted">Ubicación</span><span>{{ collect([$company->city, $company->province])->filter()->implode(', ') ?: '—' }}</span></div>
        </div>
    </section>

    <section>
        <h2 class="asc-h2">Uso <span class="asc-muted asc-small" style="font-weight:400">· datos reales de la base</span></h2>
        <div class="asc-grid">
            @foreach($h['usage'] as $label => $value)
                <div class="asc-card">
                    <p class="asc-q">{{ $label }}</p>
                    <p class="asc-v">{{ number_format($value, 0, ',', '.') }}</p>
                </div>
            @endforeach
        </div>
        <p class="asc-c" style="margin-top:.6rem">
            Última actividad registrada (reporte, remito u orden): {{ $h['last_activity'] ? $h['last_activity']->format('d/m/Y H:i').' ('.$h['last_activity']->diffForHumans().')' : 'todavía ninguna' }}.
        </p>
    </section>
</div>
