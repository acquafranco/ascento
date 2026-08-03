<x-app-layout>

<div class="max-w-xl mx-auto px-4 py-6" style="padding-bottom: 100px;">

    <div class="mb-6">
        <h1 class="text-3xl font-black text-black">
            📋 Nuevo reporte
        </h1>

        <p class="text-gray-500 mt-1">
            Informá un problema del ascensor
        </p>
    </div>


    <form method="POST"
          action="{{ route('reports.store',['company'=>$company->slug]) }}"
          enctype="multipart/form-data"
          class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 space-y-4">

        @csrf

        <div>
            <label class="text-sm font-bold text-gray-700">
                Buscar edificio
            </label>

            <input
                type="text"
                id="buildingSearch"
                placeholder="Escribí nombre o dirección..."
                class="mt-1 w-full rounded-2xl border-slate-200 text-black p-3"
                autocomplete="off"
            >

            <div
                id="buildingResults"
                class="hidden mt-2 bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            </div>

            <input type="hidden" id="buildingSelect" name="building_id">

            <div id="selectedBuilding" class="hidden mt-3 bg-blue-50 rounded-2xl p-3 text-sm font-bold text-blue-800">
            </div>
        </div>


        <div>
            <label class="text-sm font-bold text-gray-700">
                Equipo
            </label>

            <select id="elevator_number"
                    name="elevator_number"
                    class="mt-1 w-full rounded-2xl border-slate-200 text-black p-3">
                <option value="">
                    Seleccioná primero un edificio
                </option>
            </select>
        </div>


        <div>
            <label class="text-sm font-bold text-gray-700">
                Descripción
            </label>

            <textarea name="description"
                      placeholder="Describí el problema encontrado"
                      class="mt-1 w-full rounded-2xl border-slate-200 text-black p-3 h-32"></textarea>
        </div>


        <div>
            <label class="text-sm font-bold text-gray-700">
                Prioridad
            </label>

            <select name="priority"
                    class="mt-1 w-full rounded-2xl border-slate-200 text-black p-3 font-bold">
                <option value="baja" class="bg-green-100 text-green-800">
                    🟢 Baja
                </option>
                <option value="media" class="bg-yellow-100 text-yellow-800">
                    🟡 Media
                </option>
                <option value="alta" class="bg-orange-100 text-orange-800">
                    🟠 Alta
                </option>
                <option value="critica" class="bg-red-100 text-red-800">
                    🔴 Crítica
                </option>
            </select>
        </div>


        <div>
            <label class="text-sm font-bold text-gray-700">
                Imagen del problema
            </label>

            <div class="mt-2 space-y-3">

                <label class="flex flex-col items-center justify-center h-28 rounded-3xl bg-blue-50 border border-blue-200 cursor-pointer">

                    <div class="text-3xl">📷</div>

                    <div class="text-sm font-bold text-blue-700 mt-1">
                        Tomar foto o elegir de galería
                    </div>

                    <div class="text-xs text-gray-500">
                        JPG, PNG o WEBP
                    </div>

                    <input
                        id="photoInput"
                        type="file"
                        name="photo"
                        accept="image/*"
                        capture="environment"
                        class="hidden"
                    >

                </label>

                <img
                    id="photoPreview"
                    class="hidden w-16 h-16 rounded-xl object-cover border border-slate-200"
                >

            </div>
        </div>


        <button class="w-full py-3 rounded-2xl bg-blue-600 text-white font-bold shadow-sm">
            Enviar reporte
        </button>

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const buildingSelect = document.getElementById('buildingSelect');
    const elevatorSelect = document.getElementById('elevator_number');
    const buildingSearch = document.getElementById('buildingSearch');
    const buildingResults = document.getElementById('buildingResults');
    const selectedBuilding = document.getElementById('selectedBuilding');
    const buildings = @json($buildings);

    buildingSearch.addEventListener('input', function(){

        const value = this.value.toLowerCase();

        buildingResults.innerHTML = '';
        buildingResults.classList.add('hidden');

        if(value.length < 1) return;

        const matches = buildings.filter(building =>
            (building.name + ' ' + building.address).toLowerCase().includes(value)
        ).slice(0, 10);

        matches.forEach(building => {

            const item = document.createElement('button');

            item.type = 'button';
            item.className = 'w-full text-left px-4 py-3 hover:bg-slate-50 border-b border-slate-100';
            item.innerHTML = `${building.name}<br><span class="text-xs text-gray-500">${building.address ?? ''}</span>`;

            item.onclick = () => {
                buildingSelect.value = building.id;
                buildingSearch.value = building.name;
                buildingResults.classList.add('hidden');
                selectedBuilding.textContent = '✓ ' + building.name + ' - ' + (building.address ?? '');
                selectedBuilding.classList.remove('hidden');
                loadElevators();
            };

            buildingResults.appendChild(item);
        });

        if(matches.length){
            buildingResults.classList.remove('hidden');
        }

    });

    function loadElevators() {
        elevatorSelect.innerHTML = '';

        const buildingId = buildingSelect.value;

        if (!buildingId) {
            elevatorSelect.innerHTML = '<option value="">Seleccioná primero un edificio</option>';
            return;
        }

        const building = buildings.find(b => b.id == buildingId);

        if (!building) {
            elevatorSelect.innerHTML = '<option value="">No se encontró el edificio</option>';
            return;
        }

        const elevators = Number(building.elevator_count ?? 0);
        const freight = Number(building.freight_elevator_count ?? 0);

        elevatorSelect.innerHTML = '<option value="">Seleccionar equipo</option>';

        for (let i = 1; i <= elevators; i++) {
            elevatorSelect.innerHTML += `<option value="Ascensor ${i}">Ascensor ${i}</option>`;
        }

        for (let i = 1; i <= freight; i++) {
            elevatorSelect.innerHTML += `<option value="Montacargas ${i}">Montacargas ${i}</option>`;
        }
    }

    const photoInput = document.getElementById('photoInput');
    const photoPreview = document.getElementById('photoPreview');

    function previewPhoto(input){

        const file = input.files[0];

        if (!file) {
            photoPreview.classList.add('hidden');
            return;
        }

        photoPreview.src = URL.createObjectURL(file);
        photoPreview.classList.remove('hidden');

    }

    photoInput.addEventListener('change', function(){
        previewPhoto(this);
    });
});
</script>

</x-app-layout>
