{{-- Video de un reporte. $video (ReportVideo), $src (URL autorizada). Estilos en línea: sirve en panel, app y portal. --}}
<div style="margin-top: 8px;">
    <video controls preload="metadata" playsinline style="width: 100%; max-height: 420px; border-radius: 12px; background: #12151C;" aria-label="Video del reporte">
        <source src="{{ $src }}" type="{{ $video->mime === 'video/quicktime' ? 'video/mp4' : $video->mime }}">
        Tu navegador no puede mostrar este video.
    </video>
    <p style="margin: 6px 0 0; font-size: 13px; color: #6B7080;">
        @if($video->status !== \App\Models\ReportVideo::READY)
            Optimizando el video para que cargue más rápido… ya se puede ver. ·
        @elseif(! $video->isWebPlayable())
            Si no se reproduce (algunos videos de iPhone), descargalo. ·
        @endif
        {{ $video->sizeLabel() }}{{ $video->duration ? ' · '.$video->duration.' s' : '' }} ·
        <a href="{{ $src }}{{ str_contains($src, '?') ? '&' : '?' }}download=1" style="color: #C24800; font-weight: 600;">Descargar</a>
    </p>
</div>
