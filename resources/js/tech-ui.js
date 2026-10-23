/*
 * Comportamientos comunes de la app (técnico y páginas con app.js):
 *  - Barra de carga al navegar o enviar un formulario.
 *  - Sin doble envío: al enviar un formulario se deshabilitan sus botones.
 *    Si la propia página maneja el envío (preventDefault), no se toca.
 *  - Al volver con "atrás" (caché del navegador) todo queda habilitado.
 */
const progress = () => {
    let bar = document.getElementById('t-progress');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 't-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
    }
    return bar;
};

const start = () => progress().classList.add('is-active');
const stop = () => document.getElementById('t-progress')?.classList.remove('is-active');

const release = (form) => {
    delete form.dataset.submitting;
    form.querySelectorAll('[aria-busy="true"]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    });
    stop();
    // La página que maneja su propio envío (p. ej. el remito) vuelve a quedar lista.
    form.dispatchEvent(new CustomEvent('ascento:released'));
};

export function initTechUi() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download') || link.dataset.noProgress !== undefined) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search)) return;
        start();
    });

    // Fase de burbujeo: corre DESPUÉS de los manejadores propios de cada página.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || form.dataset.allowResubmit !== undefined || form.target === '_blank') return;
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        form.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]').forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });
        start();
        // Si la respuesta no cambia de página (p. ej. una descarga), se libera.
        setTimeout(() => release(form), 15000);
    });

    window.addEventListener('pageshow', () => {
        stop();
        document.querySelectorAll('form[data-submitting="1"]').forEach(release);
    });
}
