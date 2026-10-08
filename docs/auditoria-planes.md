# Auditoría de planes y suscripciones (antes de los 3 planes)

Fecha: 2026-10-08. Base: `main` @ fbf6800.

## 1. Dónde vive el plan de $149.000

| Lugar | Qué hace |
|---|---|
| `subscription_plans` (tabla) | Una fila: `slug = professional`, `name = Ascento`, `price = 149000`, `features = ["Prueba gratis de 30 días"]`, `mercadopago_plan_id` viejo (sin uso). |
| `database/seeders/SubscriptionPlanSeeder.php` | Crea/actualiza esa fila con 149000; desactiva `basic` y `premium` (planes históricos). |
| `app/Filament/Pages/Subscription.php` | Toma "el primer plan activo" (`orderBy('id')->first()`): hoy hay un solo plan. |
| `app/Services/MercadoPagoSubscriptionSync::startCheckout()` | Crea la suscripción en Mercado Pago con `price`/`currency` del plan y guarda `subscriptions.plan = slug`, `amount = price`. |
| `app/Console/Commands/CheckMercadoPago.php` | Muestra "el plan activo". |
| `app/Support/ManualSubscriptionActivator.php` | Suscripciones manuales históricas: usa `'professional'` por defecto. |
| `resources/views/welcome.blade.php` | Landing: lee el primer plan activo y muestra **una** tarjeta; tiene además textos fijos de 3 planes que no existen ("Hasta 15 edificios", "Edificios ilimitados"…). |
| Tests | Varios asumen 149000 y `professional`. |

## 2. Cómo se determina el plan de una empresa

- `companies` **no tiene** columna de plan.
- El plan es el texto `subscriptions.plan` (slug) de la suscripción de la empresa (una fila por empresa, `company_id` único).
- Sin suscripción (prueba gratis de 30 días, `companies.trial_ends_at`): **no hay plan**; hoy no importa porque no existen límites.
- El precio que se cobra está **guardado en la suscripción** (`subscriptions.amount`) y en Mercado Pago (`auto_recurring.transaction_amount`). Cambiar el precio del plan no cambia lo que se cobra a quien ya está suscripto.

## 3. Cómo se manejan las suscripciones

- Mercado Pago: preapproval sin plan (`status = pending` + `init_point`), sincronizado SOLO desde la API (webhook firmado + reconciliación cada 6 h). Se valida empresa (`external_reference`), importe y moneda contra `subscriptions.amount`.
- Acceso: `Company::hasActiveAccess()` → `Subscription::grantsAccess()` (período pago, tolerancia de 5 días si se rechaza un cobro, 48 h esperando el primer cobro, cancelada hasta fin del período). La prueba gratis la da Ascento.
- Middleware `EnsureActiveSubscription` corta el uso sin acceso. **No hay** policies, gates ni middleware de plan.

## 4. Funcionalidades actuales

Clientes · Edificios (con mapa, colores y geocodificación) · Técnicos (usuarios `role = technician`) · Mantenimientos e Inspecciones (visitas, "Planillas" mensuales) · Órdenes de trabajo (con push/Telegram) · **Remitos** · Reportes (problemas con foto) · Presupuestos (con link público, WhatsApp y email) · Historial · Dashboard · Avisos (push, Telegram, campanita).

Dependencias importantes encontradas:

- **El remito es el cierre del trabajo**: un mantenimiento mensual queda hecho cuando el técnico firma el remito, y una orden de trabajo se completa al firmar su remito (`DeliveryNoteController::store`). **No se puede quitar el remito del plan Inicial sin romper Mantenimientos y Órdenes de trabajo.**
- Lo que es "remito digital para el cliente" y sí es separable: descarga en PDF, link público y enviarlo por WhatsApp/email.
- Presupuestos es un módulo independiente (no lo usa ningún otro flujo): se puede limitar por plan sin romper nada. Sus links públicos ya enviados a clientes deben seguir abriendo.
- Reportes: los cargan los técnicos (app web) y los admins (panel). Tienen `SoftDeletes`.

## 5. Límites actuales

**Ninguno.** No hay límites de edificios, clientes, técnicos ni reportes.

## 6. Cómo se cuentan (dominio real)

