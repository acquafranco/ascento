{{--
    Video de un reporte. $video (ReportVideo), $src (URL autorizada) y
    opcional $statusUrl: mientras el servidor lo comprime, la pantalla consulta
    el estado cada pocos segundos (solo en ese caso, con tope) y se actualiza
    sola. Estilos en línea: sirve en el panel, la app del técnico y el portal.
--}}
@php
    $failed = $video->status === \App\Models\ReportVideo::FAILED;
    $processing = in_array($video->status, [\App\Models\ReportVideo::PENDING, \App\Models\ReportVideo::PROCESSING], true);
    $boxId = 'report-video-'.$video->id;
@endphp
<div id="{{ $boxId }}" style="margin-top: 8px;">
    @if($failed)
        <div role="status" style="padding: 12px 14px; border-radius: 12px; background: #FDECEC; color: #9B1C1C; font-size: 14px; font-weight: 600;">
            {{ $video->processing_note ?: 'No se pudo procesar el video. Subilo de nuevo.' }}
        </div>
    @else
        <video controls preload="metadata" playsinline style="width: 100%; max-height: 420px; border-radius: 12px; background: #11161D;" aria-label="Video del reporte">
            <source src="{{ $src }}" type="{{ $video->mime === 'video/quicktime' ? 'video/mp4' : $video->mime }}">
            Tu navegador no puede mostrar este video.
        </video>
        <p style="margin: 6px 0 0; font-size: 13px; color: #6B7080;">
            <span data-video-state>
                @if($processing)
                    <span style="color: #A84300; font-weight: 600;">Optimizando el video para que cargue más rápido… ya se puede ver.</span> ·
                @elseif(! $video->isWebPlayable())
                    Si no se reproduce (algunos videos de iPhone), descargalo. ·
                @endif
            </span>
            <span data-video-label>{{ $video->sizeLabel() }}{{ $video->duration ? ' · '.$video->duration.' s' : '' }}</span> ·
            <a href="{{ $src }}{{ str_contains($src, '?') ? '&' : '?' }}download=1" style="color: #C24800; font-weight: 600;">Descargar</a>
        </p>
    @endif
</div>
@if($processing && filled($statusUrl ?? null))
    <script>
        (() => {
            const box = document.getElementById(@json($boxId));
            if (!box || box.dataset.watching) return;
            box.dataset.watching = '1';
            let tries = 0;
            const check = async () => {
                if (!document.body.contains(box) || ++tries > 100) return; // ~10 min como máximo
                try {
                    const res = await fetch(@json($statusUrl), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (res.ok) {
                        const data = await res.json();
                        if (data.status === 'ready') {
                            box.querySelector('[data-video-state]').textContent = '';
                            box.querySelector('[data-video-label]').textContent = data.label || '';
                            const video = box.querySelector('video');
                            video.querySelector('source').type = 'video/mp4';
                            video.load();
                            return;
                        }
                        if (data.status === 'failed') {
                            box.innerHTML = '';
                            const msg = document.createElement('div');
                            msg.setAttribute('role', 'status');
                            msg.style.cssText = 'padding:12px 14px;border-radius:12px;background:#FDECEC;color:#9B1C1C;font-size:14px;font-weight:600';
                            msg.textContent = data.note || 'No se pudo procesar el video. Subilo de nuevo.';
                            box.appendChild(msg);
                            return;
                        }
                    }
                } catch (e) {}
                setTimeout(check, 6000);
            };
            setTimeout(check, 6000);
        })();
    </script>
@endif
