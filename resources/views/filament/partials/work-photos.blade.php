{{--
    «Fotos de antes y después» dentro del detalle de un mantenimiento o una
    orden de trabajo (nunca en el remito). Solo lectura: una fila por zona
    (Pozo, Sala de máquinas…) con Antes y Después uno al lado del otro; cada
    foto con equipo, observación, quién la cargó y cuándo. Cada imagen se
    abre o descarga por la ruta privada (vuelve a verificar empresa y usuario).
    Variables: $photos (Collection<VisitPhoto>), $emptyText.
--}}
@php $zones = \App\Services\Visits\WorkPhotoGallery::byZone($photos); @endphp
@include('filament.partials.photo-lightbox')
<style>
    .wp-zone { border: 1px solid rgb(229 231 235); border-radius: .75rem; padding: .75rem; }
    .wp-zone + .wp-zone { margin-top: .75rem; }
    .wp-zone-name { font-size: .875rem; font-weight: 700; margin-bottom: .5rem; }
    .wp-pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
    .wp-stage-title { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: rgb(55 65 81); margin-bottom: .375rem; }
    .wp-stage-title.is-after { color: rgb(4 120 87); }
    /* Mismo tamaño que las fotos de inspección: tarjetas de 110 a 160 px, foto cuadrada (se amplía en el visor). */
    .wp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 160px)); gap: .5rem; }
    .wp-card { border: 1px solid rgb(229 231 235); border-radius: .5rem; overflow: hidden; background: rgb(255 255 255); min-width: 0; }
    .wp-card .asc-thumb { border: 0; border-radius: 0; }
    .wp-meta { padding: .375rem .5rem; font-size: .75rem; color: rgb(75 85 99); line-height: 1.35; overflow-wrap: anywhere; }
    .wp-meta strong { color: rgb(17 24 39); font-weight: 600; }
    .wp-links { display: flex; flex-wrap: wrap; gap: .25rem .75rem; margin-top: .25rem; }
    .wp-links a { color: rgb(37 99 235); font-weight: 600; text-decoration: underline; }
    .wp-empty { font-size: .8rem; color: rgb(107 114 128); }
    .dark .wp-zone { border-color: rgb(55 65 81); }
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
        @foreach($zones as $zone)
            <div class="wp-zone" data-work-photos-zone="{{ $zone['key'] }}">
                <div class="wp-zone-name">{{ $zone['label'] }}</div>
                <div class="wp-pair">
                    @foreach(\App\Models\VisitPhoto::STAGES as $stage => $stageLabel)
                        <div data-work-photos-stage="{{ $stage }}">
                            <div class="wp-stage-title {{ $stage === 'after' ? 'is-after' : '' }}">{{ $stageLabel }} · {{ $zone[$stage]->count() }}</div>
                            @if($zone[$stage]->isEmpty())
                                <p class="wp-empty">Sin fotos de «{{ mb_strtolower($stageLabel) }}».</p>
                            @else
                                <div class="wp-grid">
                                    @foreach($zone[$stage] as $photo)
                                        <div class="wp-card" data-work-photo="{{ $photo->id }}">
                                            <a href="{{ $photo->url() }}" class="asc-thumb" target="_blank" rel="noopener" aria-label="Ampliar foto de {{ $zone['label'] }}, {{ mb_strtolower($stageLabel) }}"
                                               data-caption="{{ $zone['label'] }} · {{ $stageLabel }}" data-download="{{ $photo->downloadUrl() }}" onclick="return window.ascPhotoZoom ? ascPhotoZoom(this) : true">
                                                <img src="{{ $photo->thumbUrl() }}" alt="" loading="lazy">
                                            </a>
                                            <div class="wp-meta">
                                                <div><strong>{{ $photo->elevator?->label ?? 'General del edificio' }}</strong></div>
                                                @if($photo->notes)
                                                    <div>{{ $photo->notes }}</div>
                                                @endif
                                                <div>{{ $photo->uploader?->name ?? '—' }} · {{ $photo->created_at?->format('d/m/Y H:i') }}</div>
                                                <div class="wp-links">
                                                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" data-caption="{{ $zone['label'] }} · {{ $stageLabel }}" data-download="{{ $photo->downloadUrl() }}" onclick="return window.ascPhotoZoom ? ascPhotoZoom(this) : true">Ampliar</a>
                                                    <a href="{{ $photo->downloadUrl() }}">Descargar</a>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>
