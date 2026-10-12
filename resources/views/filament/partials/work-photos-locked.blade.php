{{--
    Fotos de los trabajos y reportes de inspección sin el plan Empresa: no se
    muestran (se conservan) y se explica cómo habilitarlas.
--}}
<div class="text-sm text-gray-600 dark:text-gray-300" data-plan-locked="work_photos">
    Las fotos de antes y después y los reportes de inspección están disponibles en el plan <strong>Empresa</strong>.
    Si ya se habían cargado, se conservan y vuelven a verse al pasar a ese plan.
    @if(auth()->user()?->isAdmin() && ! auth()->user()?->isSuperAdmin())
        <a href="{{ \App\Support\Plans\PlanUpsell::url(feature: \App\Enums\PlanFeature::WorkPhotos) }}" class="font-semibold text-primary-600 underline">Ver planes</a>
    @endif
</div>
