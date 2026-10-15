{{--
    Si el navegador bloquea el almacenamiento (modo privado estricto, cookies
    bloqueadas), leer localStorage tira un error y Alpine no arranca: el menú
    y los botones dejarían de responder. En ese caso se usa una memoria
    temporal de la página (no se recuerda nada entre páginas).
--}}
<script>
    (() => {
        try { const s = window.localStorage; s.setItem('ascento.probe', '1'); s.removeItem('ascento.probe'); return } catch (e) {}
        const data = new Map()
        const memory = {
            getItem: (k) => (data.has(String(k)) ? data.get(String(k)) : null),
            setItem: (k, v) => { data.set(String(k), String(v)) },
            removeItem: (k) => { data.delete(String(k)) },
            clear: () => data.clear(),
            key: (i) => [...data.keys()][i] ?? null,
            get length() { return data.size },
        }
        try { Object.defineProperty(window, 'localStorage', { value: memory, configurable: true }) } catch (e) {}
    })()
</script>
