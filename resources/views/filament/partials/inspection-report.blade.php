{{--
    Reporte de una inspección dentro de su detalle en el panel: observaciones
    y fotos del inspector (las imágenes, por la ruta privada de reportes).
    $report (Report|null)
--}}
<div data-inspection-report-panel>
    @if(! $report)
        <p class="text-sm text-gray-500 dark:text-gray-400" data-inspection-report-empty>El inspector todavía no cargó el reporte de esta inspección.</p>
    @else
        <p class="whitespace-pre-line text-sm text-gray-950 dark:text-white" data-report-observations>{{ $report->description }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $report->user?->name }} · {{ $report->updated_at?->format('d/m/Y H:i') }}</p>
        @if($report->photos->isNotEmpty())
            <div class="mt-3 grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));">
                @foreach($report->photos as $photo)
                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700" data-report-photo="{{ $photo->id }}">
                        <img src="{{ $photo->thumbUrl() }}" alt="Foto {{ $loop->iteration }} de la inspección" loading="lazy" style="display:block;width:100%;aspect-ratio:1/1;object-fit:cover;">
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</div>
