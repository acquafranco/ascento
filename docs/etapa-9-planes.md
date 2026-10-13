# Etapa 9: planes, página comercial y ayuda

Se entrega en tres PRs: **9.2 planes y control de acceso** (este documento), **9.1 página de inicio** y **9.3 ayuda**.

## 1. Lo que no cambia

- **Precios:** Inicial $69.000, Profesional $119.000 y Empresa $169.000.
- **Límites:**

| Límite | Inicial | Profesional | Empresa |
|---|---|---|---|
| Edificios | 20 | 70 | 300 |
| Clientes | 50 | 150 | 420 |
| Técnicos | 3 | 10 | 25 |
| Reportes por mes | 15 | Sin límite | Sin límite |
| Usuarios administrativos | 0 | 1 | 5 |

- **Cómo se cuentan:**
  - Las cuentas del portal de clientes no cuentan como técnicos (`PlanLimit::Technicians` cuenta solo `role = technician`).
  - Los administrativos tienen su propio límite.
  - Desactivar libera cupo y reactivar lo vuelve a controlar.
- **Funciones que ya dependían del plan, sin cambios:**
  - Profesional: presupuestos, portal, video, indicadores de empresa, historial avanzado y análisis de fallas.
  - Empresa: indicadores avanzados, alertas avanzadas y fotos de los trabajos.
- **Prueba gratis:** sigue siendo Empresa completo (`SubscriptionPlan::TRIAL_PLAN`).

## 1.1 Remitos digitales: en los tres planes

Regla comercial: los remitos digitales son una función central y **no dependen de la suscripción**.

- **Qué incluyen:** en Inicial, Profesional y Empresa, el técnico crea, completa y firma el remito con el flujo normal, lo ve en el historial y lo comparte con el cliente (PDF, enlace, WhatsApp o correo). El dueño lo descarga en PDF desde el panel. El enlace y el PDF del cliente abren siempre.
- **Qué había antes:** Inicial firmaba el remito, pero no podía descargarlo en PDF desde el panel ni compartirlo.
  - La migración `2026_12_30_100100_digital_delivery_notes_on_every_plan` suma `digital_delivery_notes` a todos los planes. Al revertirla, se quita solo de los que no lo tenían.
  - Se quitaron los controles de plan de la descarga del PDF, del listado de remitos y de la pantalla del remito.
- **Es parte de la base** (`PlanFeature::structural()`): el SuperAdmin no puede quitarlo desde «Planes».
- **Fotos de antes y después:** siguen siendo una función aparte, solo de Empresa («Fotos de los trabajos y reportes de inspección»). El remito no las necesita: sin ese plan se crea, se firma y se comparte igual.

## 2. Funciones nuevas en la matriz

Las funciones de las Etapas 6 a 8 no estaban declaradas en los planes. Ahora están en `PlanFeature`, y la migración `2026_12_30_100000_add_stage_9_features_to_plans` las suma a las filas de `subscription_plans`. Solo agrega claves y se puede revertir.

| Función | Valor | Plan | Límites |
|---|---|---|---|
| Tareas y recordatorios | Que nada se olvide: responsable, vencimiento y aviso a quien corresponda. | Todos | Sin límite propio |
| QR público del edificio | Información administrativa para residentes y visitantes, sin cuenta. | Todos | Uno por edificio |
| Pruebas de paracaídas | Registro de pruebas y avisos de las próximas o vencidas, según la política de cada equipo. | Todos | Sin límite propio |
| Componentes enviados a reparación | Seguimiento de lo que está en el taller: estado, fechas, proveedor y costos. | Todos | Sin límite propio |
| Historial de contactos con clientes | Seguimiento comercial, junto a presupuestos e indicadores. | **Profesional y Empresa** | Sin límite propio |

- **Por qué no se reservaron como «premium»:** las cuatro primeras son registro operativo o de seguridad, útiles a cualquier tamaño de empresa. Además, una empresa con Inicial ya tenía un QR impreso y activo.
- **Por qué contactos va desde Profesional:** acompaña a presupuestos e indicadores de empresa, que ya son de ese plan.

## 3. Control de acceso en el servidor

Todo usa `PlanGuard`, el mismo mecanismo de las funciones anteriores, sin un sistema paralelo.

- **Pantallas:** `RequiresPlanFeature`. Sin la función, se explica y se lleva a «Ver planes», nunca a un 403.
  - Contactos: listado, alta y edición.
  - Tareas: listado, alta y edición.
  - Paracaídas: estado por ascensor e historial.
  - QR públicos.
  - Reparaciones: listado, ficha, alta y edición.
  - La pestaña «Contactos» de la ficha del cliente no se muestra.
- **Servicios:** `PlanGuard::ensureCurrent` en `TaskService`, `ClientInteractionService`, `PublicQrService`, `ParachuteService` y `RepairService`.
- **Archivos:** los de pruebas de paracaídas y reparaciones responden 404.
- **Inicio y ficha del cliente:** sin los bloques de tareas o contactos.
- **Procesos automáticos:** `tasks:remind` y `parachute:remind` no avisan a empresas sin la función.
- **QR público:** sin la función responde 404, igual que un QR desactivado. Los bloques de paracaídas y reparaciones del QR se ocultan si el plan no los incluye.

## 4. Cambio de plan y datos

- **Bajar de plan nunca borra datos:** tareas, contactos, QR, pruebas, reparaciones y sus archivos se conservan.
- **Al perder la función:**
  - las pantallas llevan a «Ver planes» y el servidor rechaza crear o cambiar;
  - el QR impreso deja de mostrar información, pero su enlace se conserva;
  - los recordatorios quedan pendientes.
- **Al recuperarla:** todo vuelve como estaba, incluido el mismo QR impreso, y los recordatorios pendientes salen en la siguiente corrida.
- **Exportación completa:** incluye siempre todos los datos de la empresa, aunque su plan ya no incluya la función.

## 5. Administración por el SuperAdmin

- **Pantalla «Planes»** (`/admin/planes`, solo el SuperAdmin): para cada plan que se vende, qué funciones incluye.
  - El precio y los límites se ven, pero **no se editan** desde ahí.
  - La base del producto (edificios, clientes, técnicos, mantenimientos, inspecciones, órdenes, reportes, historial y remitos digitales) no se puede quitar.
  - Guardar pide confirmación y muestra el resumen de cambios.
  - Lo verifica `PlanFeatureManager` en el servidor. Cada cambio queda en el log de la aplicación, con quién lo hizo, el plan, lo que se sumó y lo que se quitó.
- **Desactivar una función** aplica a todas las empresas de ese plan desde ese momento, sin borrar datos.
