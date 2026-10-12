{{--
    Visor para ampliar una foto en el panel, sin salir de la página: la
    miniatura (achicada en el servidor) abre la foto original dentro de un
    cuadro que entra en la pantalla (proporción conservada), con «Abrir
    original» y «Descargar». Sin JavaScript, el enlace abre la foto en otra
    pestaña. Las imágenes siempre por la ruta privada (vuelve a verificar
    permisos). Se incluye una vez por página.
--}}
@once
<style>
    .asc-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 160px)); gap: .5rem; }
    .asc-thumb { display: block; width: 100%; aspect-ratio: 1 / 1; overflow: hidden; border-radius: .5rem; border: 1px solid rgb(229 231 235); background: rgb(243 244 246); cursor: zoom-in; }
    .asc-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .asc-thumb:focus-visible { outline: 2px solid rgb(37 99 235); outline-offset: 2px; }
    .dark .asc-thumb { border-color: rgb(55 65 81); background: rgb(31 41 55); }
    .asc-lb { position: fixed; inset: 0; margin: auto; height: fit-content; padding: 0; border: 0; border-radius: .75rem; background: rgb(17 24 39); color: #fff; width: min(92vw, 1100px); max-height: 92vh; }
    .asc-lb::backdrop { background: rgb(0 0 0 / .7); }
    .asc-lb-body { display: flex; flex-direction: column; max-height: 92vh; }
    .asc-lb img { display: block; margin: 0 auto; max-width: 100%; max-height: calc(92vh - 3.5rem); object-fit: contain; background: #000; }
    .asc-lb-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; padding: .625rem .875rem; font-size: .8125rem; }
    .asc-lb-bar a, .asc-lb-bar button { color: #fff; font-weight: 600; text-decoration: underline; background: none; border: 0; cursor: pointer; padding: .25rem 0; }
</style>
<dialog id="asc-photo-lightbox" class="asc-lb" aria-label="Foto ampliada" onclick="if (event.target === this) this.close()">
    <div class="asc-lb-body">
        <img src="" alt="">
        <div class="asc-lb-bar">
            <span data-lb-caption></span>
            <span style="display:flex;gap:1rem">
                <a href="#" target="_blank" rel="noopener" data-lb-open>Abrir original</a>
                <a href="#" data-lb-download>Descargar</a>
                <button type="button" onclick="this.closest('dialog').close()">Cerrar</button>
            </span>
        </div>
    </div>
</dialog>
<script>
    window.ascPhotoZoom = function (link) {
        const box = document.getElementById('asc-photo-lightbox');
        if (!box || typeof box.showModal !== 'function') return true; // sin soporte: abre en otra pestaña
        box.querySelector('img').src = link.href;
        box.querySelector('img').alt = link.getAttribute('aria-label') || '';
        box.querySelector('[data-lb-caption]').textContent = link.dataset.caption || '';
        box.querySelector('[data-lb-open]').href = link.href;
        box.querySelector('[data-lb-download]').href = link.dataset.download || link.href;
        box.showModal();
        return false;
    };
</script>
@endonce
