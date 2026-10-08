# Ascento — Análisis técnico de las etapas 1 a 3

Fecha: 2026-10-07. Rama de trabajo: `etapa-1-mapa-edificios` (sale de `onboarding-admin`).
Línea base antes de tocar código: 196 tests (194 OK, 2 skipped).

---

## ETAPA 1 — Mapa / sectorización de edificios (IMPLEMENTADA)

### Qué existía
- `buildings` guarda la dirección así (igual desde el primer commit, `BuildingForm`):

  | Columna        | Significado real            | Notas                                   |
  |----------------|-----------------------------|-----------------------------------------|
  | `name`         | **Calle** (no el nombre)    | ej. "Av. Cabildo"                       |
  | `address`      | **Número** (no la dirección)| el form lo valida como entero           |
  | `locality`     | Localidad                   | opcional, agregada después del 1er commit|
  | `municipality` | Municipio / partido         | opcional                                |
  | `neighborhood` | Barrio                      | opcional                                |
  | `province`     | Provincia                   | texto libre ("CABA", "Bs As"...)        |
  | —              | Código postal               | **no existe**                           |
  | —              | País                        | **no existe** (Ascento opera en AR)     |

  `companies` tiene `province` y `city`: la provincia de la empresa sirve de valor por defecto.
- Solo Filament crea/edita edificios (no hay otro camino de escritura).
- **No hay registros reales para revisar en local**: el MySQL de docker tiene 0 edificios y la
  SQLite de `database/` está vacía. No accedí a producción. El análisis de formato se hizo sobre
  el formulario, su historial en git y las variantes previsibles (registros viejos sin localidad,
  "CABA"/"Capital Federal", número con piso, etc.). Antes de geocodificar producción conviene
  correr `php artisan buildings:geocode --dry-run` para ver exactamente qué se enviaría.

### Qué se hizo
Ver el resumen de la etapa en el mensaje de entrega / commit.

---

## ETAPA 2 — Suscripción mensual con Mercado Pago (AUDITORÍA)

### Qué existe
| Pieza | Estado |
|---|---|
| `MercadoPagoService` | Cliente REST propio (`/preapproval`, `/authorized_payments`, pausar/cancelar/reanudar). Correcto en lo básico: GET con reintento, POST/PUT sin reintento. **Reutilizable.** |
| `mercadopago/dx-php` (composer) | Instalado pero **no se usa** en ningún lado. |
| `Filament\Pages\Subscription` | Flujo completo de checkout/pausa/cancelación/reanudación, pero **todos los botones de MP están comentados** en la vista; hoy solo se muestra la tarjeta de transferencia manual. Llama a la API de MP en cada `mount()`. |
| `SubscriptionController` | `checkout`, `show`, `returnFromCheckout`, `cancel`, `changePlan` son **código muerto**: no tienen rutas, y `subscriptions.show` / `subscriptions.return` / la vista `subscriptions.show` no existen. Solo `webhook` está ruteado (`POST /api/mercadopago/webhook`). |
| Webhook | Siempre vuelve a consultar el estado real a la API de MP (por eso un payload falsificado no puede cambiar el estado: lo cubre `MercadoPagoWebhookTest`). **Esto se conserva.** |
| `subscriptions` | Una fila por empresa (`company_id` UNIQUE), sin historial de pagos. |
| `subscription_plans` | Plan `professional` a ARS 149.000 con `mercadopago_plan_id` **hardcodeado de la app eliminada** en el seeder. |
| Provider `manual` | `ManualSubscriptionActivator` + `subscription:expire-manual` (transferencias). Funciona; **se conserva**. |
| `Company::hasActiveAccess()` | Regla única de acceso, usada por `EnsureActiveSubscription`. Bien centralizada; **se conserva y se adapta**. |

### Qué está roto u obsoleto
1. **Credenciales**: `MERCADOPAGO_ACCESS_TOKEN`/`PUBLIC_KEY` y `MERCADOPAGO_BASIC_PLAN_ID`/`PRO_PLAN_ID` del `.env` son de la app eliminada → inválidos. `CreateMercadoPagoPlans` y el seeder apuntan a planes que ya no existen.
2. **Sin validación de firma** del webhook (`x-signature`). Hoy lo compensa la re-consulta a MP, pero cualquiera puede forzar llamadas a la API de MP con IDs arbitrarios.
3. **`external_reference` inconsistente**: la página Filament manda `company_{id}`; el webhook solo reconoce `company_{id}_plan_{id}` → si el `provider_subscription_id` no está guardado, el webhook no encuentra la empresa.
4. **Pagos no modelados**: `authorized` da acceso aunque los cobros mensuales fallen (MP mantiene el preapproval `authorized` mientras reintenta). No hay registro de pagos aprobados/rechazados, ni renovación real, ni vencimiento.
5. **Dos mapeos distintos** del estado de MP (`Subscription::syncLocalSubscription` y `SubscriptionController::applyMercadoPagoResponse`) con semánticas diferentes (ej. `past_due` cuenta como activo en uno y no en el otro).
6. `services.mercadopago.test_payer_email` se usa pero **no existe en `config/services.php`** → siempre null.
7. Sin idempotencia ni registro de eventos de webhook.

