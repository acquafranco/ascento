# Calculadora comercial, implementación inicial y marketplace de repuestos

## Fase 0. Auditoría (antes de cambiar código)

| Tema | Qué hay hoy | Consecuencia |
|---|---|---|
| Planes | `subscription_plans` es la **única fuente de verdad**. Tres planes activos, todos con precio mensual en ARS. Límites: `max_buildings`, `max_clients`, `max_technicians`, `max_reports_per_month` y `max_admin_users` (NULL = sin límite). Funciones en `feature_keys`. Con los datos actuales: Inicial 20/50/3, Profesional 120/150/10, Empresa 400/420/25 (edificios/clientes/técnicos). | La calculadora lee el plan desde la base (`SubscriptionPlan::offered()`). **No existe límite de ascensores**: la cantidad de máquinas no decide el plan, solo el precio de implementación. |
| Prueba y suscripción | El registro crea una empresa con **prueba de 30 días con el plan Empresa** (`TRIAL_PLAN`). El plan se elige al final, desde «Mi suscripción» (Mercado Pago `preapproval`). | «Elegir este plan» no cambia la prueba: se registra igual y el plan elegido queda recordado para preseleccionarlo en «Mi suscripción». |
| Registro y onboarding | `RegisteredUserController` (empresa + dueño), con desafío anti-bots, correo verificado al terminar la guía (`EmailGate`) y un correo = una cuenta (`UserEmail`). | El registro de proveedores va por un flujo propio: no toca el de las empresas. |
| Importación | `ImportClients` importa clientes desde Excel. Los edificios y los ascensores se cargan desde el panel; la guía inicial carga el primero. | El servicio de implementación ofrece cargar lo que la app permite: clientes (importación), edificios y ascensores (panel). No promete integraciones. |
| Mercado Pago | `MercadoPagoService` maneja **suscripciones** (`preapproval`), el seguimiento de pagos y los webhooks de la cuenta de Ascento. **No hay** Checkout Pro de cobros únicos, ni OAuth de vendedores, ni split de pagos (`marketplace_fee`). | **No se pueden cobrar compras del marketplace en nombre de proveedores** con lo que existe. Ver la sección «Pagos». |
| Roles y tenencia | Usuarios: SuperAdmin, dueño (`admin`), administrativo (`staff`, permisos por área), técnico y cliente del portal. Aislamiento por `company_id` con scopes y verificación en el servidor. | Los proveedores son un **rol nuevo y aislado** (`supplier`), sin `company_id`: no entran al panel, a la app del técnico ni al portal. |
| Archivos | Disco privado `local`. `PhotoProcessor` re-codifica a JPEG, quita metadatos y achica. | Las fotos de productos usan `PhotoProcessor` y se sirven por un controlador con sesión. |

## Decisiones comerciales que necesitan tu aprobación

Todas quedan **configurables desde el panel del SuperAdmin**. Mientras no las confirmes, la app muestra «pendiente de confirmación» y no inventa un importe.

1. **Fórmula de implementación** (propuesta). Es determinista: los mismos datos y las mismas tarifas dan el mismo resultado.
   - Componentes:
     - **tarifa base**, con una cantidad incluida de clientes, edificios y ascensores;
     - un **importe por unidad adicional** de cada tipo;
     - un **factor de estado de los datos** (ordenados en una planilla ×1; varias planillas o desprolijos ×1,25; en papel ×1,5), que se aplica **solo a la parte variable**;
     - **servicios opcionales** de precio fijo: capacitación del equipo y acompañamiento en la puesta en marcha.
   - **Tope:** si el total supera un monto configurable, en lugar de un precio se ofrece una propuesta personalizada.
   - **Ventajas:** es simple, se puede explicar y no cobra dos veces lo mismo: cada edificio y cada ascensor se cuentan una sola vez, y los técnicos no suman, porque sus usuarios los carga la empresa.
   - **Limitaciones:**
     - no mide la calidad real de los datos (eso se declara);
     - no contempla viajes ni trabajo presencial;
     - un cliente con miles de edificios necesita una propuesta personalizada.
2. **Monetización del marketplace, primera versión.** Perfil y publicación básica para proveedores aprobados y **publicaciones destacadas**, que asigna el SuperAdmin (después podrán venderse). Los **planes de proveedor** quedan configurados sin precio: «a definir». **Sin comisión por venta**, porque no hay cobros dentro de la plataforma.
3. **Modelo de compra, primera versión:** **solicitud de cotización**, no compra con pago. El comprador arma un pedido, el proveedor cotiza, el comprador acepta y después confirma la recepción. El pago y la entrega se coordinan entre las partes. Ascento nunca marca algo como «pagado».
4. **Aprobación de proveedores:** el SuperAdmin aprueba cada cuenta antes de que su catálogo sea visible. Las ediciones de rutina de un proveedor aprobado se ven al instante, con moderación posterior: ocultar productos, suspender cuentas y resolver reportes.
5. **Quién compra:** el dueño de la empresa y los administrativos con permiso de «Stock». Los técnicos no.

