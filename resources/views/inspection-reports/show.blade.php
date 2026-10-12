{{--
    Reporte de la inspección (app del técnico): «Crear reporte» la primera
    vez y «Ver reporte» después. Observaciones y fotos del inspector; no es
    una reparación (sin prioridad ni estado). Las fotos solo si la empresa
    habilitó las fotos de inspecciones; apagadas, el reporte se hace igual
    con observaciones y las fotos ya cargadas se conservan.
    $company, $visit, $building, $deliveryNote, $report, $canEdit,
    $photosEnabled, $maxPhotos, $editing
--}}
@php
    $slug = $company->slug;
    $photoCount = $report?->photos->count() ?? 0;
    $room = max(0, $maxPhotos - $photoCount);
@endphp
<x-app-layout>
<div class="t-page max-w-2xl lg:max-w-2xl">
    <a href="{{ route('buildings.index', ['company' => $slug, 'month' => $visit->month, 'year' => $visit->year]) }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Visitas
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">Reporte de inspección</h1>
        <p class="t-sub">{{ $building->name }} · {{ $building->address }}</p>
        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            @if($visit->in_progress)
                <span class="t-chip-pending" data-visit-state="in_progress">Inspección en curso · sin remito</span>
            @elseif($deliveryNote)
                <span class="t-chip-done" data-visit-state="signed">Remito #{{ $deliveryNote->number }}</span>
            @endif
            <span class="text-xs text-ink-500">{{ ucfirst(\Carbon\Carbon::create($visit->year, $visit->month, 1)->locale('es')->translatedFormat('F Y')) }}</span>
        </div>
    </header>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="t-alert-error mb-4" role="alert">
            <ul class="list-inside list-disc space-y-0.5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if($report && ! $editing)
        {{-- Ver reporte --}}
        <article class="t-card overflow-hidden" data-inspection-report="{{ $report->id }}">
            <div class="p-4">
                <div class="text-[11px] font-bold uppercase tracking-wider text-ink-500">Observaciones</div>
                <p class="mt-0.5 whitespace-pre-line text-[15px] leading-6 text-ink" data-report-observations>{{ $report->description }}</p>
                <p class="mt-2 text-xs text-ink-500">{{ $report->user?->name }} · {{ $report->updated_at?->format('d/m/Y H:i') }}</p>

                @if($photoCount)
                    <h2 class="t-section">Fotos ({{ $photoCount }})</h2>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach($report->photos as $photo)
                            <div class="relative" data-report-photo="{{ $photo->id }}">
                                <a href="{{ $photo->url() }}" target="_blank" rel="noopener" class="block aspect-square overflow-hidden rounded-xl border border-ink-100 bg-ink-50">
                                    <img src="{{ $photo->thumbUrl() }}" loading="lazy" class="block h-full w-full object-cover" alt="Foto {{ $loop->iteration }} de la inspección">
                                </a>
                                @if($canEdit && $photosEnabled)
                                    <form method="POST" action="{{ route('inspection-reports.photos.destroy', ['company' => $slug, 'visit' => $visit->id, 'photo' => $photo->id]) }}"
                                          onsubmit="return confirm('¿Quitar esta foto del reporte?');" class="absolute right-1 top-1">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-full bg-white/90 px-2 py-0.5 text-xs font-bold text-red-700 shadow" aria-label="Quitar foto">✕</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="grid gap-2 border-t border-ink-100 bg-ink-50/60 p-3 {{ $canEdit ? 'grid-cols-2' : '' }}">
                @if($canEdit)
                    <a href="{{ route('inspection-reports.show', ['company' => $slug, 'visit' => $visit->id, 'edit' => 1]) }}" class="t-btn-secondary w-full" data-edit-report>Editar o agregar fotos</a>
                @endif
                <a href="{{ route('reports.pdf', $report) }}" target="_blank" rel="noopener" class="t-btn-secondary w-full">Descargar PDF</a>
            </div>
        </article>
    @elseif($canEdit)
        {{-- Crear (o editar) reporte --}}
        <form method="POST" action="{{ route('inspection-reports.save', ['company' => $slug, 'visit' => $visit->id]) }}" enctype="multipart/form-data"
              class="t-card space-y-4 p-4" data-inspection-report-form>
            @csrf
            <div>
                <label for="observations" class="t-label">Observaciones de la inspección</label>
                <textarea id="observations" name="observations" rows="7" maxlength="5000" required minlength="5" class="t-input"
                          placeholder="Qué se revisó, qué se encontró y qué se recomienda.">{{ old('observations', $report?->description) }}</textarea>
                <p class="mt-1 text-xs text-ink-500">Es el informe de la inspección, no un pedido de reparación.</p>
            </div>

            @if($photosEnabled)
                <div>
                    <span class="t-label">Fotos (opcional)</span>
                    @if($room > 0)
                        <div class="grid grid-cols-2 gap-2">
                            <label class="t-btn-primary min-h-[52px] w-full cursor-pointer" data-take-photo>
                                Tomar foto
                                <input type="file" name="photos[]" accept="image/*" capture="environment" class="sr-only" data-report-file aria-label="Tomar foto">
                            </label>
                            <label class="t-btn-secondary min-h-[52px] w-full cursor-pointer" data-pick-photo>
                                Elegir de la galería
                                <input type="file" name="photos[]" accept="image/*" multiple class="sr-only" data-report-file aria-label="Elegir de la galería">
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-ink-500" data-report-files-hint>Hasta {{ $room }} {{ $room === 1 ? 'foto más' : 'fotos más' }} (máximo {{ $maxPhotos }} por reporte, 10 MB cada una).</p>
                    @else
                        <p class="text-xs text-ink-500">El reporte ya tiene {{ $maxPhotos }} fotos (el máximo).</p>
                    @endif
                </div>
            @else
                <p class="text-xs text-ink-500" data-report-photos-disabled>Las fotos de inspecciones no están habilitadas en tu empresa: el reporte se guarda con las observaciones.</p>
            @endif

            <button type="submit" class="t-btn-primary w-full" data-save-report>{{ $report ? 'Guardar cambios' : 'Crear reporte' }}</button>
            @if($report)
                <a href="{{ route('inspection-reports.show', ['company' => $slug, 'visit' => $visit->id]) }}" class="t-btn-secondary w-full">Cancelar</a>
            @endif
        </form>
        <script>
            // Muestra cuántas fotos se van a subir (tomadas y de la galería).
            (() => {
                const inputs = document.querySelectorAll('[data-report-file]');
                const hint = document.querySelector('[data-report-files-hint]');
                if (!hint) return;
                const base = hint.textContent;
                inputs.forEach((input) => input.addEventListener('change', () => {
                    const n = [...inputs].reduce((total, i) => total + i.files.length, 0);
                    hint.textContent = n ? n + (n === 1 ? ' foto lista para subir. ' : ' fotos listas para subir. ') + base : base;
                }));
            })();
        </script>
    @else
        <div class="t-empty">Todavía no hay reporte de esta inspección.</div>
    @endif

    @if($visit->in_progress)
        <a href="{{ route('delivery-notes.building', ['company' => $slug, 'building' => $building->id, 'month' => $visit->month, 'year' => $visit->year, 'assignment_type' => 'inspection']) }}"
           class="t-btn-dark mt-4 w-full" data-make-remito>Hacer el remito de la inspección</a>
    @endif
</div>
</x-app-layout>
