<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6">

    {{-- HEADER --}}
<div class="mb-6 flex flex-col gap-4">

    <div>
        <h1 class="text-3xl font-black flex items-center gap-2">
            📍 Mis visitas
        </h1>

        <p class="text-gray-500">
            Mantenimientos e inspecciones asignados
        </p>

    </div>

<div class="grid grid-cols-3 gap-2 w-full">

    {{-- TOTAL --}}
    <div class="bg-blue-100 border border-blue-200 rounded-2xl p-2 text-center">
        <div class="w-full h-8 flex items-center justify-center">
            <span class="w-full text-center text-xs font-semibold text-green-700 leading-tight">
                🛗 Máquinas totales
            </span>
        </div>

        <div class="text-3xl font-black text-blue-800 leading-none mt-1">
            {{ $totalMachines }}
        </div>
    </div>


    {{-- MANTENIMIENTO --}}
    @if($maintenanceTotalMachines > 0)

        <div class="bg-green-100 border border-green-200 rounded-2xl p-2 text-center">
            <div class="w-full h-8 flex items-center justify-center">
                <span class="w-full text-center text-xs font-semibold text-green-700 leading-tight">
                    🔧 Mant. restantes
                </span>
            </div>

            <div class="text-3xl font-black text-green-800 leading-none mt-1">
                {{ $maintenanceRemaining }}
            </div>
        </div>

    @endif


    {{-- INSPECCION --}}
    @if($inspectionTotalMachines > 0)

        <div class="bg-purple-100 border border-purple-200 rounded-2xl p-2 text-center">
            <div class="w-full h-8 flex items-center justify-center">
                <span class="w-full text-center text-xs font-semibold text-green-700 leading-tight">
                  🔎 Insp. restantes
                </span>
            </div>

            <div class="text-3xl font-black text-purple-800 leading-none mt-1">
                {{ $inspectionRemaining }}
            </div>
        </div>

    @endif

</div>
</div>

