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

## 2. Estados del proveedor (`SupplierStanding`)

Moderación (SuperAdmin) y estado comercial son independientes. **Pagar nunca aprueba ni levanta una suspensión.**

| Estado | Catálogo visible y pedidos nuevos |
|---|---|
| Pendiente de aprobación / Rechazado / Suspendido por moderación / Cuenta cerrada | No |
| Aprobado, en período de prueba (empieza **una sola vez**, en la primera aprobación) | Sí |
| Suscripción activa (período pago vigente o tolerancia de 5 días tras un cobro rechazado) | Sí |
| Aprobado, sin cargo (Argentina con los cobros sin habilitar) | Sí |
| Aprobado, modalidad gratuita internacional (país sin pasarela, opción del SuperAdmin) | Sí |
| Prueba vencida / Suscripción pendiente de pago / Suscripción vencida o cancelada / Sin modalidad en su país | No |

- La regla se calcula **en el momento** (PHP y SQL), no depende de la tarea programada.
- Al dejar de operar **no se borra nada**. Lo publicado vuelve a verse al reactivarse; lo oculto o retirado por moderación no.
- No puede publicar ni cargar productos nuevos, ni cotizar. Puede editar, pausar, declinar pedidos, comentar y gestionar su suscripción.
- Lo ya aceptado sigue: el comprador lo completa o lo cancela. **Regla elegida:** nada se cancela automáticamente al vencer, porque cancelar dejaría a las dos partes sin el historial de una compra ya acordada fuera de Ascento.
- Una cotización de un proveedor que no opera no se puede aceptar. El comprador recibe un aviso con sus pedidos abiertos.
- El país es el registrado y validado con la identificación fiscal; el proveedor no lo puede cambiar. La moneda de sus precios sale del país (`config/marketplace.php`).

## 3. Cobros

- **Suscripción:**
  - El precio sale de «Configuración del marketplace»; nunca del navegador.
  - Se puede contratar durante la prueba: el primer mes pago empieza al terminar la prueba.
  - Activa solo con un cobro aprobado por el importe y la moneda esperados.
  - Cuotas duplicadas o fuera de orden no suman ni retroceden.
  - Importe o referencia distintos: no se aplica y se avisa al equipo.
  - Cancelar llama a Mercado Pago y el acceso sigue hasta el fin del período pago.
- **Destacados** (7 y 30 días):
  - Pendiente de pago → activo **solo** con `payment.status = approved` por el precio configurado.
  - Dos clics reutilizan el checkout pendiente; la preferencia vence en 24 h.
  - Si el producto deja de ser elegible antes del pago: «Pagado: requiere revisión» y aviso al equipo; la devolución es manual.
  - Una devolución o un contracargo lo cancela.
  - Ocultar o retirar por moderación termina los destacados activos.
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
| Proveedor suspendido o deja de operar | Compradores con pedidos abiertos | ✓ | ✓ | ✓ |
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
| Prueba termina en 3 días / terminó | Proveedor | ✓ | ✓ | ✓ |
| Suscripción cancelada, vence en 3 días, vencida, activa otra vez | Proveedor | ✓ | ✓ | ✓ |
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
| `marketplace:tick` | cada hora | Avisos de cotizaciones por vencer y vencidas; vencimiento y aviso de destacados; checkouts abandonados; cambios de estado comercial (con aviso al proveedor y a compradores con pedidos abiertos); recordatorios de fin de prueba o período. Idempotente (marcas con UPDATE condicional + claves de aviso). |
| `marketplace:reconcile-payments` | cada 6 h | Relee de Mercado Pago las suscripciones de proveedores y los destacados pendientes de los últimos 3 días, por si se perdió un webhook. |

Zona horaria: `America/Argentina/Buenos_Aires`. Una cotización vence a las 23:59 del último día de validez.

## 6. SuperAdmin (Plataforma)

- **Proveedores de repuestos:**
  - aprobar (empieza la prueba), rechazar, suspender, reactivar;
  - notas internas;
  - cancelar la suscripción;
  - cerrar y eliminar datos;
  - estado comercial con filtros (en prueba, vence en 7 días, activa, pendiente, cancelada, cobro rechazado, habilitados, sin habilitación);
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
  - días de prueba;
  - precio mensual y cobro habilitado, que exige precio y Mercado Pago configurado;
  - modalidad gratuita internacional;
  - precios de destacados.
- **Auditoría:** todas las acciones quedan en `platform_audit_logs`.

## 7. Decisiones comerciales pendientes

1. Precio de la suscripción de proveedores (hoy vacío: no se cobra ni se muestra).
2. Precios de destacados de 7 y 30 días (hoy vacíos: no se venden).
3. Cuándo habilitar el cobro. Al habilitarlo, los proveedores argentinos con la prueba terminada y sin suscripción dejan de verse hasta suscribirse; la pantalla de configuración muestra cuántos son.
4. Si se mantiene la modalidad gratuita para otros países (hoy encendida).
5. Duración de la prueba (30 días por defecto; editable).
6. Política de devoluciones de destacados pagados que no se pudieron activar.
7. Comisión por ventas: **no implementada** y fuera de alcance. Requeriría Mercado Pago Marketplace (OAuth por proveedor y `marketplace_fee`) y revisión legal.

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
