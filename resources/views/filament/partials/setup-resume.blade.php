{{-- Configuración inicial pendiente u omitida (dueño de una empresa nueva): retomar el asistente. --}}
@php
    $user = auth()->user();
    $setup = \App\Services\Onboarding\CompanySetup::class;
    $show = $setup::appliesTo($user) && in_array($user->company->setup_status, [$setup::PENDING, $setup::SKIPPED], true);
    $progress = $show ? app($setup)->progress($user->company) : null;
@endphp
@if($show)
    <a href="{{ $setup::wizardUrl() }}" data-setup-resume-banner
       style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.5rem 1rem; margin-bottom:1rem; padding:.85rem 1.1rem; border-radius:.9rem; border:1px solid rgba(255,102,0,.35); background:rgba(255,102,0,.08); text-decoration:none; color:inherit">
        <span style="min-width:0">
            <strong style="display:block">Terminá de configurar tu empresa</strong>
            <span style="font-size:.875rem; opacity:.75">{{ $progress['steps_done'] }} de {{ count($setup::STEP_TITLES) }} pasos listos. Seguís donde lo dejaste.</span>
        </span>
        <span style="font-weight:700; color:#b84a00; white-space:nowrap">Continuar →</span>
    </a>
@endif