<form method="GET" id="filtersForm" class="mb-5 space-y-3">

    <div class="flex gap-2">

        <select
            name="month"
            onchange="document.getElementById('filtersForm').submit()"
            class="rounded-xl border-gray-300"
        >
            @for($m = 1; $m <= 12; $m++)
                <option
                    value="{{ $m }}"
                    @selected($month == $m)
                >
                    {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                </option>
            @endfor
        </select>


        <select
            name="year"
            onchange="document.getElementById('filtersForm').submit()"
            class="rounded-xl border-gray-300"
        >
            @for($y = now()->year - 2; $y <= now()->year + 2; $y++)
                <option
                    value="{{ $y }}"
                    @selected($year == $y)
                >
                    {{ $y }}
                </option>
            @endfor
        </select>

    </div>


    <input
        type="text"
        name="search"
        value="{{ request('search') }}"
        id="searchInput"
        placeholder="Buscar calle, cliente, localidad o barrio..."
        class="w-full rounded-2xl border-gray-300 shadow-sm"
    >

</form>


    {{-- LISTA --}}
    <div class="space-y-3" id="buildingsList">

        @forelse($buildings as $building)
            @php
            $types = $building->users
    ->pluck('pivot.type')
    ->flatMap(function ($type) {
        return explode(',', $type);
    })
    ->toArray();

            $maintenanceVisit = $building->visits
                ->where('assignment_type', 'maintenance')
                ->first();

            $inspectionVisit = $building->visits
                ->where('assignment_type', 'inspection')
                ->first();
            @endphp
            {{-- 🔥 UN SOLO DISEÑO (mobile + desktop) --}}
            <div
                class="building-card bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden"
                data-name="{{ strtolower(
                    $building->name . ' ' .
                    $building->address . ' ' .
                    ($building->client?->name ?? '') . ' ' .
                    ($building->locality ?? '') . ' ' .
                    ($building->neighborhood ?? '') . ' ' .
                    ($building->municipality ?? '')
                ) }}"
                 >

                <div class="p-4">

                    {{-- HEADER --}}
                    <div class="flex items-start justify-between gap-3">

                        <div>

                                <div class="font-bold">

                                    {{ $building->client?->name ?? 'Sin cliente' }}

                                </div>

                                <div class="text-sm text-slate-500">

                                    {{ $building->name }} {{ $building->address }}

                                </div>

                        </div>

                        <div class="text-xs text-slate-500 mt-1">

                        {{ $building->locality }}

                        @if($building->neighborhood)
                            · {{ $building->neighborhood }}
                        @endif

                        @if($building->municipality)
                            · {{ $building->municipality }}
                        @endif

                    </div>

                    </div>

                    {{-- INFO SIMPLE (SIN ÍCONOS) --}}
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">

                        <div class="bg-slate-50 border rounded-xl p-2">
                            <div class="text-slate-500">Contacto</div>
                            <div class="font-semibold">
                                {{ $building->contact_person ?? '—' }}
                            </div>
                        </div>

                        <div class="bg-slate-50 border rounded-xl p-2">
                            <div class="text-slate-500">Teléfono</div>
                            <div class="font-semibold">
                                {{ $building->phone ?: '—' }}
                            </div>
                        </div>

                        <div class="bg-slate-50 border rounded-xl p-2 col-span-2">
                            <div class="text-slate-500">Asc / Mont</div>
                            <div class="font-semibold">
                                {{ $building->elevator_count }} / {{ $building->freight_elevator_count }}
                            </div>
                        </div>

                    </div>

                    {{-- ESTADO --}}
                    <div class="mt-4 space-y-3">

                {{-- MANTENIMIENTO --}}
                @if(in_array('maintenance', $types))

                    @if(!$maintenanceVisit)

                        <a
                            href="{{ route('delivery-notes.building',[
                                'company' => auth()->user()->company->slug,
                                'building'=>$building,
                                'month'=>$month,
                                'year'=>$year,
                                'assignment_type'=>'maintenance'
                            ]) }}"
                            class="block text-center bg-slate-800 hover:bg-slate-900 text-white py-3 rounded-2xl font-bold"
                        >
                            🔧 Marcar mantenimiento
                        </a>

                    @else

                        <div class="flex items-center justify-between bg-green-50 border border-green-200 rounded-2xl p-4">

                            <div>

                                <div class="font-bold text-green-700">
                                    🔧 Mantenimiento realizado
                                </div>

                                @if($maintenanceVisit->delivery_note)
                                    <div class="text-sm text-slate-500">
                                        Remito #{{ $maintenanceVisit->delivery_note }}
                                    </div>
                                @endif

                            </div>



                        </div>

                    @endif

                @endif



                {{-- INSPECCIÓN --}}
                @if(in_array('inspection', $types))

                    @if(!$inspectionVisit)

                        <a
                            href="{{ route('delivery-notes.building',[
                                'company' => auth()->user()->company->slug,
                                'building'=>$building,
                                'month'=>$month,
                                'year'=>$year,
                                'assignment_type'=>'inspection'
                            ]) }}"
                            class="block text-center bg-blue-700 hover:bg-blue-800 text-white py-3 rounded-2xl font-bold"
                        >
                            🔎 Marcar inspección
                        </a>

                    @else

                        <div class="flex items-center justify-between bg-blue-50 border border-blue-200 rounded-2xl p-4">

                            <div>

                                <div class="font-bold text-blue-700">
                                    🔎 Inspección realizada
                                </div>

                                @if($inspectionVisit->delivery_note)
                                    <div class="text-sm text-slate-500">
                                        Remito #{{ $inspectionVisit->delivery_note }}
                                    </div>
                                @endif

                            </div>



                        </div>

                    @endif

                @endif

            </div>

                </div>

            </div>

        @empty

            <div class="text-center py-10 text-gray-500">
                No tenés edificios asignados
            </div>

        @endforelse

    </div>

</div>

<div class="mt-6">
    {{ $buildings->links() }}
</div>
<script>
function confirmarDesmarcar() {

    return confirm(
`Este mantenimiento ya tiene un remito asociado.

Si lo desmarcás:

• El remito NO se eliminará.
• El edificio volverá a quedar pendiente.
• Podrías generar un remito duplicado si volvés a realizar el mantenimiento.

¿Querés continuar?`
    );

}
</script>
<script>

let timer;

const searchInput = document.getElementById('searchInput');

searchInput.addEventListener('input', function () {

    clearTimeout(timer);

    timer = setTimeout(() => {

        document
            .getElementById('filtersForm')
            .submit();

    }, 500);

});


window.onload = function () {

    if (searchInput.value) {

        searchInput.focus();

        searchInput.setSelectionRange(
            searchInput.value.length,
            searchInput.value.length
        );

    }

};

</script>


</x-app-layout>
