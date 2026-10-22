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
- **Demora:** hasta que corre la tarea (máximo 15 minutos), los productos de más siguen visibles.

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

## 7. Decisiones comerciales pendientes

1. Precio mensual de Premium. Hoy está vacío, así que Premium no se ofrece.
2. Confirmar los precios de los destacados (ARS 5.000 y 15.000 preparados) y encender «Vender destacados».
3. Confirmar los límites: 20 en el plan gratuito y 1.000 en Premium, ambos editables.
4. La regla para cuando un Premium vuelve al plan gratuito y no eligió: «más recientes» o «más antiguas».
5. Si en el futuro se cobra en otros países, hace falta una pasarela y precios en su moneda.
6. Comisión por ventas: **no implementada** y fuera de alcance.

## 8. Para cobrar de verdad (pendiente de verificar con credenciales reales)

1. En el panel de Mercado Pago (app de producción), activar los webhooks:
   - de **«Planes y suscripciones»**, que ya está;
   - de **«Pagos»**, nuevo, para destacados.

   La URL es la misma: `https://ascento.online/api/mercadopago/webhook`, y la clave secreta, la misma (`MERCADOPAGO_WEBHOOK_SECRET`).
2. Probar en sandbox con usuarios de prueba:
   - suscripción → cuota aprobada → período;
   - destacado → pago aprobado → activo;
   - cancelación;
   - pago rechazado.
3. Cargar precios y encender los cobros desde «Configuración del marketplace».

No se probó contra la API real: las pruebas usan respuestas simuladas con el formato documentado de Mercado Pago.

## 9. Cláusulas que requieren revisión profesional (`LEGAL_REVIEWED=false`)

- Términos 17–18: rol de intermediario sin comisión, exclusión de responsabilidad por la compraventa entre partes, suspensión de proveedores y cancelación de destacados, devoluciones.
- Términos 20 y Privacidad 01/05: ley aplicable y transferencias para usuarios de otros países.
- Conservación de cobros a proveedores (plazo fiscal) y conservación del historial de la otra parte tras un borrado.
- Fecha de versión (`config/legal.php`): ajustarla a la fecha de publicación real.
