<x-app-layout>

<div class="max-w-3xl mx-auto px-4 pt-8 pb-32">

    <a
        href="{{ route('work-orders.index', ['company' => auth()->user()->company->slug]) }}"
        class="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800"
    >
        ← Todas mis órdenes
    </a>

    <h1 class="mt-3 mb-6 text-2xl font-black">
        🔧 Orden de trabajo
        <span class="ml-1 text-base font-semibold text-slate-400">
            {{ \App\Support\WorkOrderLabels::status($workOrder->status) }}
        </span>
    </h1>

    @if(session('success'))
        <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-semibold text-emerald-700">
            {{ session('success') }}
        </div>
    @endif

    @include('work-orders.partials.card', ['workOrder' => $workOrder])

    @if($workOrder->building?->client)
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
            <span class="font-semibold text-slate-800">Cliente:</span>
            {{ $workOrder->building->client->name }}

            @if($workOrder->building->contact_person || $workOrder->building->phone)
                <div class="mt-1">
                    <span class="font-semibold text-slate-800">Contacto en el edificio:</span>
                    {{ collect([$workOrder->building->contact_person, $workOrder->building->phone])->filter()->implode(' · ') }}
                </div>
            @endif
        </div>
    @endif

</div>

</x-app-layout>
