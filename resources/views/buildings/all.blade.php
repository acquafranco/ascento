<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6">

    {{-- HEADER --}}
    <div class="mb-6">

        <h1 class="text-3xl font-black">
            Edificios
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


        <div
            class="building-card bg-white rounded-3xl border border-slate-200 shadow-sm p-5"
            data-search="
            {{ strtolower(
                $building->name.' '.
                $building->address.' '.
                $building->client?->name.' '.
                $building->phone
            ) }}"
        >


            {{-- TITULO --}}
            <div class="mb-4">

                <h2 class="text-xl font-black text-slate-900">
                    {{ $building->name }}
                </h2>

                <p class="text-sm text-slate-500">
                    📍 {{ $building->address }}
                </p>

            </div>



            {{-- CLIENTE --}}
            <div class="grid grid-cols-2 gap-3 text-sm">


                <div class="bg-slate-50 rounded-xl p-3">

                    <div class="text-gray-500">
                        Cliente
                    </div>

                    <div class="font-bold">
                        {{ $building->client?->name ?? $building->client_name ?? '—' }}
                    </div>

                </div>



                <div class="bg-slate-50 rounded-xl p-3">

                    <div class="text-gray-500">
                        Contacto
                    </div>

                    <div class="font-bold">
                        {{ $building->contact_person ?? '—' }}
                    </div>

                </div>



                <div class="bg-slate-50 rounded-xl p-3">

                    <div class="text-gray-500">
                        Teléfono
                    </div>

                    <div class="font-bold">
                        {{ $building->phone ?? '—' }}
                    </div>

                </div>



                <div class="bg-slate-50 rounded-xl p-3">

                    <div class="text-gray-500">
                        Ascensores
                    </div>

                    <div class="font-bold">
                        {{ $building->elevator_count }}
                    </div>

                </div>


            </div>



            {{-- DATOS TECNICOS --}}
            <div class="mt-4 grid grid-cols-3 gap-2">


                <div class="rounded-xl bg-blue-50 p-3 text-center">

                    <div class="text-xs text-gray-500">
                        Tracción
                    </div>

                    <div class="font-black">
                        {{ $building->traction_elevator_count }}
                    </div>

                </div>



                <div class="rounded-xl bg-green-50 p-3 text-center">

                    <div class="text-xs text-gray-500">
                        Hidráulicos
                    </div>

                    <div class="font-black">
                        {{ $building->hydraulic_elevator_count }}
                    </div>

                </div>



                <div class="rounded-xl bg-orange-50 p-3 text-center">

                    <div class="text-xs text-gray-500">
                        Montacargas
                    </div>

                    <div class="font-black">
                        {{ $building->freight_elevator_count }}
                    </div>

                </div>


            </div>



            {{-- NOTAS --}}
            @if($building->notes)

            <div class="mt-4 bg-yellow-50 rounded-xl p-3 text-sm">

                <div class="font-bold">
                    Observaciones
                </div>

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
