# Mercado Pago: qué falta para cobrar en producción

Auditoría del 23/10/2026 sobre la rama `mejora/marketplace-lanzamiento`.
**No se activó ningún cobro, no se hizo ningún pago y no se consultó Forge ni Mercado Pago.**
Lo que dice «en el servidor» lo tenés que contrastar vos con el `.env` de Forge.

Ascento cobra tres cosas con Mercado Pago. No comparten el mismo flujo:

| # | Qué | A quién | Flujo en Mercado Pago | Código | Tablas |
|---|---|---|---|---|---|
| 1 | Suscripción mensual a uno de los **tres planes** (Inicial, Profesional, Empresa) | Empresas de mantenimiento | Suscripción sin plan asociado (`/preapproval`), referencia `ascento-company-{id}` | `MercadoPagoSubscriptionSync`, página «Mi suscripción» (`Filament\Pages\Subscription`) | `subscriptions`, `subscription_payments` |
| 2 | **Premium** de proveedores (`proveedor-premium`) | Proveedores del marketplace | Suscripción sin plan asociado (`/preapproval`), referencia `ascento-supplier-{id}` | `MercadoPagoSupplierGateway`, `SupplierBillingSync`, `SupplierBillingController` | `supplier_subscriptions`, `supplier_payments` |
| 3 | **Destacados** de 7 y 30 días | Proveedores del marketplace | Pago único con Checkout Pro (`/checkout/preferences`, vence en 24 h), referencia `ascento-featured-{id}` | `MercadoPagoSupplierGateway`, `SupplierBillingSync`, `SupplierFeaturedController` | `featured_placements`, `supplier_payments` |

Las tres usan la misma cuenta de Mercado Pago, el mismo cliente HTTP (`MercadoPagoService`) y el mismo webhook (`POST /api/mercadopago/webhook`).

## 1. Variables de entorno

Estos son todos los nombres que el código lee hoy para cobrar (`config/services.php`, `config/app.php`). No hay otras variables de Mercado Pago.

### 1.a Compartidas por los tres flujos

| Variable | Obligatoria | Uso | Dónde obtenerla | Entorno | Cómo validarla |
|---|---|---|---|---|---|
| `MERCADOPAGO_ACCESS_TOKEN` | **Sí** | Token con el que Ascento crea suscripciones, preferencias y consulta pagos (`services.mercadopago.access_token`). Sin él no se empieza ningún cobro. | Mercado Pago → Tus integraciones → tu aplicación → Credenciales de producción (o de prueba, en el sandbox) → Access Token | Producción: el productivo de la cuenta de Ascento. Sandbox: el del usuario de prueba **vendedor**. | `php artisan mercadopago:check` (muestra solo los últimos 4 caracteres) y «Configuración de la plataforma → Verificar cuenta» en el SuperAdmin. |
| `MERCADOPAGO_WEBHOOK_SECRET` | **Sí** en producción | Clave para validar la firma `x-signature` de cada aviso (`services.mercadopago.webhook_secret`). En producción, sin ella se rechazan **todos** los avisos (401). | Mercado Pago → Tus integraciones → tu aplicación → Webhooks → Clave secreta | La de la aplicación de cada entorno. | `mercadopago:check` dice «configurada». En el panel de Mercado Pago, «Simular notificación» debe responder 200 (`not_found` es normal: el id de ejemplo no existe). |
| `APP_URL` | **Sí** | Base de las direcciones de vuelta del pago (`back_url`/`back_urls`) y de la URL del webhook. En producción tiene que empezar con `https://`. | Forge → sitio → Environment | Producción: `https://ascento.online` | `mercadopago:check` avisa si no es https. «Configuración de la plataforma» lo muestra como falta. |
| `APP_ENV` | **Sí** | Con `production`: un token `TEST-` se rechaza, la clave del webhook es obligatoria y la cuenta verificada tiene que ser **real**. | Forge → Environment | `production` | `php artisan about` |
| `APP_KEY` | **Sí** (ya existe) | Firma la huella **no reversible** del token verificado (`platform_settings.mp_token_fingerprint`). Si cambia, hay que volver a tocar «Verificar cuenta». | Ya existe; no se toca | Todos | — |
| `MERCADOPAGO_TEST_PAYER_EMAIL` | **No.** En producción tiene que quedar **vacía**. | Solo en el sandbox: correo del usuario de prueba **comprador**, que reemplaza al del cliente en el checkout. | Mercado Pago → Cuentas de prueba | Solo el sandbox | `mercadopago:check` marca un error si está cargada con credenciales reales. |

### 1.b Solo suscripciones de empresas

No usan otras variables además de las compartidas. Una suscripción nueva se bloquea si falta el token o si en producción el token es de prueba (`TEST-`): `BillingStatus::companyBlockers()`.

