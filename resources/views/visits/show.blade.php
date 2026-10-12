{{--
    Una visita (mantenimiento o inspección, en curso o con remito) o una
    orden de trabajo, con sus fotos de antes y después. Las fotos se cargan
    acá antes, durante o después del trabajo, de a una: cada una queda
    guardada al instante (un error del remito o un corte de señal no las
    pierde) y no hacen falta para firmar el remito.
    Si la empresa no habilitó las fotos para este tipo de trabajo, solo se
    ven las que ya existían (sin controles de carga).
--}}
@php
    $slug = $company->slug;
    $me = auth()->id();
    $typeLabel = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'work_order' => 'Orden de trabajo'][$target->type];
    $stages = \App\Models\VisitPhoto::STAGES;
    $maxGroup = \App\Models\VisitPhoto::MAX_PER_GROUP;
    $grouped = $photos->groupBy(fn ($p) => $p->photo_section_id.'|'.$p->stage);
    $inProgress = $visit?->in_progress;
    $targetField = $target->type === 'work_order' ? 'work_order_id' : 'visit_id';
    $targetId = $target->type === 'work_order' ? $workOrder->id : $visit->id;
    $back = $target->type === 'work_order'
        ? route('work-orders.show', ['company' => $slug, 'workOrder' => $workOrder->id])
        : route('buildings.index', ['company' => $slug, 'month' => $visit->month, 'year' => $visit->year]);
@endphp
<x-app-layout>
<div class="t-page max-w-2xl lg:max-w-2xl">
    <a href="{{ $back }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $target->type === 'work_order' ? 'Orden de trabajo' : 'Visitas' }}
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">{{ $building->name }}</h1>
        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            <span class="t-chip-progress">{{ $typeLabel }}{{ $visit && $target->type !== 'work_order' && $period && $period->expected > 1 ? ' · visita '.$visit->slot.' de '.$period->expected : '' }}</span>
            @if($inProgress)
                <span class="t-chip-pending" data-visit-state="in_progress">En curso · sin remito</span>
            @elseif($deliveryNote)
                <span class="{{ $deliveryNote->performed === false ? 't-chip-failed' : 't-chip-done' }}" data-visit-state="signed">Remito #{{ $deliveryNote->number }}{{ $deliveryNote->performed === false ? ' · no realizado' : '' }}</span>
            @elseif($workOrder)
                <span class="t-chip-neutral" data-visit-state="work_order">Orden #{{ $workOrder->id }}</span>
            @endif
        </div>
        @if($period)
            <p class="mt-1 text-xs text-ink-500">Período {{ $period->label() }} · {{ $period->frequencyLabel() }}</p>
        @endif
    </header>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="t-alert-error mb-4" role="alert">{{ $errors->first() }}</div>
    @endif

    @if($inProgress)
        <section class="t-card mb-4 p-4">
            <p class="text-sm text-ink-700">Cargá fotos cuando quieras: antes, durante o después del trabajo. Cuando termines, hacé el remito: completa esta misma visita. Las fotos no son obligatorias.</p>
            <a href="{{ route('delivery-notes.building', ['company' => $slug, 'building' => $building->id, 'month' => $visit->month, 'year' => $visit->year, 'assignment_type' => $target->type]) }}"
               class="t-btn-primary mt-3 w-full" data-make-remito>Hacer el remito</a>
        </section>
    @endif

    <section class="t-card p-4" id="visitPhotos"
             data-upload-url="{{ route('visit-photos.store', ['company' => $slug]) }}"
             data-section-url="{{ route('visit-photos.section', ['company' => $slug]) }}"
             data-target-field="{{ $targetField }}" data-target-id="{{ $targetId }}"
             data-max-group="{{ $maxGroup }}" data-max-total="{{ \App\Models\VisitPhoto::MAX_PER_VISIT }}"
             data-can-upload="{{ $canUpload ? '1' : '0' }}">
        <h2 class="font-bold text-ink">Fotos de antes y después <span class="text-xs font-normal text-ink-500">(opcional)</span></h2>
        @if($canUpload)
            <p class="mb-3 text-xs text-ink-500">Hasta {{ $maxGroup }} fotos por sección en «Antes» y {{ $maxGroup }} en «Después» ({{ \App\Models\VisitPhoto::MAX_PER_VISIT }} por visita). Cada foto se guarda al subirla; si alguna falla, tocá «Reintentar». Las ve la oficina, no el cliente.</p>
        @else
            <p class="mb-3 text-xs text-ink-500" data-photos-disabled>La carga de fotos no está habilitada para este tipo de trabajo. {{ $photos->isNotEmpty() ? 'Estas son las fotos que ya se habían cargado.' : '' }}</p>
        @endif

        <div class="space-y-2">
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
                            <div data-photo-group data-section="{{ $section->id }}" data-stage="{{ $stage }}" data-section-name="{{ $section->name }}">
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
                                @if($canUpload && $section->is_active)
                                    <label class="t-btn-secondary t-btn-sm mt-2 w-full cursor-pointer {{ $groupPhotos->count() >= $maxGroup ? 'hidden' : '' }}" data-add>
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        Agregar foto · {{ mb_strtolower($stageLabel) }}
                                        <input type="file" accept="image/*" multiple class="sr-only" data-file aria-label="Agregar foto de {{ $section->name }}, {{ mb_strtolower($stageLabel) }}">
                                    </label>
                                @endif
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
                <p class="text-sm text-ink-500">Esta visita no tiene fotos.</p>
            @endif
        </div>
        <p id="photos-status" class="mt-2 hidden text-xs font-semibold" role="status" aria-live="polite"></p>
    </section>

    @if($inProgress && ! $deliveryNote)
        <form method="POST" action="{{ route('visits.discard', ['company' => $slug, 'visit' => $visit->id]) }}" class="mt-4"
              onsubmit="return confirm('¿Descartar esta visita? Se borran también sus fotos. No se puede deshacer.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="t-btn-secondary w-full text-red-700" data-discard-visit>Descartar visita en curso</button>
        </form>
    @endif
