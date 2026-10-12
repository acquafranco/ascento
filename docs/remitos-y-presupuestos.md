# Remitos, PDF, presupuestos y PWA (Etapa 4)

## Remito
- Los datos salen de `App\Support\DeliveryNotes\DeliveryNoteDocument`, iguales
  para la web y el PDF. Son los mismos campos que antes:
  - empresa, número, tipo y estado;
  - cliente, edificio y fecha;
  - período, equipos y técnico;
  - trabajo realizado y materiales (solo cantidades);
  - técnicos participantes y firmas.
- **Nunca** aparecen las observaciones internas de la orden (`notes`) ni los
  costos de los materiales.
- **Técnico y admin** (`delivery-notes.show`): botón **Compartir** (WhatsApp,
  correo, PDF y copiar enlace) desde el plan Profesional.
- **Enlace público:** el documento y **Descargar PDF**, sin acciones de la
  empresa.
- **Portal** (`portal.delivery-note`): vista propia con el diseño del portal y
  `PortalAccess::ensureShared` en la vista y en el PDF.
- **El remito no tiene fotos:** el modelo no las guarda. Las fotos de
  mantenimiento son de la Etapa 5.

## PDF real (dompdf)
| Quién | Ruta |
|---|---|
| Admin | `/{empresa}/delivery-notes/{n}/pdf` (sigue siendo solo de admins) |
| Técnico y cliente con el enlace | `/{empresa}/public/delivery-notes/{token}/pdf` |
| Portal | `/portal/remitos/{n}/pdf` |

- Logo embebido y color de la empresa, con el texto en blanco u oscuro según
  el contraste.
- Firmas, pie con número de página.
- Los bloques cortos no se parten entre páginas; el trabajo realizado sí
  continúa en la hoja siguiente.
- Fuente con *subsetting*: un remito pesa unos 30 KB.

## Presupuesto
- **En pantalla** (enlace, portal y admin): `quotes/partials/web.blade.php`.
  Es responsive: en celular cada ítem es un bloque, sin tablas anidadas ni
  anchos fijos.
- **PDF:** sigue con `quotes/document.blade.php`. Las dos usan los mismos
  datos (`App\Support\Quotes\QuoteDocument`). Se verificó que el HTML del PDF
  quedó idéntico al anterior.

## PWA de iPhone (barra superior)
- **Causa:** `viewport-fit=cover` y `black-translucent` hacen que, instalada
  en el iPhone, la página se dibuje debajo de la barra de estado.
- **Corrección:** `.t-nav-top` y la barra de progreso reservan
  `env(safe-area-inset-top)`. En Android y en los navegadores comunes eso vale
  0, así que no agrega espacio.
- **No se probó en un iPhone físico.** La emulación de Chrome no simula la
  zona segura.

Prueba manual pendiente en un iPhone:
1. Instalar Ascento en la pantalla de inicio desde Safari.
2. Abrirlo, entrar como técnico y comprobar que el logo y la campanita quedan
   por debajo de la hora y la batería.
3. Probar en horizontal.
4. Comprobar que la barra inferior no tapa los botones de un formulario
   largo.
