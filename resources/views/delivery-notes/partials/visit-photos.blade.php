{{--
    Fotos de antes y después de la visita del remito. SOLO en la vista
    interna (admin de la empresa y técnicos de la visita): nunca en el enlace
    público, el portal ni el PDF. Cada imagen se sirve por la ruta privada
    (VisitPhotoController), que vuelve a verificar empresa, usuario y visita.
    $photos (Collection<VisitPhoto>)
--}}
@php
    $bySection = $photos->groupBy('photo_section_id');
    $stages = \App\Models\VisitPhoto::STAGES;
@endphp
<style>
    .rm-photos { margin-top: 14px; background: #fff; border: 1px solid #E7E9EE; border-radius: 16px; padding: 14px; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #11161D; }
    .rm-photos h2 { margin: 0 0 2px; font-size: 16px; font-weight: 800; }
    .rm-photos-sub { margin: 0 0 10px; font-size: 12px; color: #6B7280; }
    .rm-photo-section { border-top: 1px solid #F1F2F5; padding-top: 10px; margin-top: 10px; }
    .rm-photo-section h3 { margin: 0 0 6px; font-size: 14px; font-weight: 700; }
    .rm-photo-meta { font-size: 12px; color: #6B7280; margin: 0 0 6px; overflow-wrap: anywhere; }
    .rm-photo-stages { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .rm-photo-stage-label { font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 4px; }
    .rm-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 6px; }
    .rm-photo-grid a { display: block; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: #F1F3F6; }
    .rm-photo-grid img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rm-photo-empty { font-size: 12px; color: #9CA3AF; }
    @media (max-width: 360px) { .rm-photo-stages { grid-template-columns: 1fr; } }
    @media print { .rm-photos { display: none !important; } }
</style>
<section class="rm-photos" aria-labelledby="rm-photos-title" data-visit-photos>
    <h2 id="rm-photos-title">Fotos de antes y después</h2>
    <p class="rm-photos-sub">Uso interno: no se comparten con el cliente.</p>
    @foreach($bySection as $sectionPhotos)
        @php
            $first = $sectionPhotos->first();
            $notes = $sectionPhotos->pluck('notes')->filter()->unique();
            $elevator = $sectionPhotos->pluck('elevator.label')->filter()->unique()->implode(', ');
        @endphp
        <div class="rm-photo-section">
            <h3>{{ $first->section_name }}</h3>
            <p class="rm-photo-meta">
                {{ $first->created_at?->format('d/m/Y H:i') }} · {{ $first->uploader?->name ?? '—' }}{{ $elevator ? ' · '.$elevator : '' }}
                @foreach($notes as $note)<br>{{ $note }}@endforeach
            </p>
            <div class="rm-photo-stages">
                @foreach($stages as $stage => $label)
                    @php $stagePhotos = $sectionPhotos->where('stage', $stage); @endphp
                    <div>
                        <div class="rm-photo-stage-label" style="color: {{ $stage === 'before' ? '#374151' : '#047857' }}">{{ $label }}</div>
                        @if($stagePhotos->isEmpty())
                            <div class="rm-photo-empty">Sin fotos</div>
                        @else
                            <div class="rm-photo-grid">
                                @foreach($stagePhotos as $photo)
                                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" aria-label="{{ $first->section_name }}, {{ mb_strtolower($label) }}, foto {{ $loop->iteration }}">
                                        <img src="{{ $photo->thumbUrl() }}" alt="" loading="lazy">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</section>
