{{--
    Fotos de los trabajos y reportes de inspección sin el plan Empresa: no se
    muestran (se conservan) y se explica cómo habilitarlas.
--}}
<div style="font-size:.875rem;line-height:1.5" data-plan-locked="work_photos">
    Las fotos de antes y después y los reportes de inspección están disponibles en el plan <strong>Empresa</strong>.
    Si ya se habían cargado, se conservan y vuelven a verse al pasar a ese plan.
    @if(auth()->user()?->isAdmin() && ! auth()->user()?->isSuperAdmin())
        <a href="{{ \App\Support\Plans\PlanUpsell::url(feature: \App\Enums\PlanFeature::WorkPhotos) }}" style="font-weight:600;text-decoration:underline">Ver planes</a>
    @endif
</div>