## Pagos reales: qué haría falta

Hoy **no se habilitan**. Para cobrar compras y repartir el dinero:

- **Mercado Pago Marketplace:**
  - una aplicación con **OAuth**, donde cada proveedor autoriza a Ascento a cobrar en su nombre;
  - las preferencias de pago se crean con el token del vendedor y un `marketplace_fee` (la comisión de Ascento);
  - webhooks por pago y manejo de reembolsos, contracargos y pagos rechazados.
- **Requisitos:** habilitación de la cuenta de Ascento como marketplace, aceptación de los proveedores, revisión legal (intermediación, facturación de la comisión) y definición de qué pasa con los pedidos sin stock o las devoluciones.
- **Costos:** las comisiones de Mercado Pago de cada cobro, más la propia de Ascento. No hace falta infraestructura nueva.

Hasta que eso esté aprobado: catálogo, pedidos y cotizaciones, sin cobro dentro de Ascento.

## Fuera de alcance (pedido expreso)

El marketplace de **trabajos** (publicar reparaciones y recibir propuestas) **no se implementa** en esta entrega: no hay tablas, roles ni pantallas para eso.

## Qué quedó implementado

### Calculadora (Welcome, `/calculadora`)
- Cuatro preguntas, sin datos personales. Plan recomendado = el más barato cuyos límites reales (`subscription_plans`) alcanzan; si ninguno alcanza, propuesta personalizada.
- Implementación: `ImplementationQuote` con las tarifas de «Tarifas de implementación» (SuperAdmin). Sin publicar → «A confirmar». Suscripción, implementación, opcionales, total inicial y costo mensual se muestran por separado.
- Pedidos (`sales_leads`): sin duplicados (clave de envío), presupuesto recalculado en el servidor, aviso a los SuperAdmin.

### Marketplace de repuestos (catálogo + pedidos de cotización)
- **Proveedores** (`/proveedores/registro`, área `/proveedores`): rol `supplier`, sin empresa; perfil, catálogo, fotos (PhotoProcessor), pedidos y cotizaciones. Pendiente/rechazado/suspendido: no se ve su catálogo; rechazado/suspendido no puede cambiar nada.
- **Compradores** (panel → Operaciones → «Repuestos» y «Pedidos de repuestos»): dueño y administrativos con «Stock» (ver = mirar; gestionar = pedir). Búsqueda, filtros, detalle, perfil del proveedor, comparar (hasta 4), favoritos de la empresa, pedido en armado (uno por proveedor), seguimiento, historial y «repetir».
- **Estados del pedido:** enviado → cotizado → aceptado → completado; o el proveedor no puede cotizar / cotización rechazada / cancelado. No existe «pagado».
- **SuperAdmin** (Plataforma): proveedores (aprobar, rechazar, suspender, reactivar), publicaciones (ocultar, volver a mostrar, destacar hasta una fecha) y reportes (resolver, con opción de ocultar, o descartar). Todo queda en `platform_audit_logs`.
- **Reputación:** solo cuenta operaciones que el comprador confirmó como completadas. Sin reseñas.
- Términos (secciones 17 y 18) y política de privacidad actualizados (versión 22/10/2026).

## Revisión final antes de integrar (22/10/2026)

Correcciones hechas en la revisión:
- **Cotización cambiada mientras el comprador la miraba:** el comprador aceptaba solo por estado, así que si el proveedor corregía el precio en ese momento, se aceptaba un importe que nunca vio. Ahora «Aceptar» lleva la versión de la cotización que se mostró (`MarketplaceRequest::quoteVersion()`), y el servidor la compara con el pedido bloqueado; si cambió, pide revisarla.
- **Cotizar sin bloqueo:** `MarketplaceRequests::quote()` ahora bloquea el pedido y vuelve a validar el estado dentro de la transacción. Un proveedor ya no puede reescribir una cotización que el comprador aceptó o canceló en el mismo instante.
- **Aviso de aprobación al proveedor:** aprobar, rechazar, suspender o reactivar una cuenta no le avisaba nada al proveedor. Ahora recibe el aviso en su área y por correo, con el motivo cuando lo hay (`SupplierAccountUpdate`). Usa el correo que ya tiene la app.
- Texto: «Agregamos 1 producto» (antes decía «1 productos»).

Sin cambios (decisión consciente):
- Una cotización vencida no genera aviso, porque no hay tareas programadas nuevas. El comprador la ve como vencida y no puede aceptarla.
- Si un proveedor es suspendido, sus pedidos abiertos quedan como están. El comprador puede cancelarlos o confirmarlos como recibidos, pero no aceptar una cotización nueva.
