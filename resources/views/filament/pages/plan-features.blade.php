@php use App\Support\Plans\FeatureCatalog; @endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    {{-- Precio y límites: solo lectura. --}}
    <div class="asc-card" style="overflow-x:auto" data-plan-limits>
        <table style="width:100%; border-collapse:collapse; font-size:.875rem; min-width:520px">
            <thead>
                <tr>
                    <th style="text-align:left; padding:.5rem">Plan <span class="asc-muted" style="font-weight:400">· precio y límites, solo lectura</span></th>
                    @foreach($plans as $p)
                        <th style="text-align:center; padding:.5rem" data-plan="{{ $p->slug }}">{{ $p->shortName() }}<br><span class="asc-muted asc-small" style="font-weight:400">{{ $p->formattedPrice() }}/mes</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($limits as $limit)
                    <tr style="border-top:1px solid rgba(148,163,184,.2)">
                        <td style="padding:.4rem .5rem">{{ $limit === \App\Enums\PlanLimit::ReportsPerMonth ? 'Reportes por mes' : ucfirst($limit->plural()) }}</td>
                        @foreach($plans as $p)
                            <td style="text-align:center; padding:.4rem .5rem">{{ $p->limit($limit) ?? 'Sin límite' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Búsqueda y filtros. --}}
    <div class="asc-card" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center" data-plan-filters>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar función…" aria-label="Buscar función" class="fi-input" style="flex:1 1 200px; min-width:0; border-radius:.5rem; padding:.4rem .6rem; border:1px solid rgba(148,163,184,.4); background:transparent">
        <select wire:model.live="module" aria-label="Módulo" style="border-radius:.5rem; padding:.4rem 2rem .4rem .6rem; border:1px solid rgba(148,163,184,.4); background:transparent; max-width:100%">
            <option value="">Todos los módulos</option>
            @foreach($modules as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="category" aria-label="Categoría" style="border-radius:.5rem; padding:.4rem 2rem .4rem .6rem; border:1px solid rgba(148,163,184,.4); background:transparent; max-width:100%">
            <option value="">Todas las categorías</option>
            @foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="plan" aria-label="Plan" style="border-radius:.5rem; padding:.4rem 2rem .4rem .6rem; border:1px solid rgba(148,163,184,.4); background:transparent; max-width:100%">
            <option value="">Todos los planes</option>
            @foreach($plans as $p)
                <option value="{{ $p->slug }}:1">Incluidas en {{ $p->shortName() }}</option>
                <option value="{{ $p->slug }}:0">No incluidas en {{ $p->shortName() }}</option>
            @endforeach
        </select>
    </div>

    {{-- Catálogo. --}}
    <div class="asc-card" style="overflow-x:auto" data-plan-catalog>
        <table style="width:100%; border-collapse:collapse; font-size:.875rem; min-width:640px">
            <thead>
                <tr>
                    <th style="text-align:left; padding:.5rem">Función</th>
                    <th style="text-align:left; padding:.5rem">Categoría</th>
                    @foreach($plans as $p)<th style="text-align:center; padding:.5rem">{{ $p->shortName() }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $moduleKey => $entries)
                    <tr><td colspan="{{ $plans->count() + 2 }}" class="asc-q" style="padding:.9rem .5rem .3rem; font-weight:600" data-module="{{ $moduleKey }}">{{ $modules[$moduleKey] }}</td></tr>
                    @foreach($entries as $e)
                        <tr style="border-top:1px solid rgba(148,163,184,.2)" data-entry="{{ $e['key'] }}">
                            <td style="padding:.45rem .5rem; vertical-align:top">
                                <strong>{{ $e['name'] }}</strong>
                                <br><span class="asc-muted asc-small">{{ $e['description'] }}</span>
                                <br><span class="asc-muted asc-small" data-origin>Origen: {{ $e['origin'] }}</span>
                            </td>
                            <td style="padding:.45rem .5rem; vertical-align:top; white-space:nowrap">
                                <span class="asc-pill {{ $e['category'] === FeatureCatalog::ESSENTIAL ? 'is-done' : '' }}" data-category="{{ $e['category'] }}">{{ $categories[$e['category']] }}</span>
                            </td>
                            @foreach($plans as $p)
                                <td style="text-align:center; padding:.45rem .5rem; vertical-align:top">
                                    @php $included = $this->includes($e, $p->slug); @endphp
                                    @if($e['category'] === FeatureCatalog::ROLE)
                                        <span class="asc-muted asc-small">{{ $e['key'] === 'staff_users' ? 'Hasta '.($p->limit(\App\Enums\PlanLimit::AdminUsers) ?? '∞') : 'Según rol' }}</span>
                                    @elseif($e['feature'] && $e['category'] === FeatureCatalog::CONFIGURABLE)
                                        <input type="checkbox" wire:model.live="features.{{ $p->slug }}.{{ $e['feature']->value }}" data-feature="{{ $p->slug }}.{{ $e['feature']->value }}" aria-label="{{ $e['name'] }} en {{ $p->shortName() }}">
                                    @elseif($e['partOf'] && ! $e['partOf']->isEssential())
                                        <span class="asc-small" title="Va con «{{ $e['partOf']->label() }}»">{{ $included ? '✓' : '—' }}</span>
                                    @else
                                        <span title="Esencial: no se puede quitar" data-locked="{{ $p->slug }}.{{ $e['key'] }}">🔒 ✓</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="{{ $plans->count() + 2 }}" class="asc-muted" style="padding:.75rem .5rem">Ninguna función coincide con la búsqueda.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="margin-top:.75rem">{{ $this->saveAction }}</div>
    </div>

    {{-- Historial. --}}
    <div class="asc-card" data-plan-history>
        <p class="asc-q" style="font-weight:600">Historial de cambios</p>
        @forelse($history as $h)
            <div class="asc-row" style="align-items:flex-start">
                <span><strong>{{ $h->created_at?->format('d/m/Y H:i') }}</strong> · {{ $h->user_name ?? 'Usuario eliminado' }}<br>
                    <span class="asc-small">{{ ucfirst($h->plan_slug) }}: «{{ $h->featureLabel() }}» {{ $h->enabled_before ? 'incluida' : 'no incluida' }} → {{ $h->enabled_after ? 'incluida' : 'no incluida' }}</span>
                    @if($h->context)<br><span class="asc-muted asc-small">Motivo: {{ $h->context }}</span>@endif
                </span>
            </div>
        @empty
            <p class="asc-c">Todavía no hubo cambios.</p>
        @endforelse
    </div>
</x-filament-panels::page>
