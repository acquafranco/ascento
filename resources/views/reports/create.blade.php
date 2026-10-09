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

    @if (! empty($planUsage))
        <div class="mb-4 flex items-center justify-between gap-3 rounded-2xl border px-4 py-3 text-sm {{ $planUsage['warning'] ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-600' }}" data-plan-usage>
            <span><strong>{{ $planUsage['label'] }}</strong></span>
            @if ($planUsage['warning'])
                <span>{{ $planUsage['warning'] }}</span>
            @endif
        </div>
    @endif

    @if ($errors->any())
    <div class="bg-red-100 text-red-700 p-4 rounded">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

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
            <label class="text-sm font-bold text-gray-700" for="component">
                ¿Qué parte falla? <span class="font-normal text-gray-500">(opcional)</span>
            </label>
            <select id="component" name="component" class="mt-1 w-full rounded-2xl border-slate-200 text-black p-3">
                <option value="">No sé / no aplica</option>
                @foreach(\App\Support\ElevatorComponents::LIST as $value => $label)
                    <option value="{{ $value }}" @selected(old('component') === $value)>{{ $label }}</option>
                @endforeach
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
                Fotos del problema <span class="font-normal text-gray-500">(opcional, hasta {{ \App\Services\Reports\ReportPhotoService::MAX_PHOTOS }})</span>
            </label>

            <div class="mt-2 space-y-3">

                <label id="photoButton" class="flex flex-col items-center justify-center h-28 rounded-3xl bg-blue-50 border border-blue-200 cursor-pointer active:bg-blue-100">
                    <div class="text-3xl">📷</div>
                    <div class="text-base font-bold text-blue-700 mt-1">Agregar foto</div>
                    <div class="text-xs text-gray-500">Sacá una foto o elegí de la galería</div>

                    {{-- Selector: cada vez que se usa, las fotos se SUMAN a la lista. --}}
                    <input id="photoPicker" type="file" accept="image/*" multiple class="hidden">
                </label>

                {{-- Lo que se envía (lo arma el script con las fotos elegidas). --}}
                <input id="photoInput" type="file" name="photos[]" multiple class="hidden">

                <div id="photoCount" class="hidden text-sm font-bold text-gray-700"></div>
                <div id="photoList" class="grid grid-cols-3 gap-3"></div>
            </div>
        </div>

        @if(\App\Services\Reports\ReportVideoService::allowedFor(auth()->user()->company))
            {{-- Un video por reporte (Profesional y Empresa). Se valida también en el servidor. --}}
            <div>
                <label for="videoInput" class="text-sm font-bold text-gray-700">
                    Video del problema <span class="font-normal text-gray-500">(opcional, hasta {{ config('media.video_max_seconds') }} s y {{ config('media.video_max_mb') }} MB)</span>
                </label>
                <input id="videoInput" type="file" name="video" accept="video/mp4,video/quicktime,video/webm"
                       class="mt-2 block w-full text-sm text-gray-700 file:mr-3 file:rounded-xl file:border-0 file:bg-blue-50 file:px-4 file:py-3 file:font-bold file:text-blue-700">
                <p id="videoHint" class="mt-1 text-xs text-gray-500">Grabá un video corto donde se vea la falla. Con señal débil puede tardar en subir.</p>
                @error('video')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        @endif


        <button id="submitReport" class="w-full py-4 rounded-2xl bg-blue-600 text-white text-lg font-bold shadow-sm disabled:opacity-60">
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
            // textContent: nombre y dirección son texto cargado por usuarios.
            const name = document.createElement('span');
            name.textContent = building.name;

            const address = document.createElement('span');
            address.className = 'block text-xs text-gray-500';
            address.textContent = building.address ?? '';

            item.append(name, address);

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

    /*
    | Fotos: se suman de a una o varias, se pueden quitar, y antes de enviar se
    | achican en el celular (2000 px, JPEG) para que suban rápido con datos
    | móviles. Si el navegador no puede (p. ej. HEIC fuera de Safari), se manda
    | la original: el servidor igual la valida y la procesa.
    */
    const MAX_PHOTOS = {{ \App\Services\Reports\ReportPhotoService::MAX_PHOTOS }};
    const MAX_SIDE = 2000;
    const picker = document.getElementById('photoPicker');
    const photoInput = document.getElementById('photoInput');
    const photoList = document.getElementById('photoList');
    const photoCount = document.getElementById('photoCount');
    const photoButton = document.getElementById('photoButton');
    const form = photoInput.closest('form');
    const submit = document.getElementById('submitReport');
    let photos = [];

    function render() {
        photoList.innerHTML = '';

        photos.forEach((file, index) => {
            const cell = document.createElement('div');
            cell.className = 'relative';

            const img = document.createElement('img');
            img.className = 'w-full aspect-square rounded-2xl object-cover border border-slate-200 bg-slate-100';
            img.alt = 'Foto ' + (index + 1);
            const url = URL.createObjectURL(file);
            img.onload = img.onerror = () => URL.revokeObjectURL(url);
            img.src = url;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'mt-1 w-full py-2 rounded-xl bg-red-50 text-red-700 text-sm font-bold border border-red-200';
            remove.textContent = 'Quitar';
            remove.addEventListener('click', () => { photos.splice(index, 1); render(); });

            cell.append(img, remove);
            photoList.append(cell);
        });

        photoCount.textContent = photos.length + ' de ' + MAX_PHOTOS + ' fotos';
        photoCount.classList.toggle('hidden', photos.length === 0);
        photoButton.classList.toggle('hidden', photos.length >= MAX_PHOTOS);
    }

    picker.addEventListener('change', () => {
        const added = Array.from(picker.files);
        const room = MAX_PHOTOS - photos.length;

        if (added.length > room) {
            alert('Podés adjuntar hasta ' + MAX_PHOTOS + ' fotos. Se agregaron las primeras ' + room + '.');
        }

        photos = photos.concat(added.slice(0, room));
        picker.value = '';
        render();
    });

    async function shrink(file) {
        try {
            if (!window.createImageBitmap) return file;
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
        } catch (e) {
            return file;
        }
    }

    form.addEventListener('submit', async (event) => {
        if (form.dataset.ready === '1') return;
        event.preventDefault();

        if (navigator.onLine === false) {
            alert('No hay conexión a internet. El reporte NO se envió, pero no se perdió nada: esperá a tener señal y tocá "Enviar reporte" de nuevo.');
            return;
        }
        // Video: tamaño y duración antes de subir (el servidor lo vuelve a validar).
        const video = document.getElementById('videoInput')?.files?.[0];
        if (video) {
            const maxMb = {{ (int) config('media.video_max_mb') }}, maxSeconds = {{ (int) config('media.video_max_seconds') }};
            if (video.size > maxMb * 1024 * 1024) {
                alert('El video pesa ' + (video.size / 1048576).toFixed(1) + ' MB. El máximo es ' + maxMb + ' MB: grabá uno más corto.');
                return;
            }
            const seconds = await new Promise((resolve) => {
                const probe = document.createElement('video');
                probe.preload = 'metadata';
                probe.onloadedmetadata = () => { URL.revokeObjectURL(probe.src); resolve(probe.duration); };
                probe.onerror = () => resolve(0);
                probe.src = URL.createObjectURL(video);
                setTimeout(() => resolve(0), 4000);
            });
            if (seconds > maxSeconds + 1) {
                alert('El video dura ' + Math.round(seconds) + ' segundos. El máximo es ' + maxSeconds + ' segundos.');
                return;
            }
        }

        submit.disabled = true;
        submit.textContent = photos.length ? 'Preparando fotos…' : (video ? 'Subiendo video…' : 'Enviando…');

        try {
            const transfer = new DataTransfer();
            for (const file of photos) transfer.items.add(await shrink(file));
            photoInput.files = transfer.files;
        } catch (e) {
            // Navegador muy viejo, sin DataTransfer: no se pueden adjuntar las
            // fotos elegidas. Se avisa en vez de mandar el reporte sin ellas.
            if (photos.length && !confirm('Este navegador no permite adjuntar las fotos. ¿Enviar el reporte sin fotos?')) {
                submit.disabled = false;
                submit.textContent = 'Enviar reporte';
                return;
            }
        }

        submit.textContent = 'Enviando…';
        form.dataset.ready = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
    });
});

</script>

</x-app-layout>