La falta de la clave del webhook o de la verificación de la cuenta **no** bloquea a las empresas, porque ese flujo ya funciona sin ellas. Solo se avisa en «Configuración de la plataforma». Sin la clave, el estado igual se confirma consultando la API: con «Actualizar estado» y con `subscriptions:reconcile-mercadopago` cada 6 horas.

### 1.c Solo Premium de proveedores

Tampoco usan otras variables. Un cobro nuevo se bloquea si falta **cualquiera** de estas condiciones (`SalesReadiness::gatewayProblems()`):
- token cargado, y no `TEST-` en producción;
- clave del webhook cargada;
- `APP_URL` con https en producción;
- cuenta verificada: de prueba fuera de producción, **real y de Argentina (MLA)** en producción.

### 1.d Solo destacados

Igual que 1.c. Además, en el panel de Mercado Pago el webhook tiene que tener encendido el evento **«Pagos»**: el destacado se confirma con el tema `payment`.

### 1.e Valores que NO son variables de entorno

Estos valores se guardan en la base de datos, y los edita el SuperAdmin.

| Valor | Dónde | Valor actual en el código | Notas |
|---|---|---|---|
| Precios y límites de los **tres planes de empresas** | Tabla `subscription_plans` (SuperAdmin → Planes) | Los cargó la migración. Los tests verifican Profesional = ARS 119.000. | No se tocaron en esta tarea. |
| Moneda de los planes de empresas | `subscription_plans.currency` | `ARS` | — |
| Precio de Premium | `marketplace_settings.premium_price` (SuperAdmin → Configuración del marketplace) | **Vacío**: no se vende | Precio objetivo ARS 19.900 en `config/marketplace.php` → `target_prices`. Se carga con «Usar precios objetivo» y **Guardar**. |
| Precios de destacados 7 / 30 días | `marketplace_settings.featured_price_7` / `_30` | La migración `2027_01_18` dejó ARS 5.000 / 15.000 **provisorios** y apagados | Precios objetivo ARS 4.900 / 9.900. Mismo botón. |
| ¿Se vende Premium? ¿Se venden destacados? | `premium_enabled`, `featured_enabled` | Apagados | No se encienden si falta algo de 1.c. |
| Países con cobro a proveedores | `marketplace_settings.billing_countries` + `config/marketplace.php` → `gateways` | `['AR']`, con Mercado Pago | Otro país necesita una pasarela en su moneda: no hay conversión. |
| Moneda de los precios a proveedores | `marketplace_settings.pricing_currency` | `ARS` | — |
| Verificación de la cuenta | `platform_settings.mp_account_*` | Sin verificar | «Verificar cuenta» llama a `/users/me`, que es de solo lectura. |
| Webhook | Panel de Mercado Pago | — | URL `https://ascento.online/api/mercadopago/webhook`, eventos «Planes y suscripciones» y «Pagos». |

### 1.f Nombres viejos que el código NO lee

El `.env` local tiene `MERCADOPAGO_PUBLIC_KEY`, `MERCADOPAGO_BASIC_PLAN_ID` y `MERCADOPAGO_PRO_PLAN_ID`. Ningún archivo del código los lee:
- los checkouts no usan la clave pública, porque la tarjeta se carga en Mercado Pago;
- las suscripciones no usan planes de Mercado Pago, sino suscripciones sin plan.

Si están en Forge, se pueden borrar. Dejarlos no cambia nada.

## 2. Qué hace el código hoy (verificado)

| Tema | Empresas | Premium | Destacados |
|---|---|---|---|
| Alta | `startCheckout`: no durante la prueba gratis, no con una suscripción activa, reutiliza un checkout pendiente de menos de 24 h y cancela uno viejo. | Igual, con tablas propias. | Una compra por pedido; la preferencia vence en 24 h. |
| Estado | **Solo** lo que informa Mercado Pago (webhook o API). El retorno del navegador solo dispara una consulta. | Igual. | Igual. El navegador no puede confirmar nada. |
| Firma del webhook | HMAC-SHA256 `id;request-id;ts`; sin firma válida, 401 y no se guarda nada. | Mismo webhook. | Mismo webhook. |
| Importe, moneda y referencia | Se comparan con lo guardado. Si no coinciden, no se activa y se registra. | Igual, y se avisa al equipo. | Igual. |
| Idempotencia | Cada cuota se guarda una vez por id de Mercado Pago. | Igual; un mes se suma una sola vez. | Se activa una sola vez. Un segundo pago queda marcado para devolver. |
| Renovación | Débito automático mensual de Mercado Pago. | Igual. | No hay: renovar es comprar otro período. |
| Rechazo | Mantiene el acceso 5 días (`PAST_DUE`) y después vence. | Igual; después vuelve al plan gratuito sin borrar nada. | No se activa. |
| Cancelación | «Cancelar suscripción»: sigue hasta el fin del período pago. | Igual. | Se cancela al ocultar, retirar, dejar fuera del límite o suspender. |
| Devolución | Una cuota devuelta queda **rechazada**. No hay pantalla de devoluciones; se hacen desde Mercado Pago. | Queda «devuelta», no vuelve a aprobada y se avisa al equipo. | El destacado se cancela y queda «devuelto». |
| Conciliación | `subscriptions:reconcile-mercadopago` cada 6 h. | `marketplace:reconcile-payments` cada 6 h. | La misma. |
| Vencimiento | `subscription:expire-manual` diario (solo altas manuales). | `marketplace:tick` cada 15 min. | `marketplace:tick` termina los vencidos una sola vez. |

