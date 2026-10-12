{{--
    «Fotos del trabajo» en el celular del técnico: dentro de la visita
    (mantenimiento o inspección) y dentro de la orden de trabajo.

    1. Elegir «Antes» o «Después».
    2. Elegir la parte (sección), si hay más de una.
    3. «Tomar foto» (abre la cámara si el navegador lo permite: capture) o
       «Elegir de la galería» (varias a la vez).
    Cada foto se sube de a una y queda guardada al instante, con su identidad
    (client_key): «Reintentar» no la duplica. Sin las fotos habilitadas para
    el tipo de trabajo, solo se ven las que ya había (sin botones).

    Variables: $company, $target (PhotoTarget), $photos, $sections,
    $elevators, $canUpload.
--}}
@php
    $slug = $company->slug;
    $me = auth()->id();
    $stages = \App\Models\VisitPhoto::STAGES;
    $maxGroup = \App\Models\VisitPhoto::MAX_PER_GROUP;
    $grouped = $photos->groupBy(fn ($p) => $p->photo_section_id.'|'.$p->stage);
    $targetField = $target->type === 'work_order' ? 'work_order_id' : 'visit_id';
    $targetId = $target->type === 'work_order' ? $target->workOrder->id : $target->visit->id;
    $activeSections = $sections->filter(fn ($s) => $s->is_active)->values();