### Qué conviene rehacer
- Un único `MercadoPagoSubscriptionSync` (la única pieza que traduce MP → Ascento), usado por webhook, retorno del checkout y un comando de reconciliación.
- Webhook: validar `x-signature` (HMAC-SHA256 con la clave secreta del webhook), registrar el evento, responder 200 rápido, y **siempre** re-consultar a MP antes de cambiar nada (se conserva).
- Registrar cada cobro (`subscription_authorized_payment`) y derivar el acceso de **período pagado vigente** (`current_period_end`) + gracia corta, no del `status` del preapproval solo.
- Borrar el código muerto del controller; reactivar los botones de la página Filament sobre el servicio nuevo.

### Migraciones previstas
- `subscriptions`: `payer_email`, `last_payment_status`, `last_payment_at`, `grace_ends_at`, `ends_at`; quitar UNIQUE de `company_id` para conservar historial (evaluar al implementar).
- `subscription_payments` (nueva): `subscription_id`, `company_id`, `mp_payment_id` UNIQUE, `amount`, `currency`, `status`, `status_detail`, `paid_at`, `period_start/end`.
- `webhook_events` (nueva): proveedor, `request_id`, tipo, `data_id`, firma válida, `processed_at` (auditoría + idempotencia).

### Variables `.env`
`MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET` (nueva), `MERCADOPAGO_TEST_PAYER_EMAIL` (solo pruebas), `MERCADOPAGO_PUBLIC_KEY` (solo si se tokeniza tarjeta en el front; con checkout por redirección no hace falta). `MERCADOPAGO_BASIC_PLAN_ID`/`PRO_PLAN_ID` se eliminan.

### Lo que vas a tener que hacer vos
Crear la aplicación nueva en Mercado Pago Developers (producto Suscripciones), usuarios de prueba vendedor/comprador, configurar la URL del webhook (`https://<dominio>/api/mercadopago/webhook`, eventos *Planes y suscripciones*) y copiar la clave secreta. Para probar webhooks en local hace falta un túnel público (ngrok / Cloudflare Tunnel).

---

## ETAPA 3 — Notificaciones en tiempo real para técnicos (AUDITORÍA)

### Qué existe
- Al crear una OT (`CreateWorkOrder::afterCreate`) se manda un **botón de WhatsApp** a cada técnico con teléfono, usando la WABA que **cada empresa conecta** (Facebook embedded signup). Es síncrono dentro del request, no valida suscripción, y **no se dispara al editar/reasignar** una OT (solo al crear).
- `users.phone` se normaliza a `549…`. Se conserva.
- Los admins tienen notificaciones de base de datos de Filament (polling 10 s). Los técnicos **no**.
- La app del técnico es Blade (`layouts/app`), **sin manifest PWA ni service worker**.
- **No existe una pantalla de detalle de una OT para el técnico** (solo el listado `work-orders.index`): el "abrir la orden" de la notificación necesita una ruta nueva (`/{empresa}/work-orders/{id}`) o un deep-link al listado con la orden resaltada.
- No hay jobs en cola en todo el proyecto; `QUEUE_CONNECTION=database` sin evidencia de worker en producción.

### Propuesta
**Web Push estándar (VAPID) + Service Worker + manifest PWA**, sin Firebase:
- Paquete `laravel-notification-channels/webpush` (usa `minishlink/web-push`): canal de notificaciones de Laravel, tabla `push_subscriptions`, gratis, sin terceros con cuenta.
- Firebase Cloud Messaging **no aporta valor** acá: en Android Chrome ya usa FCM por debajo vía Web Push, y en iOS no ayuda. Solo sumaría una cuenta, un SDK y credenciales.
- Disparo: un evento de dominio `WorkOrderAssigned` al **adjuntar** técnicos a `work_order_user` (crear y editar), notificación `WorkOrderAssignedNotification` (canales webpush + database), con chequeo de empresa, asignación real y `hasActiveAccess()`. Si la OT se cancela/elimina antes del envío, no se manda.
- WhatsApp queda como canal opcional adicional (no requisito).

### Riesgos
- **iOS**: Web Push solo funciona si el técnico **instala Ascento en la pantalla de inicio** (iOS/iPadOS 16.4+). Hay que guiarlo en la UI.
- Requiere **HTTPS** en producción (localhost sirve para desarrollo).
- Requiere un **worker de colas** o `dispatchAfterResponse` (como en la Etapa 1) para no demorar al admin.
- El usuario puede negar el permiso de notificaciones: hay que mostrar estado y cómo reactivarlo.

### Migraciones / env / servicios
- Migración del paquete: `push_subscriptions`.
- `.env`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (`mailto:`), generadas con `php artisan webpush:vapid`.
- Servicios externos: ninguno con cuenta (los push services de los navegadores).

---

## Orden recomendado
1. **Etapa 1** (hecha).
2. **Etapa 2 depende de vos** (crear la app de MP y el túnel). Mientras tanto se puede avanzar con la Etapa 3, que no depende de terceros. Si preferís respetar el orden 1 → 2 → 3, la Etapa 2 puede implementarse y testearse completa con respuestas de MP simuladas, y validarse con credenciales reales cuando estén.
