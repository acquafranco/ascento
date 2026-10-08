# Planes, agenda, centro de atención, indicadores y legajo del ascensor

Base: `main` @ d6792aa. Rama: `planes-agenda-legajo`.

## Qué se reutilizó (no se duplicó nada)

| Necesidad | Fuente existente |
|---|---|
| Agenda de mantenimientos e inspecciones | Asignaciones edificio–técnico (`building_user`, tipo) + visita mensual (`building_visits`, que se crea al firmar el remito) + contratos (`maintenance_services`). Sin tablas nuevas. |
| Historial del ascensor | Reportes (`elevator_number`), órdenes (`unit`) con remitos y materiales, presupuestos (`unit`) y visitas del edificio. Se arma al vuelo; no se copia. |
| Planes | `subscription_plans.feature_keys` + `PlanFeature` + `PlanGuard` + `RequiresPlanFeature` (pantalla de upgrade, nunca 403/500). |
| Ayuda | La guía de bienvenida y el botón "?" (`AdminOnboarding`) quedan igual; los textos de cada sección se reutilizan. |
| Datos de empresa | Razón social, CUIT, condición fiscal, domicilio, ciudad, provincia, logo ya existían en `companies`. |

## Lo nuevo

- **Ascensor como entidad** (`elevators`): no existía. Una fila por equipo, identificada por (edificio, "Ascensor N"), el mismo valor que ya guardaban reportes/órdenes/presupuestos. Se crean solos con las cantidades del edificio; si el edificio tiene menos, el legajo queda inactivo (no se borra su historia).
- **Documentación del legajo** (`elevator_documents`): planos, manuales, certificados (con vencimiento), fotos. Disco privado, tipo detectado por contenido, solo admins de la empresa.
- **Componente afectado** (`reports.component`, `work_orders.component`, opcional): el dato mínimo que faltaba para el análisis de fallas por componente. Lo histórico queda "sin clasificar"; no se infiere.
- **Estado de cada ayuda** (`help_dismissals`: usuario + clave).
- **Datos de empresa opcionales**: código postal, actividad, Ingresos Brutos, inicio de actividades, banco, CBU, alias.

## Planes

| | Inicial | Profesional | Empresa |
|---|---|---|---|
| Operación actual (clientes, edificios, técnicos, mantenimientos, inspecciones, órdenes, remitos firmados, reportes, mapa, stock, servicios, cobranzas) | ✓ | ✓ | ✓ |
| Agenda de mantenimientos | ✓ | ✓ | ✓ |
| Centro de atención básico | ✓ | ✓ | ✓ |
| Legajo técnico + historial básico (últimos 15) | ✓ | ✓ | ✓ |
| Indicadores básicos | ✓ | ✓ | ✓ |
| Presupuestos, remitos digitales | — | ✓ | ✓ |
| Centro de atención avanzado (reincidencias, certificados, fichas incompletas, atrasos) | — | ✓ | ✓ |
| Historial avanzado (todo, filtros, materiales) y análisis de fallas | — | ✓ | ✓ |
| Indicadores de empresa (evolución, técnicos, ascensores, presupuestos) | — | ✓ | ✓ |
| Indicadores avanzados (año contra año, cartera, tendencias) | — | — | ✓ |
| Alertas avanzadas (fallas en aumento, contratos por vencer, deudas viejas) | — | — | ✓ |

Precios, límites y prueba gratis sin cambios. La migración solo **agrega** claves a `feature_keys`.

## Reglas del análisis de fallas (sin IA)

- Intervención = reporte o orden de tipo **reclamo** sobre el equipo.
- Reincidente = 3 o más en 90 días. "En aumento" (Empresa) = al menos 2 y 1,5× los 90 días anteriores (+1).
- Mensaje: "Ascensor 1 (Torre Sur) tuvo 7 intervenciones en los últimos 90 días: 4 de puertas y operador, 3 de maniobra / controlador."

## Ayuda contextual

- Cada pantalla tiene su ayuda (clave propia: `agenda_intro`, `technical_file`, `failure_analysis`, …). Se muestra como una tarjeta chica hasta que el admin toca "Entendido".
- Al descartarla se guarda una fila (usuario + clave) en la base: no vuelve aunque cierre sesión, cambie de página o vuelva otro día.
- Mientras corre la guía de bienvenida, las tarjetas esperan (no se apilan).
- "Mi empresa → Ayuda": lista de ayudas (vista / se va a mostrar), "Volver a ver" de a una, "Volver a ver todas" y "Volver a ver la guía de bienvenida".
- Los técnicos no ven ni guardan ayudas del panel.

## Futuro portal del cliente (no implementado)

Las relaciones ya permiten armarlo sin reconstruir nada: cliente → edificios → ascensores → reportes / órdenes / presupuestos / remitos / visitas / documentos / cobros (todo con `company_id` y edificio). Ya existen links públicos con token para presupuestos y remitos.

Faltan, como piezas estructurales:

1. **Usuarios del cliente**: hoy los usuarios son personal de la empresa (`role` admin/technician). Hace falta un usuario vinculado a un `client_id` (rol nuevo o tabla `client_users`) y su propio acceso.
2. **Qué ve el cliente**: reportes, fotos y documentos del legajo hoy son internos. Hace falta un indicador `visible_to_client` (o similar) por registro antes de exponerlos.
3. Las órdenes de trabajo no guardan `client_id` (se obtiene por el edificio): suficiente para el portal.

## Facturación

No se implementó (ni ARCA, CAE, puntos de venta ni comprobantes). Solo quedaron los datos de empresa opcionales que una integración futura necesitaría.