</div>

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
    const queue = [];
    let busy = false;
    const tiles = (sel) => root.querySelectorAll('[data-thumbs] > div' + (sel || ''));
    const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' };
    function say(text, isError) {
        status.textContent = text;
        status.className = 'mt-2 text-xs font-semibold ' + (isError ? 'text-red-700' : 'text-ink-500');
        status.classList.toggle('hidden', !text);
    }
    function refresh(group) {
        const n = group.querySelectorAll('[data-thumbs] > div').length;
        group.querySelector('[data-group-count]').textContent = n + ' de ' + maxGroup;
        group.querySelector('[data-add]')?.classList.toggle('hidden', n >= maxGroup);
        const section = group.closest('[data-photo-section]');
        const total = section.querySelectorAll('[data-thumbs] > div').length;
        section.querySelector('[data-section-count]').textContent = total ? total + (total === 1 ? ' foto' : ' fotos') : 'Sin fotos';
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
        retry.addEventListener('click', () => { el.dataset.state = 'uploading'; label.className = 'absolute inset-x-0 bottom-0 bg-white/90 px-1 py-0.5 text-center text-[11px] font-semibold text-ink-700'; label.textContent = 'Subiendo…'; enqueue(el); });
        label.append(retry);
        say(message, true);
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
                const msg = data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'No se pudo subir la foto.');
                if (res.status === 422 && data.errors && /hasta|máximo|desactivada/.test(msg)) { el.remove(); refresh(group); say(msg, true); return; }
                return setFailed(el, msg);
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
            say('');
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
        const input = e.target.closest('[data-file]');
        if (input) {
            const group = input.closest('[data-photo-group]');
            const files = Array.from(input.files || []).filter((f) => !f.type || f.type.startsWith('image/'));
            input.value = '';
            const roomGroup = maxGroup - group.querySelectorAll('[data-thumbs] > div').length;
            const roomTotal = maxTotal - tiles().length;
            const room = Math.max(0, Math.min(roomGroup, roomTotal));
            if (files.length > room) say(roomTotal <= 0 ? 'Llegaste al máximo de ' + maxTotal + ' fotos por visita.' : 'En esta sección entran ' + maxGroup + ' fotos por estado. Se agregaron las primeras.', true);
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
            say(res.ok ? 'Guardado.' : 'No se pudo guardar. Probá de nuevo.', !res.ok);
        } catch (err) {
            say('No se pudo guardar. Probá de nuevo.', true);
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
                if (!res.ok && res.status !== 404) throw new Error();
            } catch (err) {
                btn.disabled = false;
                return say('No se pudo quitar la foto. Probá de nuevo.', true);
            }
        }
        el.remove();
        refresh(group);
    });
    // Salir con fotos subiéndose o con error: se avisa (se perderían).
    window.addEventListener('beforeunload', (e) => {
        if (tiles('[data-state="uploading"]').length || tiles('[data-state="failed"]').length) { e.preventDefault(); e.returnValue = ''; }
    });
    window.ascentoVisitPhotos = {
        state: () => ({ uploading: tiles('[data-state="uploading"]').length, failed: tiles('[data-state="failed"]').length }),
    };
})();
</script>
@endif
</x-app-layout>
