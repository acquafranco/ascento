{{--
    Resumen antes de confirmar una asignación o un reemplazo de guardia
    (OnCallPlanner). No guarda nada: al confirmar, el servidor vuelve a validar.
    $plan: errors, warnings, headline, details.
--}}
<div class="ocs" data-on-call-summary>
    <style>
        .ocs { display: grid; gap: .75rem; font-size: .9rem; line-height: 1.45; }
        .ocs-head { font-size: 1.05rem; font-weight: 700; overflow-wrap: anywhere; }
        .ocs-list { margin: 0; padding-left: 1.1rem; display: grid; gap: .35rem; }
        .ocs-box { border-radius: .75rem; padding: .7rem .9rem; overflow-wrap: anywhere; }
        .ocs-bad { background: rgba(220,38,38,.08); border: 1px solid rgba(220,38,38,.3); color: #991b1b; }
        .ocs-warn { background: rgba(217,119,6,.08); border: 1px solid rgba(217,119,6,.3); color: #92400e; }
        .dark .ocs-bad { color: #fca5a5; }
        .dark .ocs-warn { color: #fcd34d; }
    </style>

    @if(! empty($plan['errors']))
        <div class="ocs-box ocs-bad" role="alert" data-on-call-summary-errors>
            <p style="margin:0 0 .35rem; font-weight:700">Todavía no se puede guardar. Volvé al paso anterior y corregí:</p>
            <ul class="ocs-list">
                @foreach($plan['errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <p class="ocs-head" data-on-call-summary-headline>{{ $plan['headline'] }}</p>

        @if(! empty($plan['warnings']))
            <div class="ocs-box ocs-warn" data-on-call-summary-warnings>
                <p style="margin:0 0 .35rem; font-weight:700">Antes de confirmar, revisá:</p>
                <ul class="ocs-list">
                    @foreach($plan['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <ul class="ocs-list">
            @foreach($plan['details'] as $detail)
                <li>{{ $detail }}</li>
            @endforeach
        </ul>
    @endif
</div>