Las tareas programadas necesitan el **scheduler de Forge**: `php artisan schedule:run` cada minuto.

## 3. Checklist antes de vender (marcar en orden)

**Sandbox:** un entorno que no sea producción, con credenciales de **usuarios de prueba**. El detalle de cada caso de Premium y destacados está en `docs/marketplace-pruebas-sandbox.md`.

- [ ] **Sandbox:**
  - token del vendedor de prueba en `MERCADOPAGO_ACCESS_TOKEN`;
  - correo del comprador de prueba en `MERCADOPAGO_TEST_PAYER_EMAIL`;
  - `APP_URL` con https (por ejemplo, un túnel).
- [ ] **Verificar cuenta:** en SuperAdmin → Configuración de la plataforma tiene que decir «Cuenta de PRUEBA».
- [ ] **Webhook:** cargar la URL `<APP_URL>/api/mercadopago/webhook` con los eventos «Planes y suscripciones» y «Pagos».
- [ ] **Firma:** con la clave cargada, «Simular notificación» responde 200. Un `curl` sin `x-signature` responde 401.
- [ ] **Pago aprobado:** probar empresa (plan Profesional), Premium (P1) y destacado (D1).
- [ ] **Pago rechazado:** empresa (`PAST_DUE` y aviso), P2 y D2.
- [ ] **Pago pendiente:** pagar en efectivo de prueba; nada se activa hasta que se acredita.
- [ ] **Evento duplicado:** reenviar el mismo aviso; no se suma otro mes ni se activa dos veces (W1).
- [ ] **Importe o moneda incorrectos:** se rechaza y se registra (lo cubre `SupplierBillingTest`, y en el sandbox no se puede forzar).
- [ ] **Cancelación:** empresa y Premium (P4); sigue hasta el fin del período.
- [ ] **Renovación:** esperar la segunda cuota o simularla. El período avanza un mes, una sola vez.
- [ ] **Expiración del destacado:** con 7 días (o en la base de prueba), `marketplace:tick` lo termina una vez.
- [ ] **Reconciliación:** con los webhooks apagados un momento, `marketplace:reconcile-payments` y `subscriptions:reconcile-mercadopago` ponen todo al día (C1).
- [ ] **Retorno manipulado:** `?mp=return&status=approved` sin pagar no activa nada (R1).

**Producción:**
- [ ] En Forge, revisar que `APP_ENV=production`, `APP_URL=https://ascento.online`, `MERCADOPAGO_ACCESS_TOKEN` (productivo), `MERCADOPAGO_WEBHOOK_SECRET` (de la app productiva) y `MERCADOPAGO_TEST_PAYER_EMAIL` **vacía**. Después, `php artisan optimize`.
- [ ] `php artisan mercadopago:check` termina en «Todo en orden» y la cuenta **no** dice «USUARIO DE PRUEBA».
- [ ] SuperAdmin → Configuración de la plataforma → **Verificar cuenta** → «Cuenta REAL», sin faltantes en los tres flujos.
- [ ] El webhook de la aplicación **productiva** tiene la URL y los dos eventos.
- [ ] El scheduler de Forge está activo.
- [ ] Recién entonces: Configuración del marketplace → «Usar precios objetivo» → revisar → encender Premium y/o destacados → Guardar.
- [ ] Hacer la primera compra real con una cuenta propia y un importe que estés dispuesto a pagar. Devolverla desde Mercado Pago y confirmar que queda «devuelta».

## 4. Decisiones pendientes (no se inventaron)

- **Facturación:** no hay comprobantes fiscales para empresas ni para proveedores.
- **Reembolsos:** no hay política propia más allá de la sección 18 de los Términos, que es un borrador sin revisión legal. Tampoco hay pantalla de devoluciones: se hacen desde Mercado Pago. Una cuota de empresa devuelta queda «rechazada» y no reabre ni cierra el período automáticamente.
- **Empresas de otros países:** el checkout de empresas no mira el país; cobra en la moneda del plan (ARS). Para no bloquear cuentas existentes, no se cambió. Decidí si las empresas de otros países pueden contratar online.
- **Destacado cancelado por moderación:** se cancela sin reembolso automático. La devolución, si corresponde, se decide caso por caso.
- **Cambio de precio de los planes de empresa:** rige para períodos futuros según los Términos. No hay aviso automático por correo.
