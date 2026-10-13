<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    <div class="asc-card" style="overflow-x:auto" data-plan-features>
        <table style="width:100%; border-collapse:collapse; font-size:.875rem; min-width:560px">
            <thead>
                <tr>
                    <th style="text-align:left; padding:.5rem">Plan</th>
                    @foreach($plans as $plan)
                        <th style="text-align:center; padding:.5rem" data-plan="{{ $plan->slug }}">
                            {{ $plan->shortName() }}<br>
                            <span class="asc-muted asc-small" style="font-weight:400">{{ $plan->formattedPrice() }}/mes</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="{{ $plans->count() + 1 }}" class="asc-q" style="padding:.75rem .5rem .25rem; font-weight:600">Límites <span class="asc-muted" style="font-weight:400">· solo lectura</span></td></tr>
                @foreach($limits as $limit)
                    <tr style="border-top:1px solid rgba(148,163,184,.2)">
                        <td style="padding:.4rem .5rem">{{ $limit === \App\Enums\PlanLimit::ReportsPerMonth ? 'Reportes por mes' : ucfirst($limit->plural()) }}</td>
                        @foreach($plans as $plan)
                            <td style="text-align:center; padding:.4rem .5rem">{{ $plan->limit($limit) ?? 'Sin límite' }}</td>
                        @endforeach
                    </tr>
                @endforeach

                <tr><td colspan="{{ $plans->count() + 1 }}" class="asc-q" style="padding:.75rem .5rem .25rem; font-weight:600">Funciones</td></tr>
                @foreach($toggleable as $feature)
                    <tr style="border-top:1px solid rgba(148,163,184,.2)">
                        <td style="padding:.4rem .5rem">{{ $feature->label() }}</td>
                        @foreach($plans as $plan)
                            <td style="text-align:center; padding:.4rem .5rem">
                                <input type="checkbox" wire:model.live="features.{{ $plan->slug }}.{{ $feature->value }}" data-feature="{{ $plan->slug }}.{{ $feature->value }}" aria-label="{{ $feature->label() }} en {{ $plan->shortName() }}">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="asc-small asc-muted" style="margin-top:.75rem">
            Siempre incluidas (no se pueden quitar): {{ collect($structural)->map->label()->implode(', ') }}.
        </p>
        <div style="margin-top:.75rem">{{ $this->saveAction }}</div>
    </div>
</x-filament-panels::page>
