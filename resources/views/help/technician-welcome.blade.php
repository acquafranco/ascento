{{--
    Bienvenida del técnico: se muestra en el Inicio hasta que toca
    «Entendido» (una sola vez por persona; ver HelpController). Breve: cómo
    orientarse y dónde está la ayuda. Funciona sin JavaScript (formulario).
--}}
@php $user = auth()->user(); @endphp
@if($user?->role === 'technician' && ! $user->hasSeenHelp(\App\Http\Controllers\HelpController::WELCOME_TECHNICIAN))
    <section class="t-card mb-4 p-4 sm:p-5" data-help-welcome="technician" aria-labelledby="t-welcome-title">
        <h2 id="t-welcome-title" class="text-base font-bold text-ink">Bienvenido a Ascento 👋</h2>
        <p class="mt-1 text-sm text-ink-700">Así se usa la app:</p>
        <ul class="mt-2 space-y-1 text-sm text-ink-700">
            <li><strong>Visitas:</strong> los mantenimientos e inspecciones del mes de tus edificios.</li>
            <li><strong>Trabajos:</strong> las órdenes que te asigna la oficina. Se toman y se cierran con el remito.</li>
            <li><strong>Reportes:</strong> para avisar una falla con foto.</li>
            @if($user->company_id && \App\Support\Plans\PlanGuard::companyAllows((int) $user->company_id, \App\Enums\PlanFeature::OnCall))
                <li><strong>Guardias:</strong> cuándo te toca cubrir fuera de horario.</li>
            @endif
        </ul>
        <p class="mt-2 text-sm text-ink-700">En <strong>Más → Ayuda</strong> está cómo hacer cada cosa.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('help.technician.welcome', ['company' => $user->company?->slug]) }}">
                @csrf
                <button type="submit" class="t-btn-primary" data-help-welcome-dismiss>Entendido</button>
            </form>
            <a href="{{ route('help.technician', ['company' => $user->company?->slug]) }}" class="text-sm font-semibold text-brand-700">Ver la ayuda</a>
        </div>
    </section>
@endif
