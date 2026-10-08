<x-app-layout>

<div class="max-w-xl mx-auto px-4 pt-16 pb-32 text-center">

    <div class="text-5xl" aria-hidden="true">📋</div>

    <h1 class="mt-4 text-2xl font-black text-slate-800">{{ $message }}</h1>

    <p class="mt-3 text-slate-500">
        Ya le avisamos al administrador de tu empresa: con el plan Profesional se pueden cargar
        reportes sin límite mensual. El mes que viene el contador vuelve a cero.
    </p>

    <a href="{{ route('reports.index', ['company' => $company->slug]) }}"
       class="mt-8 inline-flex rounded-2xl bg-slate-900 px-5 py-3 font-bold text-white">
        Ver reportes
    </a>

</div>

</x-app-layout>
