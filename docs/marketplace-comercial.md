# Marketplace de repuestos: modelo comercial, avisos y operación

Rama `mejora/marketplace-comercial` (sobre `main` con el PR #117). Completa lo descripto en
`docs/calculadora-y-repuestos.md`. Ascento **conecta** a compradores y proveedores: no cobra
comisiones por ventas, no procesa los pagos de repuestos y no gestiona envíos.

## 1. Auditoría previa (qué había y qué se reutilizó)

| Necesidad | Ya existía | Decisión |
|---|---|---|
| Cobro recurrente | Mercado Pago **preapproval sin plan** + webhook firmado (`x-signature`) + `MercadoPagoSubscriptionSync` + reconciliación cada 6 h, **solo para empresas** (`subscriptions.company_id` obligatorio, `restrictOnDelete`). | Mismo cliente HTTP (`MercadoPagoService`), misma cuenta, mismo webhook y misma validación de firma. Tablas propias (`supplier_subscriptions`, `supplier_payments`) para no mezclar ni modificar la tabla de cobros de empresas. Referencias propias `ascento-supplier-{id}`. |
| Pago único (destacados) | No existía. | Checkout Pro (`POST /checkout/preferences`) sobre la misma cuenta, sin costo adicional de integración. Se confirma solo con `GET /v1/payments/{id}` (webhook `payment` o reconciliación por `external_reference`). |
| Avisos | `Notifier` (deduplicación por evento, Reverb, Web Push, correo con `mailed_at`/`mail_failed_at`), solo para usuarios **con empresa**. | `Notifier::sendToAccount()` para cuentas sin empresa (proveedores y SuperAdmin) con las mismas garantías. Todos los avisos del marketplace pasan por `MarketplaceNotifier`. |
| Bandeja / push / tiempo real | Bandejas de técnico y portal, componente de push y `partials/realtime-inbox`. | El área de proveedores usa los mismos parciales y el mismo `PushSubscriptionController` (rutas propias en `/proveedores`). |
| Scheduler | `routes/console.php` con `schedule:run` cada minuto en Forge (docs/operacion-produccion.md §6). | Dos tareas nuevas registradas ahí. |
| Unicidad de correos | `users.email_key` (columna generada `lower(trim(email))`) con índice único, `UniqueUserEmail` en todos los altas y cambios, `users:email-conflicts` para históricos. Todas las cuentas (SuperAdmin, dueños, administrativos, técnicos, portal y proveedores) están en `users`. | No se reimplementó. Se agregó la prueba cruzada proveedor ↔ resto de los roles. Los correos de **contacto** (empresa, clientes, proveedor de ventas) no son cuentas y no se restringen. |
| Borrado | `CompanyEraser` (SuperAdmin) borraba en cascada los pedidos de repuestos (historial del proveedor). | Corregido: se conservan sin datos de la empresa. Nuevo `SupplierEraser`. |

## 2. Modelo definitivo: plan gratuito con límite + Premium opcional

**Moderación** (SuperAdmin) y **plan** son independientes:
- Pagar nunca aprueba ni levanta una suspensión.
- No pagar nunca deja a un proveedor sin publicar.
- No hay prueba gratuita: el plan gratuito no vence.

| Estado (`SupplierStanding`) | Catálogo visible y pedidos nuevos | Límite de publicaciones |
|---|---|---|
| Pendiente de aprobación | No (puede armar el catálogo) | Gratuito |
| Rechazado / Suspendido / Cuenta cerrada | No | — |
| **Plan gratuito** | Sí | `free_product_limit` (20) |
| **Premium** (período pagado, o 5 días de tolerancia tras un cobro rechazado) | Sí | `premium_product_limit` (1.000) |

### Límite de publicaciones (`PublicationLimits`)
- **Qué cuenta:** productos en estado `published` que no están ocultos por moderación. Un producto es una publicación, tenga 1 o 10.000 unidades.
- **Qué no cuenta:**
  - borradores;
  - pausados;
  - retirados;
  - ocultos por moderación;
  - «fuera del límite» (`over_limit`).
- **Control:** publicar siempre pasa por `PublicationLimits::publish()`. Esa función bloquea la fila del proveedor (`SELECT … FOR UPDATE`) y recién después cuenta, en la misma transacción, así que dos pedidos simultáneos no pasan el límite. Además lo verifiqué en MySQL con 6 procesos en paralelo.
- **Lleno:**
  - Mensaje claro.
  - El producto nuevo queda como borrador.
  - Se puede pausar o retirar otro, o contratar Premium si está a la venta en su país.
- **Restaurar una publicación oculta por moderación:** si no entra, queda ella «fuera del límite»; nunca desplaza a otra.

### Cuando Premium vence o se cancela
- Se vuelve al plan gratuito sin borrar nada: productos, fotos, perfil, pedidos y cotizaciones se conservan.
- `marketplace:tick`, cada 15 minutos, detecta las publicaciones que exceden el límite y las pasa a `over_limit`. Ese estado es **distinto** de «oculto por moderación».
- El orden para decidir cuáles quedan publicadas:
  1. las que el proveedor marcó «Conservar publicado», hasta el límite gratuito;
  2. las que tienen un destacado vigente;
  3. la regla configurada: «más recientes» (por defecto) o «más antiguas», por fecha de primera publicación, y a igualdad, el id.
- Los destacados de los productos que quedan fuera del límite se cancelan.
- Se avisa al proveedor.
- Puede cambiar cuáles publica en cualquier momento, siempre dentro del límite.
- Al reactivar Premium, «Volver a publicar los que entran» republica lo que quedó fuera del límite (no lo oculto por moderación).
- **Sin demora:** la visibilidad pública (`SupplierProduct::visible()`, que usa `PublicationLimits::withinLimit()`) aplica el límite del plan vigente en el momento, con el mismo orden que la tarea. Cuando vence un Premium, lo que excede deja de verse en ese instante y no se puede pedir. La tarea después marca los estados y avisa.

### Otros datos
- **País:** el registrado y validado con la identificación fiscal; el proveedor no puede cambiarlo. La moneda de sus precios sale del país (`config/marketplace.php`).
- **Cobros:** solo en los países de «Cobros por país» que tengan pasarela **y** usen la moneda de los precios (hoy, Argentina en ARS). A los demás no se les muestra ninguna opción de pago.

## 3. Cobros

- **Premium:**
  - El precio sale de «Configuración del marketplace»; nunca del navegador.
  - Se activa solo con un cobro aprobado por el importe y la moneda esperados.
  - Mientras está pendiente, el proveedor sigue en el plan gratuito.
  - Cuotas duplicadas o fuera de orden no suman ni retroceden.
  - Un importe o una referencia distintos no se aplican y se avisa al equipo.
  - Cancelar llama a Mercado Pago; Premium sigue hasta el fin del período pago.
- **Destacados** (7 y 30 días):
  - Precios iniciales preparados: ARS 5.000 y ARS 15.000, **apagados** hasta que los confirmes.
  - Se venden en cualquier plan, solo en países con cobro.
  - Pendiente de pago → activo **solo** con el pago aprobado por el precio configurado.
  - Dos clics reutilizan el checkout pendiente; la preferencia vence en 24 h.
  - Renovar es otra compra, que empieza cuando termina la vigente. Vencen solos.
  - Si el producto deja de ser elegible antes del pago, queda «Pagado: requiere revisión» y se avisa al equipo.
  - Una devolución o un contracargo lo cancela.
  - Ocultar, retirar o pasar a «fuera del límite» termina el destacado.
- **Pago duplicado de un destacado:** una preferencia de Checkout Pro se puede pagar más de una vez. Un segundo pago aprobado para la misma compra no activa otro período: queda marcado «Pago duplicado: devolver» y se avisa al equipo.
- **Cobros aprobados:** solo una devolución o un contracargo los cambia. Un aviso viejo «pendiente» o «rechazado» no los retrocede. Si se devuelve una cuota de Premium, queda «Devuelto» y se avisa al equipo, pero el período no se recorta solo.
- **Devoluciones definitivas:** un cobro «Devuelto» no vuelve a «Aprobado» por un aviso posterior. Cada cuota suma su mes una sola vez, y un destacado devuelto no se reactiva. Si Mercado Pago revierte un contracargo, lo resuelve el equipo a mano.
- **Duplicados simultáneos:** quién es el primer pago se decide con el destacado bloqueado (`FOR UPDATE`). Cuenta cualquier pago ya acreditado de esa compra, aunque después se haya devuelto.
- **Pagos de destacados cancelados:** solo un checkout vencido sin pago se activa si se acredita tarde. Si Ascento, la moderación, una suspensión o el cierre de la cuenta ya lo habían cancelado, queda «Requiere revisión» y se avisa al equipo.
- **Suspensión:** suspender a un proveedor cancela sus destacados activos (sección 18 de los términos), y reactivar la cuenta no los revive.
- **Devoluciones** (procedimiento administrativo):
  1. El proveedor la pide por correo.
  2. El SuperAdmin revisa el caso en «Destacados de repuestos» y «Cobros a proveedores».
  3. Si corresponde, devuelve desde el panel de Mercado Pago.
  4. El aviso de devolución que llega por webhook o por la reconciliación deja el cobro como «Devuelto» y cancela el destacado. No hay devoluciones automáticas.
- **Orden del catálogo** (`CatalogRanking`):
  1. Relevancia (palabras en nombre, modelo, código o marca).
  2. Compatibilidad declarada.
  3. Disponibilidad.
  4. Destacado vigente, solo entre iguales en 1–3.
  5. Rotación diaria por empresa.

  El navegador no puede cambiar el orden.
- **Ingresos** (SuperAdmin → «Cobros a proveedores»): solo cobros `approved`. Nunca pagos pendientes ni el valor de pedidos entre las partes.

## 4. Matriz de avisos

Canales:
- **App**: notificación en la campanita o la bandeja, con tiempo real por Reverb si está activo.
- **Push**: solo si la persona lo activó en ese dispositivo.
- **Correo**: queda `mailed_at` si el proveedor de correo lo aceptó, o `mail_failed_at` si falló. Nunca se registra como leído.

| Evento | Destinatario | App | Push | Correo |
|---|---|---|---|---|
| Registro recibido | Proveedor | ✓ | – | (sale el correo de confirmación de cuenta) |
| Proveedor nuevo pendiente | SuperAdmin | ✓ | – | ✓ |
| Aprobado / rechazado / suspendido / reactivado (con motivo) | Proveedor | ✓ | ✓ | ✓ |
| Proveedor suspendido o cerrado | Compradores con pedidos abiertos | ✓ | ✓ | ✓ |
| Pedido de cotización nuevo | Proveedor | ✓ | ✓ | ✓ |
| Pedido enviado (constancia) | Comprador que lo envió | ✓ | – | – |
| Cotización recibida / actualizada / no puede cotizar | Comprador | ✓ | ✓ | ✓ |
| Cotización vence en 24 h | Comprador | ✓ | ✓ | ✓ |
| Cotización vencida | Comprador / Proveedor | ✓ | ✓ | – |
| Aceptada / rechazada / cancelada / recepción confirmada | Proveedor | ✓ | ✓ | ✓ |
| Lo mismo, si lo hizo otra persona de la empresa | Quien creó el pedido | ✓ | ✓ | – |
| Comentario de la otra parte | Comprador o proveedor | ✓ | ✓ | ✓ |
| Incidencia reportada | SuperAdmin | ✓ | – | ✓ |
| Incidencia resuelta / pedido anulado por Ascento | Ambas partes | ✓ | ✓ | ✓ |
| Premium cancelado / termina en 3 días / terminó (volvió al plan gratuito) / activo | Proveedor | ✓ | ✓ | ✓ |
| Productos que quedaron «fuera del límite» | Proveedor | ✓ | ✓ | ✓ |
| Cobro aprobado / rechazado | Proveedor | ✓ | ✓ | ✓ |
| Cobro pendiente | Proveedor | ✓ | ✓ | – |
| Importe distinto, destacado pagado sin poder activarse, devolución | SuperAdmin | ✓ | – | ✓ |
| Publicación ocultada / retirada / restaurada por moderación | Proveedor | ✓ | ✓ | ✓ |
| Destacado activo / vence mañana / vencido / cancelado | Proveedor | ✓ | ✓ | ✓ (vencido: sin correo) |
| Reporte de una publicación | SuperAdmin | ✓ | – | – |

**Garantías de entrega:**
- Cada aviso tiene una clave única por destinatario, así que repetir el evento o la tarea no lo duplica.
- Proveedores: solo usuarios de ESE proveedor, con la cuenta sin cerrar.
- Compradores: quien hizo el pedido mientras pueda ver Repuestos; si no, los dueños de esa empresa.
- Los enlaces apuntan a rutas existentes que vuelven a verificar los permisos.
- «Pausada por moderación» no existe: el SuperAdmin oculta o retira, y ambos casos se avisan.

## 5. Tareas programadas (Forge: `schedule:run` cada minuto, ya activo)

| Comando | Cuándo | Qué hace |
|---|---|---|
| `marketplace:tick` | cada 15 minutos | Avisos de cotizaciones por vencer y vencidas; vencimiento y aviso de destacados; checkouts abandonados; cambio de plan (Premium ↔ gratuito) con aviso; publicaciones que exceden el límite → «fuera del límite»; aviso de fin de un Premium cancelado. Idempotente (marcas con UPDATE condicional + claves de aviso). |
| `marketplace:reconcile-payments` | cada 6 h | Relee de Mercado Pago las suscripciones de proveedores y los destacados pendientes de los últimos 3 días, por si se perdió un webhook. |

Zona horaria: `America/Argentina/Buenos_Aires`. Una cotización vence a las 23:59 del último día de validez.

## 6. SuperAdmin (Plataforma)

- **Proveedores de repuestos:**
  - aprobar, rechazar, suspender, reactivar;
  - notas internas;
  - cancelar la suscripción;
  - cerrar y eliminar datos;
  - plan y uso del límite, con filtros (Premium, gratuito, Premium pendiente, cancelado, cobro rechazado, con productos fuera del límite);
  - cobros, destacados e historial de acciones.
- **Publicaciones de repuestos:**
  - búsqueda;
  - filtros por proveedor, estado, rubro, destacados vigentes o vencidos, reportes abiertos;
  - ocultar, retirar, restaurar, destacar y quitar destacado.
- **Pedidos de repuestos (global):**
  - todos los pedidos entre empresas y proveedores: productos, importes, vencimientos, historial (solo lectura) e incidencias;
  - acciones: «Resolver incidencia» y «Anular pedido» con motivo visible para las partes, auditado y sin cambiar importes.
- **Cobros a proveedores:** historial de cobros e ingresos confirmados.
- **Destacados de repuestos:** historial y cancelación con motivo.
- **Configuración del marketplace:**
  - límites del plan gratuito y de Premium;
  - regla para cuando un Premium vuelve al plan gratuito;
  - precio y venta de Premium, que exige precio y Mercado Pago configurado;
  - países con cobro (solo los que tienen pasarela);
  - precios y venta de destacados.
- **Auditoría:** todas las acciones quedan en `platform_audit_logs`.

## 6b. Catálogo, fichas y fotos (rama mejora/marketplace-lanzamiento)

**Fotos de cada producto**
- Hasta `Catalog::MAX_PHOTOS` (6) por producto, de hasta 8 MB cada una (`Catalog::MAX_PHOTO_KB`), en JPG, PNG o WebP.
- El proveedor puede:
  - subir varias a la vez, con vista previa y aviso inmediato de formato, tamaño y cantidad en el navegador;
  - elegir la principal, que es la primera y la que se ve en el catálogo;
  - moverlas antes o después y quitarlas.
- El servidor:
  - vuelve a validar todo;
  - vuelve a codificar cada imagen (contenido real, sin metadatos, nombre aleatorio; nunca el del navegador);
  - con el producto bloqueado, no deja pasar del máximo;
  - si un archivo falla, no guarda ninguno ni deja archivos sueltos.
- Cada operación busca la foto dentro del producto y del proveedor de quien pide; cualquier otra combinación da 404.
- Las fotos se sirven desde el disco privado, solo al proveedor dueño, al SuperAdmin o a una empresa compradora mientras el producto esté visible.
- No hizo falta migración: `supplier_product_photos.position` ya existía, y las fotos anteriores mantienen su orden.

**Catálogo de las empresas** (`/admin/repuestos`)
- Tarjetas con:
  - foto principal en 4:3 sin deformar, con la cantidad de fotos;
  - rubro, nombre, marca y modelo, y código;
  - compatibilidad declarada (abreviada), proveedor, precio (o «A cotizar») y disponibilidad;
  - «Ver detalle» y «Agregar».
- Favorito y comparar quedan como íconos sobre la foto.
- En el celular, tarjetas horizontales y filtros en dos columnas.
- Estados vacío y «Buscando…».

**Ficha**
- Galería deslizable con flechas, teclado, contador, miniaturas y «Ver en tamaño completo».
- Datos ordenados y compatibilidad en un recuadro «declarada por el proveedor».
- Cantidad con +/− y «Agregar al pedido de cotización».

**Filtros**
- La flecha cortada de los selectores venía de usar la apariencia nativa con el mismo relleno simétrico y los bordes redondeados de un campo de texto: el indicador quedaba pegado o recortado en el borde.
- Ahora el selector (`.mk-select`) dibuja su propia flecha en SVG, con espacio reservado a la derecha y recorte «…» en textos largos.
- `.mk-input` usa `background-color` para no borrar esa flecha.

**Presentación pública**
- La Welcome (`/`) sigue presentando la gestión de la empresa: es la versión de `df9085a`, anterior al rediseño del 23/10/2026, que se revirtió a pedido del dueño. Solo suma la sección «Marketplace de repuestos» (`partials/landing-marketplace.blade.php`, después de «Vista previa» y antes de los planes) y un enlace en el pie.
- La página comercial del marketplace es `/marketplace` (ruta `marketplace`, vista `marketplace/landing.blade.php`). Sus botones van a rutas reales:
  - «Explorar repuestos»: el catálogo del panel (`/admin/repuestos`); sin sesión, pasa por el ingreso;
  - «Publicar mi catálogo»: `suppliers.register`;
  - «Ya tengo cuenta»: `login`.
- El límite gratuito sale de `MarketplaceSettings`; Premium solo aparece si está a la venta y `SalesReadiness::gatewayReady()`.
- Capturas reales con datos ficticios: ver `docs/landing-capturas.md`, «Marketplace de repuestos».

## 7. Precios y decisiones comerciales

Ventas: **apagadas**. Premium y los destacados no se ofrecen hasta que el SuperAdmin los encienda en «Configuración del marketplace» (y además el país del proveedor tenga cobro habilitado).

| Concepto | Valor por defecto de la migración 2027_01_18 | Propuesta comercial (cargar en el panel; no hay migración de precios) |
|---|---|---|
| Plan gratuito | 20 publicaciones, para siempre | sin cambios |
| Premium | 1.000 publicaciones, **sin precio** (no se ofrece) | ARS 19.900 por mes |
| Destacado 7 días | ARS 5.000, apagado | ARS 4.900 |
| Destacado 30 días | ARS 15.000, apagado | ARS 9.900 |
| Países con cobro | Argentina (ARS) | sin cambios |

- Los precios de producción reales se ven con la consulta 2 de `docs/sql/auditoria-marketplace.sql` (solo lectura).
- Cada compra guarda su precio y su moneda: cambiar la configuración no altera suscripciones ni destacados ya creados.
- Los términos muestran los precios vigentes solo mientras se venden, y los países con cobro según la configuración.

Pendiente de decisión: la regla cuando un Premium vuelve al plan gratuito sin elegir («más recientes», por defecto, o «más antiguas»); cobrar en otros países (requiere una pasarela y precios en su moneda). Comisión por ventas: **no implementada** y fuera de alcance.

## 7b. Migraciones de esta etapa

- `2027_01_18_100000_marketplace_commercial_model`: ya está en `main` (PR #118). **No se edita más**; si ya corrió en algún entorno, cualquier cambio va en una migración nueva.
- `2027_01_19_100000_backfill_admin_featured_placements`: pasa al historial los destacados vigentes que el SuperAdmin asignó antes (solo `featured_until`). Se puede correr más de una vez, y `down()` quita solo lo que creó.

## 8. Cómo habilitar las ventas (nada de esto se hizo todavía)

Qué se verificó en el código y con pruebas automatizadas (respuestas simuladas de Mercado Pago, nunca la API real):

- **Webhook** (`/api/mercadopago/webhook`): exige la firma `x-signature` (HMAC-SHA256 con `MERCADOPAGO_WEBHOOK_SECRET`); en producción, sin la clave, rechaza todo. El cuerpo nunca decide el estado: siempre se vuelve a consultar a la API.
- **Importe, moneda y referencia:** un cobro solo cuenta si coinciden con lo que Ascento creó (`external_reference`, importe ±0,01 y moneda); si no, queda «importe distinto», no activa nada y avisa al equipo.
- **Idempotencia:** el mismo aviso repetido no suma otro mes ni activa dos veces; un segundo pago de un mismo destacado se marca como duplicado para devolver.
- **Devoluciones y contracargos:** son definitivos; un destacado devuelto se cancela; un aviso viejo no revive un cobro devuelto.
- **Cancelación:** Premium sigue hasta el fin del período pago y no vuelve a cobrar.
- **Retorno del navegador** (`?mp=return&status=approved…`): solo dispara una consulta a la API; los parámetros de la URL nunca activan nada.
- **Conciliación:** `marketplace:reconcile-payments` (cada 6 h) relee lo que pudo perderse.
- **Proveedores de otros países:** usan el plan gratuito sin ver opciones de pago ni poder contratar.

**Bloqueos automáticos** (`App\Support\Marketplace\SalesReadiness`):
- «Configuración del marketplace» no guarda una venta encendida si falta alguno de estos requisitos:
  - `MERCADOPAGO_ACCESS_TOKEN`; en producción, además, que no sea `TEST-`;
  - `MERCADOPAGO_WEBHOOK_SECRET`;
  - un precio válido: mayor a cero y con 2 decimales como máximo;
  - al menos un país con pasarela;
  - la moneda de los precios igual a la de esos países.
- La misma página muestra qué falta en el servidor, con el nombre de cada variable y nunca su valor.
- Aunque la venta quede encendida, si después falta el token o la clave, no se ofrece ni se empieza ningún cobro nuevo. Cancelar un Premium ya pagado sigue funcionando solo con el token, para que nadie quede cobrado sin poder darse de baja.

**Qué falta hoy para cobrar:**
1. **Saber de qué cuenta es la credencial de tu `.env` local.** Empieza con `APP_USR-`, igual que las de usuarios de prueba. Lo dice `php artisan mercadopago:check`, que solo consulta la cuenta.
2. **`MERCADOPAGO_WEBHOOK_SECRET`:** no está en el `.env` local. En producción no lo sé.
3. **Webhook «Pagos» activo en la app de Mercado Pago:** lo necesitan los destacados.
4. **Las pruebas de sandbox** de `docs/marketplace-pruebas-sandbox.md`.
5. **Los precios aprobados**, cargados en el panel.

**Pasos, en orden** (con aprobación expresa para cada uno):
1. Sandbox completo según `docs/marketplace-pruebas-sandbox.md`, en un entorno que no sea producción.
2. En producción:
   - `php artisan mercadopago:check`: debe decir que la cuenta **no** es de prueba, y no debe marcar problemas;
   - `MERCADOPAGO_WEBHOOK_SECRET` cargada;
   - webhooks «Planes y suscripciones» y «Pagos» activos con `https://ascento.online/api/mercadopago/webhook`;
   - `php artisan optimize`.
3. En el panel, cargar los precios aprobados y recién entonces encender «Vender Premium» y «Vender destacados».
4. Comprobar con la consulta 2 de `docs/sql/auditoria-marketplace.sql`.

## 9. Cláusulas que requieren revisión profesional (`LEGAL_REVIEWED=false`)

- Términos 17–18: rol de intermediario sin comisión, exclusión de responsabilidad por la compraventa entre partes, suspensión de proveedores y cancelación de destacados, devoluciones.
- Términos 20 y Privacidad 01/05: ley aplicable y transferencias para usuarios de otros países.
- Conservación de cobros a proveedores (plazo fiscal) y conservación del historial de la otra parte tras un borrado.
- Fecha de versión (`config/legal.php`): ajustarla a la fecha de publicación real.
- Lista completa y actualizada: `docs/marketplace-limpieza-profesionales.md`, sección «Cuestiones legales pendientes».
