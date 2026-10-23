{{--
    Intervención del técnico (urgencia o trabajo no programado), sin esperar a la oficina.
    - Sin $workOrder: abre la visita. Edificio con el mismo buscador que «Nuevo reporte»,
      limitado a los que el técnico puede atender (asignados, o todos si está de guardia).
    - Con $workOrder: suma otro trabajo a esa visita (mismo edificio, mismo remito).
    El servidor vuelve a validar todo (InterventionService). submission_token evita duplicados.
--}}
@php
    use App\Support\TechNav;
    $action = $workOrder
        ? route('interventions.job.store', ['company' => $company->slug, 'workOrder' => $workOrder->id])
        : route('interventions.store', ['company' => $company->slug]);
    $units = $workOrder?->building?->unitLabels() ?? [];
@endphp
<x-app-layout>
<div class="t-page max-w-xl lg:max-w-xl">
    <a href="{{ $workOrder ? route('work-orders.show', ['company' => $company->slug, 'workOrder' => $workOrder->id]) : route('reports.index', ['company' => $company->slug]) }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $workOrder ? 'Orden de trabajo' : 'Reportes' }}
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">{{ $workOrder ? 'Otro trabajo de esta visita' : 'Registrar intervención' }}</h1>
        <p class="t-sub">
            @if($workOrder)
                Un problema más que atendiste en {{ $workOrder->building?->name }}. Va al mismo remito, como un trabajo aparte.
            @else
                Para urgencias o trabajos no programados (un reclamo por WhatsApp, por teléfono o del encargado). No necesitás que la oficina cree la orden: se crea ahora y después hacés el remito con la firma.
            @endif
        </p>
    </header>

    @if ($errors->any())
        <div class="t-alert-error mb-4" role="alert">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-5" novalidate data-intervention-form>
        @csrf
        <input type="hidden" name="submission_token" value="{{ $token }}">

        {{-- 1. Dónde --}}
        <section class="t-card p-4">
            @if($workOrder)
                <div class="t-label">Edificio</div>
                <div class="rounded-xl border border-ink-100 bg-ink-50 px-3 py-3">
                    <div class="font-semibold text-ink">{{ $workOrder->building?->name }}</div>
                    <div class="text-sm text-ink-700">{{ $workOrder->building?->address }}</div>
                </div>
            @else
                <label for="buildingSearch" class="t-label">Edificio</label>
                <div id="buildingPicker">
                    <input type="search" id="buildingSearch" class="t-input" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="buildingResults"
                           placeholder="Nombre, calle y altura, barrio o cliente">
                    <p class="mt-1.5 text-xs text-ink-500">
                        {{ $onCall ? 'Estás de guardia: podés elegir cualquier edificio de la empresa.' : 'Aparecen los edificios que tenés asignados.' }}
                    </p>
                    <div id="buildingResults" role="listbox" class="mt-2 hidden max-h-80 overflow-y-auto rounded-xl border border-ink-100 bg-white"></div>
                </div>
                <input type="hidden" id="buildingSelect" name="building_id" value="{{ old('building_id') }}">
                <div id="selectedBuilding" class="hidden items-start gap-3 rounded-xl border border-brand-200 bg-brand-50 px-3 py-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('check') !!}</svg>
                    <div class="min-w-0 flex-1">
                        <div id="selectedName" class="font-semibold text-ink"></div>
                        <div id="selectedMeta" class="text-sm text-ink-700"></div>
                    </div>
                    <button type="button" id="changeBuilding" class="t-btn-secondary t-btn-sm shrink-0">Cambiar</button>
                </div>
                {{-- Ya tiene una orden abierta en este edificio: seguirla evita duplicar el registro del mismo reclamo. --}}
                <div id="openOrders" class="t-alert-warning mt-3 hidden" data-open-orders>
                    <p class="font-semibold">Ya tenés órdenes abiertas en este edificio</p>
                    <p class="text-sm">Si es el mismo reclamo, seguí esa orden (podés sumarle trabajos). Si es otro problema, registrá la intervención igual.</p>
                    <ul id="openOrdersList" class="mt-1 space-y-1 text-sm font-semibold"></ul>
                </div>
            @endif

            <label for="elevator_number" class="t-label mt-4">Equipo</label>
            <select id="elevator_number" name="elevator_number" class="t-input" @if(! $workOrder) disabled @endif>
                @if($workOrder)
                    @if(count($units) > 1)<option value="">Elegí el equipo</option>@endif
                    @foreach($units as $unit)<option value="{{ $unit }}" @selected(old('elevator_number', $workOrder->unit) === $unit)>{{ $unit }}</option>@endforeach
                @else
                    <option value="">Elegí primero el edificio</option>
                @endif
            </select>
        </section>

        {{-- 2. El reclamo --}}
        <section class="t-card space-y-4 p-4">
            <fieldset>
                <legend class="t-label">¿Qué es?</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(\App\Services\Interventions\InterventionService::KINDS as $value => $kind)
                        <label class="relative">
                            <input type="radio" name="kind" value="{{ $value }}" class="peer sr-only" @checked(old('kind', 'urgent') === $value)>
                            <span class="flex min-h-[52px] cursor-pointer items-center justify-center rounded-xl border border-ink-100 bg-white px-2 text-center text-sm font-semibold text-ink-700 peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-brand">{{ $kind['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div>
                <label class="t-label" for="channel">¿Cómo te llegó el reclamo?</label>
                <select id="channel" name="channel" class="t-input">
                    @foreach(\App\Models\Report::CHANNELS as $value => $label)
                        <option value="{{ $value }}" @selected(old('channel', $workOrder ? 'detected' : 'whatsapp') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="t-label" for="component">¿Qué parte? <span class="font-normal text-ink-500">(opcional)</span></label>
                <select id="component" name="component" class="t-input">
                    <option value="">No sé / no aplica</option>
                    @foreach(\App\Support\ElevatorComponents::LIST as $value => $label)
                        <option value="{{ $value }}" @selected(old('component') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="t-label" for="description">Problema informado o encontrado</label>
                <textarea id="description" name="description" rows="4" maxlength="5000" class="t-input" placeholder="Ej.: «El ascensor 2 no baja del piso 5», según el encargado">{{ old('description') }}</textarea>
                <p class="mt-1.5 text-xs text-ink-500">Lo que hiciste y cómo quedó lo completás en el remito, junto con la firma.</p>
            </div>
        </section>

        {{-- 3. Fotos --}}
        <section class="t-card p-4">
            <div class="t-label">Fotos <span class="font-normal text-ink-500">(opcional, hasta {{ \App\Services\Reports\ReportPhotoService::MAX_PHOTOS }})</span></div>
            <label id="photoButton" class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-xl border border-dashed border-ink-300 bg-ink-50 px-4 py-3 active:bg-ink-100">
                <svg class="h-6 w-6 shrink-0 text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('camera') !!}</svg>
                <span>
                    <span class="block font-semibold text-ink">Agregar foto</span>
                    <span class="block text-xs text-ink-500">Sacá una foto o elegí de la galería</span>
                </span>
                <input id="photoPicker" type="file" accept="image/*" multiple class="hidden">
            </label>
            <input id="photoInput" type="file" name="photos[]" multiple class="hidden">
            <div id="photoList" class="mt-2 grid grid-cols-3 gap-2"></div>
        </section>

        <div id="formError" role="alert" class="t-alert-error hidden"></div>
        <div id="uploadProgress" class="hidden" aria-live="polite">
            <div class="h-2 w-full overflow-hidden rounded-full bg-ink-100"><div id="uploadBar" class="h-full w-0 rounded-full bg-brand transition-all"></div></div>
            <p id="uploadLabel" class="mt-2 text-sm font-semibold text-ink-700">Subiendo…</p>
        </div>

        <button id="submitIntervention" class="t-btn-primary w-full text-base">{{ $workOrder ? 'Agregar trabajo' : 'Registrar intervención' }}</button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-intervention-form]');
    const elevatorSelect = document.getElementById('elevator_number');
    const formError = document.getElementById('formError');
    const submit = document.getElementById('submitIntervention');
    const label = submit.textContent;
    const showError = (message) => { formError.textContent = message; formError.classList.remove('hidden'); formError.scrollIntoView({ block: 'center', behavior: 'smooth' }); };

    /* ---------- Edificio (solo al abrir la visita) ---------- */
    const buildings = @json($buildings);
    const buildingSelect = document.getElementById('buildingSelect');
    if (buildingSelect) {
        const search = document.getElementById('buildingSearch');
        const results = document.getElementById('buildingResults');
        const picker = document.getElementById('buildingPicker');
        const selected = document.getElementById('selectedBuilding');
        const normalize = (t) => (t ?? '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        buildings.forEach((b) => { b.haystack = normalize([b.name, b.address, b.place, b.client].join(' ')); });
        const hide = () => { results.classList.add('hidden'); search.setAttribute('aria-expanded', 'false'); };
        const show = (list) => {
            results.innerHTML = '';
            if (!list.length) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-3 text-sm text-ink-500';
                empty.textContent = buildings.length ? 'No encontramos edificios. Probá con la calle o el barrio.' : 'No tenés edificios asignados. Si estás de guardia, pedile a la oficina que te cargue en la guardia.';
                results.appendChild(empty);
            }
            list.forEach((b) => {
                // textContent: nombre, dirección y cliente son datos cargados por usuarios.
                const item = document.createElement('button');
                item.type = 'button';
                item.setAttribute('role', 'option');
                item.className = 'flex w-full items-start gap-3 border-t border-ink-100 px-3 py-3 text-left first:border-t-0 hover:bg-ink-50 focus:bg-ink-50 focus:outline-none';
                const text = document.createElement('span');
                text.className = 'min-w-0 flex-1';
                const name = document.createElement('span');
                name.className = 'block font-semibold text-ink';
                name.textContent = b.name;
                const meta = document.createElement('span');
                meta.className = 'block text-sm text-ink-700';
                meta.textContent = [b.address, b.place, b.client].filter(Boolean).join(' · ');
                text.append(name, meta);
                item.appendChild(text);
                item.addEventListener('click', () => choose(b));
                results.appendChild(item);
            });
            results.classList.remove('hidden');
            search.setAttribute('aria-expanded', 'true');
        };
        const filter = () => {
            const words = normalize(search.value).split(/\s+/).filter(Boolean);
            show(words.length ? buildings.filter((b) => words.every((w) => b.haystack.includes(w))).slice(0, 15) : buildings.slice(0, 12));
        };
        search.addEventListener('focus', filter);
        search.addEventListener('input', filter);
        document.addEventListener('click', (e) => { if (!picker.contains(e.target)) hide(); });
        function choose(b, unit = null) {
            buildingSelect.value = b.id;
            document.getElementById('selectedName').textContent = b.name;
            document.getElementById('selectedMeta').textContent = [b.address, b.place, b.client].filter(Boolean).join(' · ');
            picker.classList.add('hidden');
            selected.classList.remove('hidden');
            selected.classList.add('flex');
            hide();
            const openBox = document.getElementById('openOrders');
            const openList = document.getElementById('openOrdersList');
            openList.innerHTML = '';
            (b.open || []).forEach((o) => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = o.url;
                a.className = 'underline';
                a.textContent = o.label; // texto cargado por usuarios: siempre textContent
                li.appendChild(a);
                openList.appendChild(li);
            });
            openBox.classList.toggle('hidden', !(b.open || []).length);
            elevatorSelect.innerHTML = '';
            elevatorSelect.disabled = !b.units.length;
            if (!b.units.length) { elevatorSelect.add(new Option('Este edificio no tiene equipos cargados', '')); return; }
            if (b.units.length > 1) elevatorSelect.add(new Option('Elegí el equipo', ''));
            b.units.forEach((u) => elevatorSelect.add(new Option(u, u)));
            if (unit && b.units.includes(unit)) elevatorSelect.value = unit;
        }
        document.getElementById('changeBuilding').addEventListener('click', () => {
            buildingSelect.value = '';
            selected.classList.add('hidden');
            selected.classList.remove('flex');
            picker.classList.remove('hidden');
            elevatorSelect.innerHTML = '<option value="">Elegí primero el edificio</option>';
            elevatorSelect.disabled = true;
            document.getElementById('openOrders').classList.add('hidden');
            search.value = '';
            search.focus();
        });
        const previous = buildings.find((b) => String(b.id) === String(buildingSelect.value));
        if (previous) choose(previous, @json(old('elevator_number')));
    }

    /* ---------- Fotos: se suman, se pueden quitar y se achican antes de subir ---------- */
    const MAX_PHOTOS = {{ \App\Services\Reports\ReportPhotoService::MAX_PHOTOS }};
    const photoPicker = document.getElementById('photoPicker');
    const photoInput = document.getElementById('photoInput');
    const photoList = document.getElementById('photoList');
    const photoButton = document.getElementById('photoButton');
    let photos = [];
    const render = () => {
        photoList.innerHTML = '';
        photos.forEach((file, index) => {
            const cell = document.createElement('div');
            cell.className = 'relative';
            const img = document.createElement('img');
            img.className = 'aspect-square w-full rounded-xl border border-ink-100 bg-ink-50 object-cover';
            img.alt = 'Foto ' + (index + 1);
            const url = URL.createObjectURL(file);
            img.onload = img.onerror = () => URL.revokeObjectURL(url);
            img.src = url;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'absolute right-1.5 top-1.5 flex h-8 w-8 items-center justify-center rounded-full bg-ink/80 text-white';
            remove.setAttribute('aria-label', 'Quitar foto ' + (index + 1));
            remove.textContent = '×';
            remove.addEventListener('click', () => { photos.splice(index, 1); render(); });
            cell.append(img, remove);
            photoList.append(cell);
        });
        photoButton.classList.toggle('hidden', photos.length >= MAX_PHOTOS);
    };
    photoPicker.addEventListener('change', () => {
        const images = Array.from(photoPicker.files).filter((f) => !f.type || f.type.startsWith('image/'));
        photos = photos.concat(images.slice(0, MAX_PHOTOS - photos.length));
        photoPicker.value = '';
        render();
    });
    async function shrink(file) {
        try {
            if (!window.createImageBitmap) return file;
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            return blob && blob.size < file.size ? new File([blob], (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
        } catch (e) { return file; }
    }

    /* ---------- Envío con progreso (el mismo token: un reenvío no duplica) ---------- */
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        formError.classList.add('hidden');
        if (buildingSelect && !buildingSelect.value) { showError('Elegí el edificio.'); return; }
        if (!elevatorSelect.value) { showError('Elegí el equipo.'); elevatorSelect.focus(); return; }
        if (document.getElementById('description').value.trim().length < 5) { showError('Contá el problema en al menos 5 caracteres.'); return; }
        if (navigator.onLine === false) { showError('No hay conexión. No se perdió nada: cuando tengas señal, tocá el botón de nuevo.'); return; }

        submit.disabled = true;
        submit.textContent = 'Enviando…';
        try {
            const transfer = new DataTransfer();
            for (const file of photos) transfer.items.add(await shrink(file));
            photoInput.files = transfer.files;
        } catch (e) {}

        const progress = document.getElementById('uploadProgress');
        const bar = document.getElementById('uploadBar');
        progress.classList.remove('hidden');
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 100) + '%'; };
        const reset = () => { submit.disabled = false; submit.textContent = label; progress.classList.add('hidden'); };
        xhr.onload = () => {
            let body = {};
            try { body = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300 && body.redirect) { window.location.assign(body.redirect); return; }
            reset();
            if (xhr.status === 422) showError(body.message || 'Revisá los datos.');
            else if (xhr.status === 419) showError('La sesión venció. Recargá la página e intentá de nuevo.');
            else if (xhr.status === 403) showError('No podés registrar trabajos en esta orden.');
            else showError('No se pudo enviar. Probá de nuevo en un momento: no se va a duplicar.');
        };
        xhr.onerror = () => { reset(); showError('Se cortó la conexión. Probá de nuevo cuando tengas señal: no se va a duplicar.'); };
        xhr.send(new FormData(form));
    });
});
</script>
</x-app-layout>
