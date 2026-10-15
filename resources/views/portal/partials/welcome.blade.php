{{--
    Bienvenida del portal: se muestra en el Inicio hasta que la persona toca
    «Entendido» (una sola vez por persona; ver HelpController). Solo explica
    el portal: nada del panel de la empresa.
--}}
@php $user = auth()->user(); @endphp
@if($user && ! $user->hasSeenHelp(\App\Http\Controllers\HelpController::WELCOME_PORTAL))
    <section class="p-card" style="display:block; margin-bottom:1rem" data-help-welcome="portal" aria-labelledby="p-welcome-title">
        <h2 id="p-welcome-title" style="font-size:1rem; font-weight:700; margin:0">Bienvenido al portal 👋</h2>
        <p class="p-muted" style="margin:.35rem 0 0">Acá ves la información que {{ $portalCompany?->name }} comparte de tus edificios: las visitas, quiénes los atienden y los documentos (remitos, reportes y presupuestos). Solo ves los edificios que te habilitaron.</p>
        <p class="p-muted" style="margin:.35rem 0 0">Si tenés una duda, entrá a «Ayuda».</p>
        <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; margin-top:.75rem">
            <form method="POST" action="{{ route('portal.help.welcome') }}">
                @csrf
                <button type="submit" class="p-btn" data-help-welcome-dismiss>Entendido</button>
            </form>
            <a href="{{ route('portal.help') }}" style="font-weight:600">Ver la ayuda</a>
        </div>
    </section>
@endif
