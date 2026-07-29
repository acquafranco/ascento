<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6">

    {{-- HEADER --}}
    <div class="mb-6">

        <h1 class="text-3xl font-black">
             🏢 Edificios
        </h1>

        <p class="text-gray-500">
            Información técnica de los edificios
        </p>

    </div>


    {{-- BUSCADOR --}}
    <div class="mb-6">

        <input
            type="text"
            id="searchBuilding"
            placeholder="Buscar edificio, dirección, cliente..."
            class="w-full rounded-2xl border-gray-300 shadow-sm"
        >

    </div>



    {{-- LISTA --}}
    <div
        id="buildingsList"
        class="space-y-4"
    >

        @forelse($buildings as $building)


       <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">


    <h2 class="text-xl font-black">
         {{ $building->client?->name ?? $building->client_name ?? '—' }}

    </h2>


    <div class="text-sm text-gray-500 mt-1">
        📍 {{ $building->name }} {{ $building->address }}
    </div>


    <div class="mt-4 grid grid-cols-2 gap-3">


        <div class="bg-slate-50 rounded-xl p-3">

            <div class="text-gray-500 text-xs">
                Contacto
            </div>

            <div class="font-bold">
                {{ $building->contact_person ?? '—' }}
            </div>

        </div>


        <div class="bg-slate-50 rounded-xl p-3">

            <div class="text-gray-500 text-xs">
                Telefono
            </div>

            <div class="font-bold">
                 {{ $building->phone ?? '—' }}
            </div>

        </div>


    </div>


    {{-- EQUIPOS --}}
    <div class="mt-4 flex gap-3">


        <div class="flex-1 bg-blue-100 rounded-xl p-3 text-center">

            <div class="text-xs text-gray-500">
                🛗 Ascensores
            </div>

            <div class="text-xl font-black">
                {{ $building->elevator_count }}
            </div>

        </div>


        <div class="flex-1 bg-rose-200 rounded-xl p-3 text-center">

            <div class="text-xs text-gray-500">
                🏗️ Montacargas
            </div>

            <div class="text-xl font-black">
                {{ $building->freight_elevator_count }}
            </div>

        </div>


    </div>


    @if($building->notes)

    <div class="mt-4 bg-amber-200 rounded-xl p-3 text-sm">

        <strong>
            Observaciones:
        </strong>

        <div>
            {{ $building->notes }}
        </div>

    </div>

    @endif


</div>


        @empty


        <div class="text-center text-gray-500 py-10">

            No hay edificios cargados

        </div>


        @endforelse


    </div>


</div>
<div class="mt-6">
    {{ $buildings->links() }}
</div>


<script>

document
.getElementById('searchBuilding')
.addEventListener('keyup', function(){

    let value = this.value.toLowerCase();

    document
    .querySelectorAll('.building-card')
    .forEach(card => {

        let data = card.dataset.search;

        card.style.display =
            data.includes(value)
            ? 'block'
            : 'none';

    });


});


</script>


</x-app-layout>
