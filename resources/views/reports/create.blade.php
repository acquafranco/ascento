{{--
    Nuevo reporte del técnico.
    - Edificio: buscador por nombre, calle y altura, localidad, barrio o cliente
      (varias palabras, sin importar acentos). Al tocar el campo aparecen
      primero los edificios asignados al técnico. El servidor vuelve a validar
      que el edificio sea de la empresa, esté activo y que el equipo exista.
    - Fotos (hasta 6, se achican en el celular) y un video (según el plan).
    - Envío con barra de progreso; los errores se muestran en pantalla.
--}}
@php
    use App\Support\TechNav;
    $videoAllowed = \App\Services\Reports\ReportVideoService::allowedFor(auth()->user()->company);
@endphp
<x-app-layout>
<div class="t-page max-w-xl lg:max-w-xl">
    <a href="{{ route('reports.index', ['company' => $company->slug]) }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Reportes
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">Nuevo reporte</h1>
        <p class="t-sub">Contale a la oficina qué falla y dónde.</p>
    </header>

    @if (! empty($planUsage))
        <div class="{{ $planUsage['warning'] ? 't-alert-warning' : 't-card px-4 py-3 text-sm text-ink-700' }} mb-4 flex items-center justify-between gap-3" data-plan-usage>
            <span><strong>{{ $planUsage['label'] }}</strong></span>
            @if ($planUsage['warning'])<span>{{ $planUsage['warning'] }}</span>@endif
        </div>
    @endif

    @if ($errors->any())
        <div class="t-alert-error mb-4" role="alert">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('reports.store', ['company' => $company->slug]) }}" enctype="multipart/form-data" class="space-y-5" novalidate
          data-draft="reporte.{{ auth()->id() }}.{{ $company->id }}" data-draft-xhr @if($errors->any()) data-draft-restore="off" @endif>
        @csrf
        @include('partials.draft-notice', ['media' => 'Las fotos y el video se vuelven a elegir.'])

        {{-- 1. Edificio --}}
        <section class="t-card p-4">
            <label for="buildingSearch" class="t-label">Edificio</label>
            <div id="buildingPicker">
                <input type="search" id="buildingSearch" class="t-input" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="buildingResults"
                       placeholder="Nombre, calle y altura, barrio o cliente">
                <p class="mt-1.5 text-xs text-ink-500">Tocá el campo para ver tus edificios asignados.</p>
                <div id="buildingResults" role="listbox" class="mt-2 hidden max-h-80 overflow-y-auto rounded-xl border border-ink-100 bg-white"></div>
            </div>
            <input type="hidden" id="buildingSelect" name="building_id" data-draft-field value="{{ old('building_id') }}">

            <div id="selectedBuilding" class="hidden items-start gap-3 rounded-xl border border-brand-200 bg-brand-50 px-3 py-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('check') !!}</svg>
                <div class="min-w-0 flex-1">
                    <div id="selectedName" class="font-semibold text-ink"></div>
                    <div id="selectedMeta" class="text-sm text-ink-700"></div>
                </div>
                <button type="button" id="changeBuilding" class="t-btn-secondary t-btn-sm shrink-0">Cambiar</button>
            </div>

            <label for="elevator_number" class="t-label mt-4">Equipo</label>
            <select id="elevator_number" name="elevator_number" class="t-input" disabled data-draft-field>
                <option value="">Elegí primero el edificio</option>
            </select>
        </section>

        {{-- 2. Qué pasa --}}
        <section class="t-card space-y-4 p-4">
            <div>
                <label class="t-label" for="component">¿Qué parte falla? <span class="font-normal text-ink-500">(opcional)</span></label>
                <select id="component" name="component" class="t-input" data-draft-field>
                    <option value="">No sé / no aplica</option>
                    @foreach(\App\Support\ElevatorComponents::LIST as $value => $label)
                        <option value="{{ $value }}" @selected(old('component') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="t-label" for="description">Descripción</label>
                <textarea id="description" name="description" data-draft-field rows="4" maxlength="5000" class="t-input" placeholder="Qué encontraste, desde cuándo, qué probaste">{{ old('description') }}</textarea>
            </div>
            <fieldset>
                <legend class="t-label">Prioridad</legend>
                <div class="grid grid-cols-4 gap-2">
                    @foreach(['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'] as $value => $label)
                        <label class="relative">
                            <input type="radio" name="priority" value="{{ $value }}" data-draft-field class="peer sr-only" @checked(old('priority', 'baja') === $value)>
                            <span class="flex min-h-[44px] cursor-pointer items-center justify-center rounded-xl border border-ink-100 bg-white text-sm font-semibold text-ink-700 peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-brand">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>

        {{-- 3. Fotos y video --}}
        <section class="t-card space-y-4 p-4">
            <div>
                <div class="t-label">Fotos <span class="font-normal text-ink-500">(opcional, hasta {{ \App\Services\Reports\ReportPhotoService::MAX_PHOTOS }})</span></div>
                <label id="photoButton" class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-xl border border-dashed border-ink-300 bg-ink-50 px-4 py-3 active:bg-ink-100">
                    <svg class="h-6 w-6 shrink-0 text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('camera') !!}</svg>
                    <span>
                        <span class="block font-semibold text-ink">Agregar foto</span>
                        <span class="block text-xs text-ink-500">Sacá una foto o elegí de la galería</span>
                    </span>
                    {{-- Selector: cada vez que se usa, las fotos se SUMAN a la lista. --}}
                    <input id="photoPicker" type="file" accept="image/*" multiple class="hidden">
                </label>
                {{-- Lo que se envía (lo arma el script con las fotos elegidas). --}}
                <input id="photoInput" type="file" name="photos[]" multiple class="hidden">
                <div id="photoCount" class="mt-2 hidden text-sm font-semibold text-ink-700"></div>
                <div id="photoList" class="mt-2 grid grid-cols-3 gap-2"></div>
            </div>

            @if($videoAllowed)
                {{-- Un video por reporte (Profesional y Empresa). Duración y tamaño se validan también en el servidor. --}}
                <div>
                    <div class="t-label">Video <span class="font-normal text-ink-500">(opcional, hasta {{ config('media.video_max_seconds') }} s y {{ config('media.video_max_mb') }} MB)</span></div>
                    <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-xl border border-dashed border-ink-300 bg-ink-50 px-4 py-3 active:bg-ink-100">
                        <svg class="h-6 w-6 shrink-0 text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3z"/></svg>
                        <span class="min-w-0">
                            <span id="videoLabel" class="block truncate font-semibold text-ink">Agregar video</span>
                            <span class="block text-xs text-ink-500">Grabá uno corto donde se vea la falla</span>
                        </span>
                        <input id="videoInput" type="file" name="video" accept="video/mp4,video/quicktime,video/webm" class="hidden">
                    </label>
                    <button type="button" id="videoClear" class="mt-1.5 hidden text-sm font-semibold text-red-600">Quitar video</button>
                    @error('video')<p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
        </section>

        {{-- Errores y progreso de la subida (sin ventanas emergentes). --}}
        <div id="formError" role="alert" class="t-alert-error hidden"></div>
        <div id="uploadProgress" class="hidden" aria-live="polite">
            <div class="h-2 w-full overflow-hidden rounded-full bg-ink-100">
                <div id="uploadBar" class="h-full w-0 rounded-full bg-brand transition-all"></div>
            </div>
            <p id="uploadLabel" class="mt-2 text-sm font-semibold text-ink-700">Subiendo…</p>
        </div>

        <button id="submitReport" class="t-btn-primary w-full text-base">Enviar reporte</button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    /* ---------- Edificio y equipo ---------- */
    const buildings = @json($buildings);
    const buildingSelect = document.getElementById('buildingSelect');
    const elevatorSelect = document.getElementById('elevator_number');
    const search = document.getElementById('buildingSearch');
    const results = document.getElementById('buildingResults');
    const picker = document.getElementById('buildingPicker');
    const selected = document.getElementById('selectedBuilding');
    const oldUnit = @json(old('elevator_number')) ?? elevatorSelect.dataset.draftValue ?? null; // o el del borrador

    const normalize = (text) => (text ?? '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    buildings.forEach((b) => { b.haystack = normalize([b.name, b.address, b.place, b.client].join(' ')); });

    const showResults = (list, title) => {
        results.innerHTML = '';
        if (title) {
            const head = document.createElement('div');
            head.className = 'px-3 pb-1 pt-2.5 text-[11px] font-bold uppercase tracking-wider text-ink-500';
            head.textContent = title;
            results.appendChild(head);
        }
        if (!list.length) {
            const empty = document.createElement('div');
            empty.className = 'px-3 py-3 text-sm text-ink-500';
            empty.textContent = 'No encontramos edificios. Probá con la calle o el barrio.';
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
            const address = document.createElement('span');
            address.className = 'block text-sm text-ink-700';
            address.textContent = b.address ?? '';
            const meta = document.createElement('span');
            meta.className = 'block text-xs text-ink-500';
            meta.textContent = [b.place, b.client].filter(Boolean).join(' · ');
            text.append(name, address, meta);
            item.appendChild(text);
            if (b.mine) {
                const chip = document.createElement('span');
                chip.className = 't-chip-progress shrink-0';
                chip.textContent = 'Asignado';
                item.appendChild(chip);
            }
            item.addEventListener('click', () => choose(b));
            results.appendChild(item);
        });
        results.classList.remove('hidden');
        search.setAttribute('aria-expanded', 'true');
    };

    const hideResults = () => {
        results.classList.add('hidden');
        search.setAttribute('aria-expanded', 'false');
    };

    const filter = () => {
        const words = normalize(search.value).split(/\s+/).filter(Boolean);
        if (!words.length) {
            const mine = buildings.filter((b) => b.mine);
            showResults(mine.length ? mine.slice(0, 12) : buildings.slice(0, 12), mine.length ? 'Tus edificios asignados' : 'Edificios');
            return;
        }
        showResults(buildings.filter((b) => words.every((w) => b.haystack.includes(w))).slice(0, 15), null);
    };

    search.addEventListener('focus', filter);
    search.addEventListener('input', filter);
    search.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); results.querySelector('button')?.focus(); }
        if (e.key === 'Escape') hideResults();
    });
    results.addEventListener('keydown', (e) => {
        const items = [...results.querySelectorAll('button')];
        const i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); items[Math.min(i + 1, items.length - 1)]?.focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); (i <= 0 ? search : items[i - 1]).focus(); }
        if (e.key === 'Escape') { hideResults(); search.focus(); }
    });
    document.addEventListener('click', (e) => { if (!picker.contains(e.target)) hideResults(); });

    function choose(b, unit = null) {
        buildingSelect.value = b.id;
        buildingSelect.dispatchEvent(new Event('change', { bubbles: true })); // borrador
        document.getElementById('selectedName').textContent = b.name;
        document.getElementById('selectedMeta').textContent = [b.address, b.place, b.client].filter(Boolean).join(' · ');
        picker.classList.add('hidden');
        selected.classList.remove('hidden');
        selected.classList.add('flex');
        hideResults();

        elevatorSelect.innerHTML = '';
        elevatorSelect.disabled = false;
        if (!b.units.length) {
            elevatorSelect.innerHTML = '<option value="">Este edificio no tiene equipos cargados</option>';
            elevatorSelect.disabled = true;
            return;
        }
        if (b.units.length > 1) elevatorSelect.add(new Option('Elegí el equipo', ''));
        b.units.forEach((label) => elevatorSelect.add(new Option(label, label)));
        if (unit && b.units.includes(unit)) elevatorSelect.value = unit;
    }

    document.getElementById('changeBuilding').addEventListener('click', () => {
        buildingSelect.value = '';
        selected.classList.add('hidden');
        selected.classList.remove('flex');
        picker.classList.remove('hidden');
        elevatorSelect.innerHTML = '<option value="">Elegí primero el edificio</option>';
        elevatorSelect.disabled = true;
        search.value = '';
        search.focus();
    });

    // Volver con errores (sin JavaScript de envío): se recupera lo elegido.
    const previous = buildings.find((b) => String(b.id) === String(buildingSelect.value));
    if (previous) choose(previous, oldUnit);

    /*
    | Fotos: se suman de a una o varias, se pueden quitar, y antes de enviar se
    | achican en el celular (2000 px, JPEG) para que suban rápido con datos
    | móviles. Si el navegador no puede (p. ej. HEIC fuera de Safari), se manda
    | la original: el servidor igual la valida y la procesa.
    */
    const MAX_PHOTOS = {{ \App\Services\Reports\ReportPhotoService::MAX_PHOTOS }};
    const MAX_PHOTO_MB = {{ (int) (\App\Services\Reports\ReportPhotoService::MAX_KB / 1024) }};
    const MAX_SIDE = 2000;
    const photoPicker = document.getElementById('photoPicker');
    const photoInput = document.getElementById('photoInput');
    const photoList = document.getElementById('photoList');
    const photoCount = document.getElementById('photoCount');
    const photoButton = document.getElementById('photoButton');
    const form = photoInput.closest('form');
    const submit = document.getElementById('submitReport');
    const formError = document.getElementById('formError');
    let photos = [];

    const showError = (message) => {
        formError.textContent = message;
        formError.classList.remove('hidden');
        formError.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };

    function render() {
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
            remove.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
            remove.addEventListener('click', () => { photos.splice(index, 1); render(); });
            cell.append(img, remove);
            photoList.append(cell);
        });
        photoCount.textContent = photos.length + ' de ' + MAX_PHOTOS + ' fotos';
        photoCount.classList.toggle('hidden', photos.length === 0);
        photoButton.classList.toggle('hidden', photos.length >= MAX_PHOTOS);
    }

    photoPicker.addEventListener('change', () => {
        formError.classList.add('hidden');
        const chosen = Array.from(photoPicker.files);
        const images = chosen.filter((f) => !f.type || f.type.startsWith('image/'));
        if (images.length < chosen.length) showError('Algunos archivos no son imágenes y no se agregaron.');
        const room = MAX_PHOTOS - photos.length;
        if (images.length > room) showError('Podés adjuntar hasta ' + MAX_PHOTOS + ' fotos. Se agregaron las primeras ' + room + '.');
        photos = photos.concat(images.slice(0, room));
        photoPicker.value = '';
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

    /* ---------- Video ---------- */
    const videoInput = document.getElementById('videoInput');
    const videoClear = document.getElementById('videoClear');
    videoInput?.addEventListener('change', () => {
        const file = videoInput.files?.[0];
        document.getElementById('videoLabel').textContent = file
            ? file.name + ' (' + (file.size / 1048576).toFixed(1) + ' MB)'
            : 'Agregar video';
        videoClear.classList.toggle('hidden', !file);
    });
    videoClear?.addEventListener('click', () => {
        videoInput.value = '';
        videoInput.dispatchEvent(new Event('change'));
    });

    /* ---------- Envío ---------- */
    const progress = document.getElementById('uploadProgress');
    const bar = document.getElementById('uploadBar');
    const progressLabel = document.getElementById('uploadLabel');
    const resetSubmit = () => {
        submit.disabled = false;
        submit.textContent = 'Enviar reporte';
        progress.classList.add('hidden');
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return; // evita el doble envío
        formError.classList.add('hidden');

        // Lo básico antes de subir nada (el servidor lo vuelve a validar).
        if (!buildingSelect.value) { showError('Elegí el edificio.'); search.focus(); return; }
        if (!elevatorSelect.value) { showError('Elegí el equipo.'); elevatorSelect.focus(); return; }
        if (document.getElementById('description').value.trim().length < 5) { showError('Escribí una descripción de al menos 5 caracteres.'); document.getElementById('description').focus(); return; }
        if (navigator.onLine === false) {
            showError('No hay conexión a internet. El reporte NO se envió, pero no se perdió nada: esperá a tener señal y tocá "Enviar reporte" de nuevo.');
            return;
        }

        // Video: tamaño y duración antes de subir (el servidor lo vuelve a validar).
        const video = videoInput?.files?.[0];
        if (video) {
            const maxMb = {{ (int) config('media.video_max_mb') }}, maxSeconds = {{ (int) config('media.video_max_seconds') }};
            if (video.size > maxMb * 1024 * 1024) {
                showError('El video pesa ' + (video.size / 1048576).toFixed(1) + ' MB. El máximo es ' + maxMb + ' MB: grabá uno más corto.');
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
                showError('El video dura ' + Math.round(seconds) + ' segundos. El máximo es ' + maxSeconds + ' segundos.');
                return;
            }
        }

        submit.disabled = true;
        submit.textContent = photos.length ? 'Preparando fotos…' : 'Enviando…';

        try {
            const transfer = new DataTransfer();
            for (const file of photos) {
                const ready = await shrink(file);
                if (ready.size > MAX_PHOTO_MB * 1024 * 1024) {
                    resetSubmit();
                    showError('Una foto pesa más de ' + MAX_PHOTO_MB + ' MB y no se pudo achicar. Quitala o sacá otra.');
                    return;
                }
                transfer.items.add(ready);
            }
            photoInput.files = transfer.files;
        } catch (e) {
            // Navegador muy viejo, sin DataTransfer: no se pueden adjuntar las
            // fotos elegidas. Se avisa en vez de mandar el reporte sin ellas.
            if (photos.length && !confirm('Este navegador no permite adjuntar las fotos. ¿Enviar el reporte sin fotos?')) {
                resetSubmit();
                return;
            }
        }

        // Envío con progreso real de la subida. El servidor responde JSON:
        // 422 con el error, o la URL a la que ir cuando el reporte se guardó.
        submit.textContent = 'Enviando…';
        bar.style.width = '0%';
        progressLabel.textContent = 'Subiendo… 0%';
        progress.classList.remove('hidden');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = (e) => {
            if (!e.lengthComputable) return;
            const pct = Math.round(e.loaded / e.total * 100);
            bar.style.width = pct + '%';
            progressLabel.textContent = pct < 100 ? 'Subiendo… ' + pct + '%' : 'Guardando el reporte…';
        };
        xhr.onload = () => {
            let body = {};
            try { body = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300 && body.redirect) {
                progressLabel.textContent = 'Listo. Abriendo tus reportes…';
                window.ascentoDrafts?.clear(form.dataset.draft);
                window.location.assign(body.redirect);
                return;
            }
            resetSubmit();
            if (xhr.status === 422) showError(body.message || 'Revisá los datos del reporte.');
            else if (xhr.status === 413) showError('Los archivos pesan más de lo que acepta el servidor. Probá con un video más corto o menos fotos.');
            else if (xhr.status === 419) showError('La sesión venció. Recargá la página e intentá de nuevo.');
            else showError('No se pudo enviar el reporte. Probá de nuevo en un momento.');
        };
        xhr.onerror = () => {
            resetSubmit();
            showError('Se cortó la conexión. El reporte NO se envió: probá de nuevo cuando tengas señal.');
        };
        xhr.send(new FormData(form));
    });
});
</script>
</x-app-layout>
