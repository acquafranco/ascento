{{--
    «Fotos del trabajo» dentro de la tarjeta del trabajo (Visitas y Trabajos),
    sin cambiar de pantalla: Antes / Después, la zona del sistema, «Tomar foto» (abre la
    cámara si el navegador lo permite) y «Elegir de la galería». Cada foto se
    sube de a una, con su identidad (client_key): «Reintentar» no la duplica.
    En mantenimiento, la primera foto abre la visita en curso
    del período (la misma que después completa el remito). Solo se incluye
    si la empresa habilitó las fotos para ese tipo de trabajo; el servidor lo
    vuelve a verificar en cada carga.

    Variables: $slug, $sections (zonas del sistema: PhotoSection::zonesFor), $label (p. ej.
    «Mantenimiento»), y una de dos:
    - $workOrderId (orden de trabajo), o
    - $visitId (visita en curso, puede ser null) + $start (building_id,
      assignment_type, month, year) para abrirla con la primera foto.
    Opcionales: $allUrl (pantalla con todas las fotos), $count.
--}}
@php
    $uid = 'qp'.\Illuminate\Support\Str::random(6);
    $count = (int) ($count ?? 0);
    $first = $sections->first();
@endphp
<div class="mt-2 rounded-xl border border-ink-100 bg-ink-50 p-3" data-quick-photos data-quick-photos-for="{{ isset($workOrderId) ? 'work_order' : ($start['assignment_type'] ?? '') }}"
     data-upload-url="{{ route('visit-photos.store', ['company' => $slug]) }}"
     @isset($workOrderId) data-work-order-id="{{ $workOrderId }}" @else
     data-visit-id="{{ $visitId ?? '' }}" data-start-url="{{ route('visits.start', ['company' => $slug]) }}"
     data-start='@json($start)' @endisset
     data-max-group="{{ \App\Models\VisitPhoto::MAX_PER_GROUP }}">
    <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-1.5 text-sm font-bold text-ink">
            <svg class="h-4 w-4 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            Fotos {{ isset($label) ? 'de '.mb_strtolower($label) : 'del trabajo' }}
        </span>
        <a href="{{ $allUrl ?? '#' }}" class="text-xs font-semibold text-brand-700 underline {{ ($allUrl ?? null) ? '' : 'hidden' }}" data-quick-all>Ver todas (<span data-quick-count>{{ $count }}</span>)</a>
    </div>

    <div class="mt-2 grid grid-cols-2 gap-2" role="radiogroup" aria-label="¿Antes o después del trabajo?">
        @foreach(\App\Models\VisitPhoto::STAGES as $stage => $stageLabel)
            <label class="relative">
                <input type="radio" name="{{ $uid }}-stage" value="{{ $stage }}" class="peer sr-only" data-quick-stage @checked($stage === 'before')>
                <span class="flex min-h-[40px] cursor-pointer items-center justify-center rounded-lg border-2 border-ink-200 bg-white px-2 text-sm font-bold text-ink-700 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $stageLabel }}</span>
            </label>
        @endforeach
    </div>

    @if($sections->count() > 1)
        <label class="mt-2 block">
            <span class="sr-only">Zona</span>
            <select class="t-input py-2 text-sm" data-quick-section aria-label="Zona">
                @foreach($sections as $section)
                    <option value="{{ $section->id }}">{{ $section->label() }}</option>
                @endforeach
            </select>
        </label>
    @else
        <input type="hidden" value="{{ $first->id }}" data-quick-section data-name="{{ $first->label() }}">
    @endif

    <div class="mt-2 grid grid-cols-2 gap-2">
        {{-- capture="environment": la mayoría de los celulares abren la cámara trasera; si el navegador no lo soporta, ofrece elegir. --}}
        <label class="t-btn-primary t-btn-sm min-h-[44px] w-full cursor-pointer" data-quick-take>
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            Tomar foto
            <input type="file" accept="image/*" capture="environment" class="sr-only" data-quick-file aria-label="Tomar foto">
        </label>
        <label class="t-btn-secondary t-btn-sm min-h-[44px] w-full cursor-pointer text-center leading-tight" data-quick-pick>
            Elegir de la galería
            <input type="file" accept="image/*" multiple class="sr-only" data-quick-file aria-label="Elegir de la galería">
        </label>
    </div>

    <div class="mt-2 flex flex-wrap gap-1.5 empty:hidden" data-quick-thumbs></div>
    <p class="mt-1.5 hidden text-xs font-semibold" role="status" aria-live="polite" data-quick-status></p>
</div>

@once
<script>
/* Fotos desde la tarjeta del trabajo. Se suben de a una (en fila, por
   tarjeta), achicadas en el celular, con identidad propia: «Reintentar»
   no duplica. La primera foto de un mantenimiento o una inspección abre la
   visita en curso (la misma que completa el remito). */
