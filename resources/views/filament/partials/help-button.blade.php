{{-- Abre el panel de Ayuda (App\Livewire\AdminOnboarding). --}}
<button
    type="button"
    x-data
    x-on:click="$dispatch('ascento-help')"
    title="Ayuda"
    aria-label="Ayuda"
    data-ascento-help
    style="display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px;border:1px solid rgba(148,163,184,.45);background:transparent;color:inherit;font-weight:700;font-size:1rem;cursor:pointer;margin-inline-end:.25rem;"
>?</button>