@endphp
<section class="t-card p-4" id="visitPhotos" data-work-photos-manager
         data-upload-url="{{ route('visit-photos.store', ['company' => $slug]) }}"
         data-section-url="{{ route('visit-photos.section', ['company' => $slug]) }}"
         data-target-field="{{ $targetField }}" data-target-id="{{ $targetId }}"
         data-max-group="{{ $maxGroup }}" data-max-total="{{ \App\Models\VisitPhoto::MAX_PER_VISIT }}"
         data-can-upload="{{ $canUpload ? '1' : '0' }}">
    <h2 class="flex items-center gap-2 font-bold text-ink">
        <svg class="h-5 w-5 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Fotos del trabajo <span class="text-xs font-normal text-ink-500">(opcional)</span>
    </h2>

    @if($canUpload && $activeSections->isNotEmpty())
        <div class="mt-3 space-y-3 rounded-xl bg-ink-50 p-3" data-photo-composer>
            <fieldset>
                <legend class="mb-1.5 text-xs font-bold uppercase tracking-wider text-ink-500">¿Antes o después del trabajo?</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach($stages as $stage => $stageLabel)
                        <label class="relative">
                            <input type="radio" name="photo-stage" value="{{ $stage }}" class="peer sr-only" data-stage-choice @checked($stage === 'before')>
                            <span class="flex min-h-[48px] cursor-pointer items-center justify-center rounded-xl border-2 border-ink-200 bg-white px-3 text-sm font-bold text-ink-700 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $stageLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if($activeSections->count() > 1)
                <div>
                    <label for="photo-composer-section" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-ink-500">¿Qué parte?</label>
                    <select id="photo-composer-section" class="t-input py-2.5" data-composer-section>
                        @foreach($activeSections as $section)
                            <option value="{{ $section->id }}">{{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" value="{{ $activeSections->first()->id }}" data-composer-section>
            @endif

            <div class="grid grid-cols-2 gap-2">
                {{-- capture="environment": la mayoría de los celulares abren la cámara trasera directamente; si el navegador no lo soporta, ofrece elegir. --}}
                <label class="t-btn-primary min-h-[52px] w-full cursor-pointer" data-take-photo>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Tomar foto
                    <input type="file" accept="image/*" capture="environment" class="sr-only" data-file aria-label="Tomar foto" data-source="camera">
                </label>
                <label class="t-btn-secondary min-h-[52px] w-full cursor-pointer" data-pick-photo>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                    Elegir de la galería
                    <input type="file" accept="image/*" multiple class="sr-only" data-file aria-label="Elegir de la galería" data-source="gallery">
                </label>
            </div>
            <p class="text-xs text-ink-500" data-composer-hint aria-live="polite"></p>
            <p id="photos-status" class="hidden text-sm font-semibold" role="status" aria-live="polite"></p>
            <p class="text-xs text-ink-500">Hasta {{ $maxGroup }} fotos por parte en «Antes» y {{ $maxGroup }} en «Después» ({{ \App\Models\VisitPhoto::MAX_PER_VISIT }} en total). Cada foto se guarda al subirla. Las ve la oficina, nunca el cliente.</p>
        </div>
    @elseif(! $canUpload)
        <p class="mt-1 text-xs text-ink-500" data-photos-disabled>La carga de fotos no está habilitada para este tipo de trabajo.{{ $photos->isNotEmpty() ? ' Estas son las fotos que ya se habían cargado.' : '' }}</p>
    @endif

    <div class="mt-3 space-y-2">
        @foreach($sections as $section)
            @php
                $sectionPhotos = $photos->where('photo_section_id', $section->id);
                $count = $sectionPhotos->count();
            @endphp
            @continue(! $canUpload && $count === 0)
            @continue(! $section->is_active && $count === 0)
            <details class="rounded-xl border border-ink-100" data-photo-section="{{ $section->id }}" @if($count) open @endif>
                <summary class="flex min-h-[48px] cursor-pointer select-none items-center justify-between gap-2 px-3 font-semibold text-ink">
                    <span>{{ $section->name }}</span>
                    <span class="text-xs font-normal text-ink-500" data-section-count>{{ $count ? $count.' '.($count === 1 ? 'foto' : 'fotos') : 'Sin fotos' }}</span>
                </summary>
                <div class="space-y-3 border-t border-ink-100 px-3 pb-3 pt-3">
                    @foreach($stages as $stage => $stageLabel)
                        @php $groupPhotos = $grouped->get($section->id.'|'.$stage, collect()); @endphp
                        <div data-photo-group data-section="{{ $section->id }}" data-stage="{{ $stage }}" data-section-name="{{ $section->name }}" data-stage-label="{{ $stageLabel }}">
                            <div class="mb-1.5 flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider {{ $stage === 'before' ? 'text-ink-700' : 'text-emerald-700' }}">{{ $stageLabel }}</span>
                                <span class="text-xs text-ink-500" data-group-count>{{ $groupPhotos->count() }} de {{ $maxGroup }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-2" data-thumbs>
                                @foreach($groupPhotos as $photo)
                                    <div class="relative aspect-square overflow-hidden rounded-lg bg-ink-50" data-photo-id="{{ $photo->id }}" data-state="done">
                                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener" aria-label="Ver foto de {{ $section->name }}, {{ mb_strtolower($stageLabel) }}">
                                            <img src="{{ $photo->thumbUrl() }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                        </a>
                                        @if($canUpload && (int) $photo->uploaded_by === (int) $me)
                                            <button type="button" class="absolute right-1 top-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-bold text-red-700 shadow" data-remove
                                                    data-delete-url="{{ route('visit-photos.destroy', ['company' => $slug, 'visitPhoto' => $photo->id]) }}" aria-label="Quitar foto">✕</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    @php
                        $sectionNote = $sectionPhotos->pluck('notes')->filter()->first();
                        $sectionElevator = $sectionPhotos->pluck('elevator_id')->filter()->first();
                    @endphp
                    @if($canUpload && $section->is_active)
                        @if($elevators->count() > 1)
                            <div>
                                <label class="mb-1 block text-xs text-ink-500" for="photo-elevator-{{ $section->id }}">Equipo (opcional)</label>
                                <select id="photo-elevator-{{ $section->id }}" class="t-input py-2.5" data-section-elevator>
                                    <option value="">General del edificio</option>
                                    @foreach($elevators as $elevator)
                                        <option value="{{ $elevator->id }}" @selected((int) $sectionElevator === (int) $elevator->id)>{{ $elevator->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div>
                            <label class="mb-1 block text-xs text-ink-500" for="photo-notes-{{ $section->id }}">Observación de {{ $section->name }} (opcional)</label>
                            <input type="text" id="photo-notes-{{ $section->id }}" maxlength="500" value="{{ $sectionNote }}" class="t-input py-2.5" data-section-notes>
                        </div>
                    @else
                        @if($sectionNote || $sectionElevator)
                            <p class="text-xs text-ink-500">{{ collect([$sectionPhotos->pluck('elevator.label')->filter()->first(), $sectionNote])->filter()->implode(' · ') }}</p>
                        @endif
                    @endif
                    @if($count)
                        <p class="text-[11px] text-ink-500">{{ $sectionPhotos->map(fn ($p) => $p->uploader?->name)->filter()->unique()->implode(', ') }} · {{ $sectionPhotos->max('created_at')?->format('d/m/Y H:i') }}</p>
                    @endif
                </div>
            </details>
        @endforeach
        @if(! $canUpload && $photos->isEmpty())
            <p class="text-sm text-ink-500" data-no-photos>Este trabajo no tiene fotos.</p>
        @endif
    </div>
</section>

@if($canUpload)
<script>
/* Fotos: se suben de a una (en fila), achicadas en el celular. Cada foto
   lleva su identidad: si la subida llegó pero la respuesta no, «Reintentar»
   recibe la misma foto en vez de duplicarla. */
(() => {
    const root = document.getElementById('visitPhotos');
    if (!root) return;
    const MAX_SIDE = 2000;
    const maxGroup = parseInt(root.dataset.maxGroup, 10);
    const maxTotal = parseInt(root.dataset.maxTotal, 10);
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const status = document.getElementById('photos-status');
    const hint = root.querySelector('[data-composer-hint]');
    const sectionPicker = root.querySelector('[data-composer-section]');
    const queue = [];
    let busy = false;
    const tiles = (sel) => root.querySelectorAll('[data-thumbs] > div' + (sel || ''));
    const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' };
    function say(text, kind) {
        if (!status) return;
        status.textContent = text;
        status.className = 'text-sm font-semibold ' + (kind === 'error' ? 'text-red-700' : (kind === 'ok' ? 'text-emerald-700' : 'text-ink-500'));
        status.classList.toggle('hidden', !text);
    }
    function stage() {
        return root.querySelector('[data-stage-choice]:checked')?.value || 'before';
    }
    // Grupo (parte + antes/después) al que van las fotos nuevas.
    function chosenGroup() {
        return root.querySelector('[data-photo-group][data-section="' + (sectionPicker?.value || '') + '"][data-stage="' + stage() + '"]');
    }
    function composer() {
        const group = chosenGroup();
        if (!group || !hint) return;
        const n = group.querySelectorAll('[data-thumbs] > div').length;
        const full = n >= maxGroup || tiles().length >= maxTotal;
        hint.textContent = full
            ? (tiles().length >= maxTotal ? 'Llegaste al máximo de ' + maxTotal + ' fotos.' : group.dataset.sectionName + ' · ' + group.dataset.stageLabel + ' ya tiene ' + maxGroup + ' fotos (el máximo). Elegí otra parte o quitá una.')
            : 'Se guarda en: ' + group.dataset.sectionName + ' · ' + group.dataset.stageLabel + ' (' + n + ' de ' + maxGroup + ').';
        root.querySelectorAll('[data-take-photo], [data-pick-photo]').forEach((label) => {
            label.classList.toggle('opacity-50', full);
            label.classList.toggle('pointer-events-none', full);
            label.setAttribute('aria-disabled', full ? 'true' : 'false');
            label.querySelector('input').disabled = full;
        });
    }
    function refresh(group) {
        const n = group.querySelectorAll('[data-thumbs] > div').length;
        group.querySelector('[data-group-count]').textContent = n + ' de ' + maxGroup;
        const section = group.closest('[data-photo-section]');
        const total = section.querySelectorAll('[data-thumbs] > div').length;
        section.querySelector('[data-section-count]').textContent = total ? total + (total === 1 ? ' foto' : ' fotos') : 'Sin fotos';
        composer();
    }
    function newKey() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    }
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
    function sectionMeta(group) {
        const section = group.closest('[data-photo-section]');
        return {
            notes: section.querySelector('[data-section-notes]')?.value || '',
            elevator: section.querySelector('[data-section-elevator]')?.value || '',
        };
    }
    function tile(group, file) {
        const el = document.createElement('div');
        el.className = 'relative aspect-square overflow-hidden rounded-lg bg-ink-50';
        el.dataset.state = 'uploading';
        el.innerHTML = '<img alt="" class="h-full w-full object-cover opacity-60">'
            + '<span class="absolute inset-x-0 bottom-0 bg-white/90 px-1 py-0.5 text-center text-[11px] font-semibold text-ink-700" data-label>Subiendo…</span>'
            + '<button type="button" class="absolute right-1 top-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-bold text-red-700 shadow" data-remove aria-label="Quitar foto">✕</button>';
        try { el.querySelector('img').src = URL.createObjectURL(file); } catch (e) {}
        el._file = file;
        el._group = group;
        el._key = newKey();
        group.querySelector('[data-thumbs]').append(el);
        refresh(group);
        return el;
    }
    function setFailed(el, message) {
        el.dataset.state = 'failed';
        const label = el.querySelector('[data-label]');
        label.className = 'absolute inset-x-0 bottom-0 bg-red-50 px-1 py-0.5 text-center text-[11px] font-bold text-red-700';
        label.innerHTML = '';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Reintentar';
        retry.className = 'underline';
        retry.dataset.retry = '';
        retry.addEventListener('click', () => { el.dataset.state = 'uploading'; label.className = 'absolute inset-x-0 bottom-0 bg-white/90 px-1 py-0.5 text-center text-[11px] font-semibold text-ink-700'; label.textContent = 'Subiendo…'; say('Subiendo…'); enqueue(el); });
        label.append(retry);
        say(message, 'error');
    }
    async function upload(el) {
        const group = el._group;
        const meta = sectionMeta(group);
        const body = new FormData();
        body.append(root.dataset.targetField, root.dataset.targetId);
        body.append('photo_section_id', group.dataset.section);
        body.append('stage', group.dataset.stage);
        body.append('client_key', el._key);
        body.append('notes', meta.notes);
        if (meta.elevator) body.append('elevator_id', meta.elevator);
        body.append('photo', el._file);
        try {
            if (navigator.onLine === false) throw new Error('offline');
            const res = await fetch(root.dataset.uploadUrl, { method: 'POST', body, headers, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const msg = data.errors ? Object.values(data.errors).flat()[0] : (res.status === 413 ? 'La foto es demasiado pesada.' : (data.message || 'No se pudo subir la foto.'));
                if (res.status === 422 && data.errors && /hasta|máximo|desactivada|ya no existe/.test(msg)) { el.remove(); refresh(group); say(msg, 'error'); return; }
                return setFailed(el, msg + ' Tocá «Reintentar».');
            }
            if (!el.isConnected) {
                // La quitaron mientras se subía: se borra también del servidor.
                fetch(data.delete_url, { method: 'DELETE', headers, credentials: 'same-origin' }).catch(() => {});
                return;
            }
            el.dataset.state = 'done';
            el.dataset.photoId = data.id;
            el.querySelector('[data-remove]').dataset.deleteUrl = data.delete_url;
            const img = el.querySelector('img');
            img.classList.remove('opacity-60');
            img.src = data.thumb_url;
            el.querySelector('[data-label]').remove();
            el._file = null;
            say(queue.length ? 'Foto guardada. Subiendo las demás…' : '✓ Foto guardada en ' + group.dataset.sectionName + ' · ' + group.dataset.stageLabel + '.', 'ok');
        } catch (e) {
            setFailed(el, navigator.onLine === false ? 'Sin conexión: la foto no se subió. Tocá «Reintentar» cuando vuelva la señal.' : 'No se pudo subir la foto. Tocá «Reintentar».');
        }
    }
    function enqueue(el) {
        queue.push(el);
        if (!busy) next();
    }
    async function next() {
        busy = true;
        while (queue.length) {
            const el = queue.shift();
            if (!el.isConnected) continue;
            el._file = el._compressed ? el._file : await shrink(el._file);
            el._compressed = true;
            await upload(el);
        }
        busy = false;
    }
    root.addEventListener('change', async (e) => {
        if (e.target.matches('[data-stage-choice], [data-composer-section]')) { composer(); return; }
        const input = e.target.closest('[data-file]');
        if (input) {
            const group = chosenGroup();
            const files = Array.from(input.files || []).filter((f) => !f.type || f.type.startsWith('image/'));
            input.value = '';
            if (!group || !files.length) return;
            const details = group.closest('details');
            if (details) details.open = true;
            const roomGroup = maxGroup - group.querySelectorAll('[data-thumbs] > div').length;
            const roomTotal = maxTotal - tiles().length;
            const room = Math.max(0, Math.min(roomGroup, roomTotal));
            if (files.length > room) say(roomTotal <= 0 ? 'Llegaste al máximo de ' + maxTotal + ' fotos.' : 'En ' + group.dataset.sectionName + ' · ' + group.dataset.stageLabel + ' entran ' + maxGroup + ' fotos. Se agregaron las primeras.', 'error');
            else say('Subiendo…');
            files.slice(0, room).forEach((file) => enqueue(tile(group, file)));
            return;
        }
        // Equipo u observación de la sección: se guardan para sus fotos.
        const field = e.target.closest('[data-section-notes], [data-section-elevator]');
        if (!field) return;
        const section = field.closest('[data-photo-section]');
        const meta = { notes: section.querySelector('[data-section-notes]')?.value || '', elevator: section.querySelector('[data-section-elevator]')?.value || '' };
        try {
            const res = await fetch(root.dataset.sectionUrl, { method: 'PATCH', headers: { ...headers, 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ [root.dataset.targetField]: root.dataset.targetId, photo_section_id: section.dataset.photoSection, notes: meta.notes, elevator_id: meta.elevator || null }) });
            say(res.ok ? 'Guardado.' : 'No se pudo guardar. Probá de nuevo.', res.ok ? 'ok' : 'error');
        } catch (err) {
            say('No se pudo guardar. Probá de nuevo.', 'error');
        }
    });
    root.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-remove]');
        if (!btn) return;
        const el = btn.closest('[data-thumbs] > div');
        const group = btn.closest('[data-photo-group]');
        if (el.dataset.state === 'done' && btn.dataset.deleteUrl) {
            if (!confirm('¿Quitar esta foto?')) return;
            btn.disabled = true;
            try {
                const res = await fetch(btn.dataset.deleteUrl, { method: 'DELETE', headers, credentials: 'same-origin' });
                if (!res.ok && res.status !== 404) {
                    const data = await res.json().catch(() => ({}));
                    btn.disabled = false;
                    return say(data.errors ? Object.values(data.errors).flat()[0] : 'No se pudo quitar la foto. Probá de nuevo.', 'error');
                }
            } catch (err) {
                btn.disabled = false;
                return say('No se pudo quitar la foto. Probá de nuevo.', 'error');
            }
        }
        el.remove();
        refresh(group);
    });
    // Salir con fotos subiéndose o con error: se avisa (se perderían).
    window.addEventListener('beforeunload', (e) => {
        if (tiles('[data-state="uploading"]').length || tiles('[data-state="failed"]').length) { e.preventDefault(); e.returnValue = ''; }
    });
    composer();
    window.ascentoVisitPhotos = {
        state: () => ({ uploading: tiles('[data-state="uploading"]').length, failed: tiles('[data-state="failed"]').length }),
    };
})();
</script>
@endif