| Recurso | Modelo | Cuenta | Notas |
|---|---|---|---|
| Edificios | `Building` | no eliminados (`deleted_at` nulo) de la empresa | "Desactivar" = soft delete y libera cupo; **reactivar debe chequear el cupo** (si no, se esquiva el límite). El toggle "Activo" NO libera cupo. |
| Clientes | `Client` | no eliminados de la empresa | Igual que edificios (reactivar chequea cupo). |
| Técnicos | `User` | `role <> admin`, no SuperAdmin, no eliminados | Los admins y el SuperAdmin **no** consumen cupo. Reactivar un técnico chequea cupo. |
| Reportes | `Report` | creados en el mes calendario actual, **incluidos los eliminados** | Si contaran solo los vigentes, borrar uno liberaría cupo. |

## 7. Riesgos

1. Empresas pagando $149.000 por Mercado Pago: cambiar su plan no cambia el cobro en Mercado Pago. Asignarlas a un plan más chico les **quitaría** cosas que hoy pagan.
2. Empresas que ya superan un límite: no se borra nada; solo no pueden **agregar** más hasta bajar o subir de plan.
3. Prueba gratis sin plan: hay que definir qué límites aplican durante la prueba.
4. Esquivar límites desactivando y reactivando registros.
5. Errores genéricos (500/403) si un límite se viola desde un camino no previsto: se necesita una última defensa a nivel modelo con mensaje claro.

## 8. Estrategia elegida

- **Fuente única de verdad**: `subscription_plans` con columnas de límites (`max_buildings`, `max_clients`, `max_technicians`, `max_reports_per_month`; `NULL` = sin límite) y `feature_keys`. Enums `PlanLimit` / `PlanFeature` para no repetir strings. `Company::plan()` nunca devuelve null.
- **Migración no destructiva**: crea `inicial`, `profesional`, `empresa`; desactiva `professional` (queda para historial); las suscripciones con plan viejo pasan a `empresa` **manteniendo su importe de $149.000** (no se toca Mercado Pago) y se guarda el slug anterior en `subscriptions.legacy_plan`. Nadie pierde funciones ni paga distinto.
- **Prueba gratis = plan Profesional** (el recomendado) con sus límites.
- **Cambio de plan con suscripción activa**: `PUT /preapproval/{id}` con el nuevo importe; el plan nuevo rige desde ya y el importe nuevo desde el próximo cobro.
- **Defensa en profundidad**: chequeo en las pantallas (mensaje + upgrade), en las acciones de reactivar y en los eventos del modelo (última línea, para cualquier otro camino).

## 9. Lo implementado

| | Inicial | Profesional (recomendado) | Empresa |
|---|---|---|---|
| Precio | $69.000/mes | $119.000/mes | $169.000/mes |
| Edificios | 20 | 70 | 300 |
| Clientes | 50 | 150 | 420 |
| Técnicos (usuarios no admin) | 3 | 10 | 25 |
| Reportes | 15 por mes | sin límite | sin límite |
| Clientes, edificios, técnicos, mantenimientos, inspecciones, órdenes de trabajo, reportes, historial, mapa | ✓ | ✓ | ✓ |
| Remito firmado (registro del trabajo) | ✓ | ✓ | ✓ |
| Remitos digitales para el cliente (PDF, link, WhatsApp/email) | — | ✓ | ✓ |
| Presupuestos | — | ✓ | ✓ |

- Fuente de verdad: `subscription_plans` (+ `PlanLimit` / `PlanFeature`, `PlanGuard`, `Company::plan()`).
- Validación: pantallas (mensaje + upgrade), reactivar (individual y masivo), app del técnico y eventos del modelo (última línea).
- Prueba gratis = Profesional. Empresas con el plan de $149.000 → Profesional ($119.000) por la migración 2026_10_14 (antes se habían pasado a Empresa); `legacy_plan` guarda el plan anterior. Si Mercado Pago estuviera cobrando alguna, conserva su importe hasta "Cambiar de plan". Plan de respaldo para datos viejos: Profesional.
- Cambio de plan con suscripción activa: `PUT /preapproval/{id}`; el cobro del ciclo en curso con el importe anterior se acepta hasta 35 días.
