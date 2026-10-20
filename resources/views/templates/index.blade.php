{{--
    «Planilla» del técnico: sus trabajos del mes, semana por semana y día por
    día (TechnicianSheet). Solo la propia: la de otro técnico la ve el dueño en
    el panel.
--}}
@php
    $slug = auth()->user()->company->slug;
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Mi planilla</h1>
        <p class="t-sub">Tus trabajos del mes: los que hiciste y en los que participaste. Solo la ves vos y el dueño de la empresa.</p>
    </header>

    @include('planilla.sheet', [
        'sheet' => $sheet,
        'self' => true,
        'navUrl' => fn ($m, $y) => route('templates.index', ['company' => $slug, 'month' => $m, 'year' => $y]),
        'dayUrl' => fn ($d) => route('templates.day', ['company' => $slug, 'date' => $d->format('Y-m-d')]),
        'remitoUrl' => $remitoUrl,
    ])
</div>
</x-app-layout>
