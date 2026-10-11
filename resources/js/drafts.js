/*
 * Borradores locales de los formularios del técnico (reporte y remito).
 *
 * Lo que se escribe se guarda en el celular (localStorage) mientras se
 * completa: si se cierra la página, se corta la señal o vence la sesión, al
 * volver se recupera. No se guardan fotos, videos ni firmas (se vuelven a
 * cargar). No se envía nada solo: el técnico toca "Enviar" cuando tiene señal.
 *
 * Uso: <form data-draft="clave-única" [data-draft-restore="off"] [data-draft-xhr]>
 * con [data-draft-field] en cada campo a guardar y, adentro, un aviso
 * [data-draft-notice] con un botón [data-draft-discard].
 *  - data-draft-restore="off": la página ya trae los datos (error de validación).
 *  - data-draft-xhr: la página envía por su cuenta y llama a ascentoDrafts.clear().
 *  - Formularios normales: al enviarse quedan "enviados"; en la página
 *    siguiente (sin ese formulario) el borrador se borra. Si el envío falló
 *    (sin señal, sesión vencida), al volver al formulario sigue ahí.
 */
const PREFIX = 'ascento.draft.';
const done = new Set(); // enviados con éxito en esta página: no se vuelven a guardar
let frozen = false; // cerrando sesión: no se guarda nada más
const MAX_AGE = 7 * 24 * 3600 * 1000;

const read = (key) => {
    try {
        return JSON.parse(localStorage.getItem(PREFIX + key) || 'null');
    } catch (e) {
        return null;
    }
};
const write = (key, value) => {
    try {
        localStorage.setItem(PREFIX + key, JSON.stringify(value));
    } catch (e) {
        // Sin almacenamiento (modo privado, lleno): el formulario anda igual.
    }
};
const clear = (key) => {
    try {
        localStorage.removeItem(PREFIX + key);
    } catch (e) {}
};
const keys = () => {
    try {
        return Object.keys(localStorage).filter((k) => k.startsWith(PREFIX)).map((k) => k.slice(PREFIX.length));
    } catch (e) {
        return [];
    }
};

const collect = (form) => {
    const values = {};
    form.querySelectorAll('[data-draft-field]').forEach((el) => {
        if (!el.name) return;
        if (el.type === 'radio') {
            if (el.checked) values[el.name] = el.value;
        } else if (el.type === 'checkbox') {
            values[el.name] = el.checked;
        } else {
            values[el.name] = el.value;
        }
    });
    return values;
};

// ¿Hay algo distinto de cómo vino el formulario? (una prioridad por defecto no es un borrador)
const differs = (values, initial) => Object.keys(values).some((k) => {
    const v = values[k];
    return v !== initial[k] && !(typeof v === 'string' && v.trim() === '' && (initial[k] ?? '') === '');
});

const restore = (form, values) => {
    form.querySelectorAll('[data-draft-field]').forEach((el) => {
        if (!el.name || !(el.name in values)) return;
        const value = values[el.name];
        if (el.type === 'radio') {
            el.checked = el.value === value;
        } else if (el.type === 'checkbox') {
            el.checked = !!value;
        } else if (el.tagName === 'SELECT' && ![...el.options].some((o) => o.value === value)) {
            // Opciones que arma la página después (p. ej. los equipos del edificio).
            el.dataset.draftValue = value;
        } else {
            el.value = value;
        }
    });
};

const when = (at) => {
    const minutes = Math.round((Date.now() - at) / 60000);
    if (minutes < 1) return 'hace un momento';
    if (minutes < 60) return 'hace ' + minutes + ' min';
    const date = new Date(at);
    return 'del ' + date.toLocaleDateString('es-AR') + ' a las ' + date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
};

function setup(form) {
    const key = form.dataset.draft;
    const initial = collect(form);
    let saved = read(key);

    if (saved && (!saved.at || Date.now() - saved.at > MAX_AGE)) {
        clear(key);
        saved = null;
    }

    if (saved && form.dataset.draftRestore !== 'off' && differs(saved.values || {}, initial)) {
        restore(form, saved.values);
        const notice = form.querySelector('[data-draft-notice]');
        if (notice) {
            const label = notice.querySelector('[data-draft-when]');
            if (label) label.textContent = when(saved.at);
            notice.hidden = false;
            notice.querySelector('[data-draft-discard]')?.addEventListener('click', () => {
                done.add(key); // que el pagehide de la recarga no lo vuelva a guardar
                clear(key);
                location.reload();
            });
        }
    }

    let timer = null;
    const save = (submitted = false) => {
        if (frozen || done.has(key)) return;
        const values = collect(form);
        if (!differs(values, initial)) {
            clear(key); // volvió a quedar como al principio
            return;
        }
        write(key, { at: Date.now(), submitted, values });
    };
    const later = () => {
        clearTimeout(timer);
        timer = setTimeout(() => save(), 400);
    };

    form.addEventListener('input', later);
    form.addEventListener('change', later);
    window.addEventListener('pagehide', () => {
        if (form.dataset.submitting !== '1') save();
    });
    if (form.dataset.draftXhr === undefined) {
        form.addEventListener('submit', () => save(true));
    }
}

export function initDrafts() {
    // Borradores ya enviados de formularios que no están en esta página: el
    // envío salió bien (se llegó a otra pantalla de la app).
    keys().forEach((key) => {
        const draft = read(key);
        if (draft?.submitted && !document.querySelector('form[data-draft="' + CSS.escape(key) + '"]')) clear(key);
    });

    document.querySelectorAll('form[data-draft]').forEach(setup);

    // Al cerrar sesión no quedan textos en el celular.
    document.addEventListener('submit', (event) => {
        if (/\/logout$/.test(new URL(event.target.action, location.href).pathname)) {
            frozen = true;
            keys().forEach(clear);
        }
    });

    window.ascentoDrafts = {
        clear(key) {
            done.add(key);
            clear(key);
        },
    };
}