(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' };
    const MAX_SIDE = 2000;
    const newKey = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
    async function shrink(file) {
        try {
            if (!window.createImageBitmap) return file;
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
        } catch (e) { return file; }
    }
    function say(root, text, kind) {
        const el = root.querySelector('[data-quick-status]');
        el.textContent = text;
        el.className = 'mt-1.5 text-xs font-semibold ' + (kind === 'error' ? 'text-red-700' : (kind === 'ok' ? 'text-emerald-700' : 'text-ink-500'));
        el.classList.toggle('hidden', !text);
    }
    const firstError = (data, fallback) => (data && data.errors ? Object.values(data.errors).flat()[0] : null) || (data && data.message) || fallback;

    // Visita en curso del período (se abre con la primera foto; la devuelve si ya existe).
    async function ensureTarget(root) {
        if (root.dataset.workOrderId) return { work_order_id: root.dataset.workOrderId };
        if (!root.dataset.visitId) {
            const res = await fetch(root.dataset.startUrl, { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/json' }, body: root.dataset.start });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(firstError(data, 'No se pudo abrir la visita.'));
            root.dataset.visitId = data.visit_id;
            const all = root.querySelector('[data-quick-all]');
            all.href = data.url;
            all.classList.remove('hidden');
        }
        return { visit_id: root.dataset.visitId };
    }
    function tile(root, file, stage, sectionName) {
        const el = document.createElement('div');
        el.className = 'relative h-16 w-16 overflow-hidden rounded-lg bg-white ring-1 ring-ink-100';
        el.dataset.state = 'uploading';
        el.title = sectionName + ' · ' + (stage === 'after' ? 'Después' : 'Antes');
        el.innerHTML = '<img alt="" class="h-full w-full object-cover opacity-60">'
            + '<span class="absolute left-0 top-0 rounded-br bg-white/90 px-1 text-[10px] font-bold ' + (stage === 'after' ? 'text-emerald-700' : 'text-ink-700') + '">' + (stage === 'after' ? 'Después' : 'Antes') + '</span>'
            + '<span class="absolute inset-x-0 bottom-0 bg-white/90 text-center text-[10px] font-semibold text-ink-700" data-label>Subiendo…</span>';
        try { el.querySelector('img').src = URL.createObjectURL(file); } catch (e) {}
        root.querySelector('[data-quick-thumbs]').append(el);
        return el;
    }
    async function upload(root, job) {
        const { el } = job;
        el.dataset.state = 'uploading';
        const label = el.querySelector('[data-label]');
        label.className = 'absolute inset-x-0 bottom-0 bg-white/90 text-center text-[10px] font-semibold text-ink-700';
        label.textContent = 'Subiendo…';
        try {
            if (navigator.onLine === false) throw new Error('Sin conexión: la foto no se subió.');
            const target = await ensureTarget(root);
            if (!job.compressed) { job.file = await shrink(job.file); job.compressed = true; }
            const body = new FormData();
            Object.entries(target).forEach(([k, v]) => body.append(k, v));
            body.append('photo_section_id', job.section);
            body.append('stage', job.stage);
            body.append('client_key', job.key);
            body.append('photo', job.file);
            const res = await fetch(root.dataset.uploadUrl, { method: 'POST', body, headers, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const msg = res.status === 413 ? 'La foto es demasiado pesada.' : firstError(data, 'No se pudo subir la foto.');
                if (res.status === 422 && /hasta|máximo|desactivada|ya no existe/.test(msg)) { el.remove(); say(root, msg, 'error'); return; }
                throw new Error(msg);
            }
            el.dataset.state = 'done';
            const img = el.querySelector('img');
            img.classList.remove('opacity-60');
            if (data.thumb_url) img.src = data.thumb_url;
            label.remove();
            if (res.status === 201) {
                const count = root.querySelector('[data-quick-count]');
                count.textContent = parseInt(count.textContent || '0', 10) + 1;
            }
            say(root, '✓ Foto guardada (' + job.sectionName + ' · ' + (job.stage === 'after' ? 'Después' : 'Antes') + ').', 'ok');
        } catch (e) {
            el.dataset.state = 'failed';
            label.className = 'absolute inset-x-0 bottom-0 bg-red-50 text-center text-[10px] font-bold text-red-700';
            label.innerHTML = '';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.textContent = 'Reintentar';
            retry.className = 'underline';
            retry.dataset.quickRetry = '';
            retry.addEventListener('click', () => enqueue(root, job));
            label.append(retry);
            say(root, (navigator.onLine === false ? 'Sin conexión: la foto no se subió.' : e.message) + ' Tocá «Reintentar».', 'error');
        }
    }
    function enqueue(root, job) {
        root._queue = root._queue || [];
        root._queue.push(job);
        if (root._busy) return;
        root._busy = true;
        (async () => {
            while (root._queue.length) await upload(root, root._queue.shift());
            root._busy = false;
        })();
    }
    document.addEventListener('change', (e) => {
        const input = e.target.closest('[data-quick-file]');
        if (!input) return;
        const root = input.closest('[data-quick-photos]');
        const stage = root.querySelector('[data-quick-stage]:checked')?.value || 'before';
        const picker = root.querySelector('[data-quick-section]');
        const sectionName = picker.tagName === 'SELECT' ? picker.selectedOptions[0].text : (picker.dataset.name || '');
        const files = Array.from(input.files || []).filter((f) => !f.type || f.type.startsWith('image/'));
        input.value = '';
        if (!files.length) return;
        say(root, 'Subiendo…');
        files.forEach((file) => enqueue(root, { file, stage, section: picker.value, sectionName: sectionName || 'General', key: newKey(), el: tile(root, file, stage, sectionName) }));
    });
    // Salir con fotos subiéndose o con error: se avisa (se perderían).
    window.addEventListener('beforeunload', (e) => {
        if (document.querySelector('[data-quick-thumbs] > [data-state="uploading"], [data-quick-thumbs] > [data-state="failed"]')) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>
@endonce
