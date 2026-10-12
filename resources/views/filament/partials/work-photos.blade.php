{{--
    «Fotos del trabajo» dentro del detalle de un mantenimiento, una inspección
    (su remito) o una orden de trabajo. Solo lectura: Antes y Después, por
    sección, con equipo, observación, quién la cargó y cuándo. Cada imagen se
    abre o descarga por la ruta privada (vuelve a verificar empresa y usuario).
    Variables: $photos (Collection<VisitPhoto>), $emptyText.
--}}
@php $groups = \App\Services\Visits\WorkPhotoGallery::group($photos); @endphp
<style>
    .wp-stage + .wp-stage { margin-top: 1.25rem; }
    .wp-stage-title { font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: rgb(55 65 81); margin-bottom: .5rem; }
    .wp-stage-title.is-after { color: rgb(4 120 87); }
    .wp-section { margin-bottom: .75rem; }
    .wp-section-name { font-size: .875rem; font-weight: 600; margin-bottom: .375rem; }
    .wp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .75rem; }
    .wp-card { border: 1px solid rgb(229 231 235); border-radius: .5rem; overflow: hidden; background: rgb(255 255 255); }
    .wp-card img { display: block; width: 100%; aspect-ratio: 1 / 1; object-fit: cover; background: rgb(243 244 246); }
    .wp-meta { padding: .375rem .5rem; font-size: .75rem; color: rgb(75 85 99); line-height: 1.35; }
    .wp-meta strong { color: rgb(17 24 39); font-weight: 600; }
    .wp-links { display: flex; gap: .75rem; margin-top: .25rem; }
    .wp-links a { color: rgb(37 99 235); font-weight: 600; text-decoration: underline; }
    .wp-empty { font-size: .875rem; color: rgb(107 114 128); }
    .dark .wp-stage-title { color: rgb(209 213 219); }
    .dark .wp-stage-title.is-after { color: rgb(110 231 183); }
    .dark .wp-card { border-color: rgb(55 65 81); background: rgb(17 24 39); }
    .dark .wp-meta { color: rgb(156 163 175); }
    .dark .wp-meta strong { color: rgb(243 244 246); }
    .dark .wp-links a { color: rgb(96 165 250); }
    .dark .wp-empty { color: rgb(156 163 175); }
</style>
<div data-work-photos>
    @if($photos->isEmpty())
        <p class="wp-empty" data-work-photos-empty>{{ $emptyText ?? 'Este trabajo no tiene fotos.' }}</p>
    @else
        @foreach($groups as $stage => $group)
            <div class="wp-stage" data-work-photos-stage="{{ $stage }}">
                <div class="wp-stage-title {{ $stage === 'after' ? 'is-after' : '' }}">{{ $group['label'] }} · {{ $group['sections']->flatten()->count() }}</div>
                @forelse($group['sections'] as $sectionName => $sectionPhotos)
                    <div class="wp-section">
                        <div class="wp-section-name">{{ $sectionName }}</div>
                        <div class="wp-grid">
                            @foreach($sectionPhotos as $photo)
                                <div class="wp-card" data-work-photo="{{ $photo->id }}">
                                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" aria-label="Ver foto de {{ $sectionName }}, {{ mb_strtolower($group['label']) }}">
                                        <img src="{{ $photo->thumbUrl() }}" alt="" loading="lazy">
                                    </a>
                                    <div class="wp-meta">
                                        <div><strong>{{ $photo->elevator?->label ?? 'General del edificio' }}</strong></div>
                                        @if($photo->notes)
                                            <div>{{ $photo->notes }}</div>
                                        @endif
                                        <div>{{ $photo->uploader?->name ?? '—' }} · {{ $photo->created_at?->format('d/m/Y H:i') }}</div>
                                        <div class="wp-links">
                                            <a href="{{ $photo->url() }}" target="_blank" rel="noopener">Ver</a>
                                            <a href="{{ $photo->downloadUrl() }}">Descargar</a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="wp-empty">Sin fotos de «{{ mb_strtolower($group['label']) }}».</p>
                @endforelse
            </div>
        @endforeach
    @endif
</div>
