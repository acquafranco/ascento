<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6">

    {{-- HEADER --}}
    <div class="mb-6">

        <h1 class="text-3xl font-black">
            Mis edificios
        </h1>

        <p class="text-gray-500">
            Marcá los mantenimientos realizados
        </p>

    </div>

    {{-- BUSCADOR --}}
    <div class="mb-5">

        <input
            type="text"
            id="searchBuilding"
            placeholder="Buscar edificio..."
            class="w-full rounded-2xl border-gray-300 shadow-sm"
        >

    </div>

    {{-- LISTA --}}
    <div class="space-y-3" id="buildingsList">

        @forelse($buildings as $building)

            @php
                $visit =
                    \App\Models\BuildingVisit::where('building_id', $building->id)
                        ->where('user_id', auth()->id())
                        ->where('visit_type', 'fixed')
                        ->where('month', now()->month)
                        ->where('year', now()->year)
                        ->first();

                $done = $visit !== null;
            @endphp

            {{-- 🔥 UN SOLO DISEÑO (mobile + desktop) --}}
            <div
                class="building-card bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden"
                data-name="{{ strtolower($building->name . ' ' . $building->address . ' ' . ($building->client?->name ?? '')) }}"
            >

                <div class="p-4">

                    {{-- HEADER --}}
                    <div class="flex items-start justify-between gap-3">

                        <div>

                            <div class="font-semibold text-slate-900">
                                {{ $building->client?->name ?? 'Sin cliente' }}
                            </div>

                            <p class="text-sm text-slate-500 mt-1">
                                {{ $building->name }} {{ $building->address }}
                            </p>

                        </div>

                        <div class="text-xs text-slate-400 whitespace-nowrap">
                            #{{ $building->id }}
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
                    <div class="mt-4">

                        @if(!$done)

                            <a
                                href="{{ route('delivery-notes.building', $building) }}"
                                class="block text-center bg-slate-800 hover:bg-slate-900 text-white py-3 rounded-2xl font-bold transition"
                            >
                                Marcar mantenimiento
                            </a>

                        @else

                            <div class="flex items-center justify-between bg-green-50 border border-green-200 rounded-2xl p-4">

                                <div>

                                    <div class="font-bold text-green-700">
                                        Mantenimiento realizado
                                    </div>

                                    @if($visit->delivery_note)
                                        <div class="text-sm text-slate-500">
                                            Remito #{{ $visit->delivery_note }}
                                        </div>
                                    @endif

                                </div>

                                <form
                                    method="POST"
                                    action="{{ route('building-check.done', $building) }}"
                                    onsubmit="return confirm(
                                'Este mantenimiento ya posee un remito asociado.

                                Si lo desmarcás, el remito NO será eliminado.

                                El edificio volverá a aparecer como pendiente y podrías generar un remito duplicado.

                                ¿Deseás continuar?'
                                )"
                                >
                                    @csrf

                                    <button class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-xl font-semibold">
                                        Desmarcar
                                    </button>

                                </form>

                            </div>

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

<script>
document.getElementById('searchBuilding').addEventListener('keyup', function () {

    let value = this.value.toLowerCase();
    let cards = document.querySelectorAll('.building-card');

    cards.forEach(card => {
        let name = card.dataset.name;
        card.style.display = name.includes(value) ? 'block' : 'none';
    });

});
</script>

</x-app-layout>
