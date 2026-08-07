<x-app-layout>
    @if(session('success'))
    <div class="mb-4 rounded-lg bg-green-100 px-4 py-3 text-green-800">
        {{ session('success') }}
    </div>
@endif
    <div class="max-w-7xl mx-auto p-6">

 <div class="flex items-center justify-between mb-8">

    <div>
        <h1 class="text-3xl font-black">
            📄 Mis Remitos
        </h1>

        <p class="text-gray-500 mt-1">
            Historial de remitos generados
        </p>
    </div>

    <div class="bg-blue-50 text-blue-700 px-4 py-2 rounded-2xl font-bold">
        {{ $deliveryNotes->count() }} remitos
    </div>

</div>

<form method="GET" class="mb-6 flex flex-wrap items-center gap-2">

    <select
        name="day"
        onchange="this.form.submit()"
        class="w-20 rounded-xl border-gray-300 text-sm px-2"
    >
        <option value="">Día</option>

        @for($d=1;$d<=31;$d++)
            <option
                value="{{ $d }}"
                @selected(request('day')==$d)
            >
                {{ $d }}
            </option>
        @endfor

    </select>

    <select
        name="month"
        onchange="this.form.submit()"
        class="w-20 rounded-xl border-gray-300 text-sm px-2"
    >

        <option value="">Mes</option>

        @for($m=1;$m<=12;$m++)
            <option
                value="{{ $m }}"
                @selected(request('month')==$m)
            >
                {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
            </option>
        @endfor

    </select>

    <select
        name="year"
        onchange="this.form.submit()"
        class="w-20 rounded-xl border-gray-300 text-sm px-2"
    >

        <option value="">Año</option>

        @for($y=now()->year-3;$y<=now()->year+1;$y++)
            <option
                value="{{ $y }}"
                @selected(request('year')==$y)
            >
                {{ $y }}
            </option>
        @endfor

    </select>

      <a
        href="{{ route('delivery-notes.index', [
            'company' => auth()->user()->company->slug,
            'status' => request('status')
        ]) }}"
        title="Limpiar filtros"
        class="h-10 w-20 rounded-xl bg-blue-600 text-white flex items-center justify-center hover:bg-blue-700 transition shadow-sm shrink-0"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
    </a>

</form>

<div class="grid gap-4">

    @forelse($deliveryNotes as $note)

        <a href="{{ route('delivery-notes.show', [
    'company' => auth()->user()->company->slug,
    'deliveryNote' => $note->number
    ]) }}"

            class="bg-white rounded-3xl shadow hover:shadow-lg transition p-6 block"
        >

            <div class="flex justify-between items-start">

                <div>

                    <h2 class="font-black text-xl">
                        Remito #{{ $note->number }}
                    </h2>

                    <p class="text-gray-500 mt-1">
                        {{ $note->building?->name }} {{ $note->building?->address }}

                    </p>

                    @if($note->workOrder)

                        @php
                            $type = $note->workOrder->type;
                            $label = \App\Support\WorkOrderLabels::type($type);

                            $colors = [
                                'maintenance' => 'bg-blue-100 text-blue-700',
                                'inspection' => 'bg-yellow-100 text-yellow-700',
                                'claim' => 'bg-red-100 text-red-700',
                                'installation' => 'bg-green-100 text-green-700',
                                'modernization' => 'bg-purple-100 text-purple-700',
                            ];
                        @endphp

                        <p class="text-sm mt-2">
                            OT:

                            <span class="px-3 py-1 rounded-full text-xs font-black {{ $colors[$type] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $label }}
                            </span>
                        </p>


                    @elseif($note->buildingVisit)

                        @php
                            $type = $note->buildingVisit->assignment_type;
                            $label = \App\Support\WorkOrderLabels::type($type);

                            $colors = [
                                'maintenance' => 'bg-blue-100 text-blue-700',
                                'inspection' => 'bg-yellow-100 text-yellow-700',
                            ];
                        @endphp

                        <p class="text-sm mt-2">
                            Visita:

                            <span class="px-3 py-1 rounded-full text-xs font-black {{ $colors[$type] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $label }}
                            </span>
                        </p>

                    @endif
                </div>

                <div>

                    @if($note->performed)

                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm font-bold">
                            Realizado
                        </span>

                    @else

                        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm font-bold">
                            No realizado
                        </span>

                    @endif

                </div>

            </div>

            <div class="mt-4 text-sm text-gray-500">

                {{ $note->created_at->format('d/m/Y H:i') }}

            </div>

        </a>

    @empty

        <div class="bg-white rounded-3xl shadow p-10 text-center">

            <div class="text-5xl mb-3">
                📄
            </div>

            <h2 class="font-bold text-lg">
                Todavía no hay remitos
            </h2>

        </div>

    @endforelse

</div>
</div>
</x-app-layout>

