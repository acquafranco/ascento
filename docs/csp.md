# Content-Security-Policy (R2): plan para probarla antes de aplicarla

**Estado:** la política existe solo en **modo reporte** y está **apagada por defecto** (`ASCENTO_CSP_REPORT_ONLY=false`). En modo reporte el navegador no bloquea nada: solo avisa en la consola lo que la política bloquearía. No hay ninguna dirección que reciba reportes, así que no se agrega nada nuevo que atender ni abusar.

**Dónde está:** `App\Http\Middleware\SecurityHeaders::policy()`.

## La política candidata

```
default-src 'self';
script-src 'self' 'unsafe-inline' 'unsafe-eval';
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob: https://maps.geoapify.com https://tile.openstreetmap.org;
font-src 'self' data:;
connect-src 'self' wss://<REVERB_HOST> ws://<REVERB_HOST>;
media-src 'self' blob:;
worker-src 'self'; manifest-src 'self';
frame-src 'none'; object-src 'none';
base-uri 'self'; form-action 'self'; frame-ancestors 'self'
```

**Por qué `'unsafe-inline'` y `'unsafe-eval'` en scripts:**
- Filament, Livewire y Alpine evalúan expresiones de los atributos (`x-data`, `wire:`).
- Hay scripts en línea: el asistente de la web, el aviso de cookies y el mapa.
- Sacarlos exige el build de Alpine compatible con CSP y pasar los scripts a archivos o *nonces*, que es una refactorización amplia.

**Qué protege igual:**
- no carga scripts, estilos ni imágenes de dominios que no estén en la lista;
- solo se conecta al propio sitio y a Reverb;
- no deja meter `<base>` ni `<object>`;
- los formularios solo se envían al propio sitio;
- nadie puede enmarcar Ascento.

Limita a dónde podría mandar datos un script inyectado.

## Prueba hecha (13/10/2026, demo local)

Con `ASCENTO_CSP_REPORT_ONLY=true` se recorrieron **29 páginas**:
- **visitante:** inicio con el asistente abierto, ingreso, registro, ingreso del portal y legales;
- **dueño:**
  - panel, mapa, edificios y edición de un edificio;
  - presupuesto, reportes, indicadores, paracaídas, QR, ayuda;
  - Mi empresa, suscripción y tareas;
- **técnico:** inicio, trabajos, visitas, reporte nuevo, remitos, perfil y ayuda;
- **portal:** inicio, documentos, mantenimientos y ayuda.

**Resultado:** 0 violaciones. La única que apareció al principio fue el WebSocket local en un puerto no estándar, y se corrigió para incluir el puerto. En producción Reverb va por el 443.

**Fuera de esa prueba** (hay que mirarlos en producción en modo reporte):
- el mapa con mosaicos de Geoapify, porque en local no hay clave y se usó OpenStreetMap;
- el pago con Mercado Pago, que es navegación y no está alcanzado por la CSP;
- la carga real de video;
- las notificaciones push del navegador.

## Plan para aplicarla

1. **Modo reporte en producción (1 o 2 semanas):** `ASCENTO_CSP_REPORT_ONLY=true` en Forge y volver a desplegar.
   - No cambia nada para los usuarios.
   - Revisar la consola del navegador (DevTools → Console, «Content-Security-Policy-Report-Only») en el mapa, la suscripción y la carga de un reporte con video, desde la computadora y el celular.
2. **Ajustar:** si aparece un origen legítimo (por ejemplo, otro servidor de mosaicos), agregarlo en `policy()` con su prueba.
3. **Aplicarla:** cambiar el nombre de la cabecera a `Content-Security-Policy`, detrás de otra variable (por ejemplo `ASCENTO_CSP_ENFORCE`), para poder volver atrás sin desplegar código.
4. **Endurecerla (opcional, después):** build de Alpine compatible con CSP y *nonces* para sacar `'unsafe-eval'` y `'unsafe-inline'`.
