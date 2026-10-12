{{--
    Reporte de una inspección dentro de su detalle en el panel: observaciones
    y fotos del inspector, en miniaturas de tamaño fijo (proporción
    conservada) que se amplían en un visor. Las imágenes, por la ruta privada
    de reportes. $report (Report|null)
--}}
@include('filament.partials.photo-lightbox')
<div data-inspection-report-panel>
    @if(! $report)
        <p style="font-size:.875rem;color:rgb(107 114 128)" data-inspection-report-empty>El inspector todavía no cargó el reporte de esta inspección.</p>
    @else
        <p style="white-space:pre-line;font-size:.875rem" data-report-observations>{{ $report->description }}</p>
        <p style="margin-top:.25rem;font-size:.75rem;color:rgb(107 114 128)">{{ $report->user?->name }} · {{ $report->updated_at?->format('d/m/Y H:i') }}</p>
        @if($report->photos->isNotEmpty())
            <div class="asc-thumbs" style="margin-top:.75rem">
                @foreach($report->photos as $photo)
                    <a href="{{ $photo->url() }}" class="asc-thumb" target="_blank" rel="noopener" data-report-photo="{{ $photo->id }}"
                       data-caption="Foto {{ $loop->iteration }} de {{ $report->photos->count() }}" data-download="{{ $photo->url() }}"
                       aria-label="Ampliar foto {{ $loop->iteration }} de la inspección" onclick="return window.ascPhotoZoom ? ascPhotoZoom(this) : true">
                        <img src="{{ $photo->thumbUrl() }}" alt="" loading="lazy">
                    </a>
                @endforeach
            </div>
            <p style="margin-top:.375rem;font-size:.75rem;color:rgb(107 114 128)">Tocá una foto para ampliarla.</p>
        @endif
    @endif
</div>
