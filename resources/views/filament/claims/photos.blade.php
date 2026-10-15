<div style="display:flex; flex-wrap:wrap; gap:.5rem">
    @foreach($getRecord()->photos as $photo)
        <a href="{{ route('client-claim-photos.show', $photo->id) }}" target="_blank" rel="noopener">
            <img src="{{ route('client-claim-photos.show', $photo->id) }}" alt="Foto {{ $loop->iteration }} del reclamo" loading="lazy" style="width:7rem; height:7rem; object-fit:cover; border-radius:.5rem; border:1px solid rgba(148,163,184,.35)">
        </a>
    @endforeach
</div>
