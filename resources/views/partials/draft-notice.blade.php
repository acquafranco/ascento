{{-- Aviso de borrador recuperado (resources/js/drafts.js lo muestra). --}}
<div data-draft-notice hidden class="t-alert-warning">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <span class="min-w-0 flex-1">Recuperamos lo que habías escrito <span data-draft-when></span>. {{ $media ?? 'Las fotos, el video y las firmas se vuelven a cargar.' }}</span>
        <button type="button" data-draft-discard class="t-btn-secondary t-btn-sm shrink-0">Descartar</button>
    </div>
</div>
