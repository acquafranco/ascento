# Etapa 6: rol administrativo, herramientas, CRM e indicadores

Estado: **diseño aprobado en lo general, con las decisiones del 12/10/2026. Pendiente de aprobación para implementar el PR 1.** No hay código de esta etapa todavía.

## 1. Punto de partida (relevado en el código, `main` `6f8d623`)

- **Roles hoy:** `users.role` = `admin` (el que registró la empresa), `client` (portal) o `technician` (`job_type` = mantenimiento o inspección). `is_super_admin` aparte.
- **Una empresa tiene un solo admin en la práctica.** El panel crea usuarios técnicos y no hay forma de dar el rol de admin. `Company::owner()` es el admin más antiguo.
- **Autorización del panel:** un único control. Entra quien es admin o SuperAdmin (`User::canAccessPanel`); adentro, todo admin puede todo. No hay Policies; hay un solo Gate (`view-user-template`).
- **Comprobaciones a revisar:**
  - 46 llamadas a `isAdmin()`, clasificadas en la sección 6;
  - 34 comparaciones directas con `role`, de las cuales 8 definen «técnico» como «ni admin ni cliente» (ver riesgos).
- **Ya existe para 6.2:**
  - Agenda: vencidas, pendientes, sin técnico y visitas en curso.
  - Centro de atención con 16 alertas: visitas no realizadas o vencidas, edificios sin técnico, órdenes sin tomar o trabadas, órdenes urgentes, reportes graves, presupuestos que vencen en 5 días, stock bajo, cobros vencidos, fallas repetidas, ficha técnica incompleta, contratos por terminar y deudas viejas.
  - Indicadores, Cobranzas, Stock y Presupuestos con eventos (`QuoteEvent`).
- **CRM hoy:**
  - cliente con contacto, teléfono, correo y notas;
  - edificios y ascensores por cliente;
  - presupuestos con historial de envíos, cobranzas y portal.
  - No hay historial de contactos, tareas ni una ficha que lo reúna.

## 2. Decisiones tomadas

### Planes y límites de administrativos
| Plan | Administrativos adicionales |
|---|---|
| Inicial | 0 |
| Profesional | hasta 1 |
| Empresa (y prueba gratis, que usa Empresa) | hasta 5 |

- **Quiénes cuentan:** el dueño no cuenta. Los administrativos no consumen cupo de técnicos.
- **Cómo se guarda:** límite nuevo `PlanLimit::AdminUsers` en `subscription_plans.limits`, con una migración que solo suma la clave. No cambia precios ni otros límites.
- **Dónde se verifica:** en el servidor al crear o reactivar un administrativo (como los demás límites, con `ConsumesPlanLimit`) y en cada pedido, al calcular si su acceso está vigente.

### Comportamiento seguro al bajar de plan
Si una empresa queda con más administrativos activos que su nuevo límite:
- No se borra ni se desactiva a nadie, y el historial queda intacto.
- **Siguen con acceso** los N administrativos más antiguos (N = límite del plan); el resto queda **suspendido por plan**.
  - Un suspendido no entra al panel: ve un aviso que le dice que hable con el dueño.
  - Sus sesiones dejan de valer en el siguiente pedido.
- La suspensión **se calcula en cada pedido, no se guarda**. Al volver a subir de plan, o si el dueño desactiva a otro, el suspendido recupera el acceso solo.
- El dueño ve quién está suspendido y elige a quién desactivar para liberar el lugar.
- Las altas nuevas y las reactivaciones quedan bloqueadas mientras se supere el límite.

### Permisos
- **Inicio:** el administrativo empieza sin permisos. Solo el dueño asigna permisos.
- **Lectura y gestión, independientes** (gestionar implica ver), por área:

| Área | Ver | Gestionar | Pantallas y rutas que cubre |
|---|---|---|---|
| Clientes | ✓ | ✓ | Clientes; `clients.show` de la app web |
| Edificios y ascensores | ✓ | ✓ | Edificios, Ascensores (legajo), Mapa, asignación masiva (además requiere «Técnicos: gestionar») |
| Técnicos | ✓ | ✓ | Ver: listado de técnicos y su actividad. Gestionar: asignar técnicos a edificios y órdenes. **Nunca** crear, editar, desactivar usuarios ni cambiar roles |
| Visitas y agenda | ✓ | ✓ | Agenda, Mantenimientos, frecuencias, visitas en curso (abrir, descartar) |
| Órdenes de trabajo | ✓ | ✓ | Órdenes, materiales, fotos de la orden (las fotos, además, solo con plan Empresa) |
| Inspecciones y reportes | ✓ | ✓ | Inspecciones, reportes de falla y de inspección, sus fotos y PDF (fotos con plan Empresa) |
| Presupuestos | ✓ | ✓ | Presupuestos, PDF, envío al cliente |
| Documentos y remitos | ✓ | ✓ | Documentos del ascensor (subir, borrar) y remitos (ver, PDF, compartir con el portal). Los remitos firmados nunca se editan |
| Indicadores | ✓ | — | Indicadores, según el plan |
| Centro de atención | ✓ | — | Centro de atención (cada alerta enlaza a su área: sin permiso en el área, se ve la alerta sin el enlace) |
| Cobranzas | ✓ | ✓ | Cobranzas, pagos, contratos (servicios) |
| Stock | ✓ | ✓ | Stock y movimientos |
| Tareas y recordatorios | ✓ | ✓ | PR 3 |
| Historial de contactos | ✓ | ✓ | PR 3 |

- **Reservado siempre al dueño:**
  - suscripción, plan y pagos;
  - «Mi empresa» (datos fiscales, marca, interruptores de fotos, WhatsApp);
  - gestión de usuarios y permisos;
  - exportación completa;
  - guía de bienvenida.
- **Del SuperAdmin:** sus funciones (empresas, suscripciones, backups) siguen exclusivas.
- **Otras empresas:** un administrativo nunca accede a ellas. El aislamiento por empresa no cambia: el administrativo tiene `company_id` y pasa por los mismos filtros.

### Indicadores
- **Inicial:** conserva los indicadores básicos actuales, sin cambios.
- **Profesional y Empresa:** suman inspecciones realizadas, edificios atendidos y presupuestos pendientes (ver la sección 8).
- **Reparaciones pendientes:** no se implementa hasta que exista el modelo de la Etapa 8.4.

## 3. Modelo técnico

- **Rol nuevo:** `users.role = 'staff'` (en pantalla, «Administrativo»).
  - Entra al panel; no usa la app del técnico (se lo redirige al panel).
  - No es «dueño»: `isAdmin()` sigue significando exactamente lo de hoy.
- **Permisos:** columna JSON `users.permissions` con claves de un enum cerrado `Permission` (`clients.view`, `clients.manage`, …).
  - No es asignable masivamente; solo la escribe el servicio de gestión del PR 2.
  - Las claves desconocidas se ignoran.
- **Servicio central `Access`:** responde a `Access::can($user, Permission)`, `Access::isOwner($user)` y `Access::panelUser($user)`.
  - Dueño (`role = admin`) y SuperAdmin dentro de la empresa elegida: todo, como hoy.
  - Administrativo con acceso vigente: solo sus permisos.
  - Técnico y portal: nada del panel.
- **Aplicación en el servidor:**
  - `Gate::before` y Policies por modelo. Filament las usa en listar, ver, crear, editar, borrar, restaurar y acciones masivas.
  - `canAccess` de cada página y el middleware o los controladores de la app web llaman a `Access`.
  - Los archivos (fotos, PDF de presupuestos y remitos, documentos del ascensor) también: cada controlador de archivo pide el permiso del área además de las reglas actuales.
- **Navegación:** se oculta lo que no puede ver, pero el control real es el de arriba.

## 4. Registro de actividad

- **Tabla nueva `activity_logs`:**
  - empresa (`company_id`);
  - usuario (`user_id`, nullable, nullOnDelete) más una **copia del nombre y el rol al momento** (`actor_name`, `actor_role`), para que el registro sobreviva a la desactivación o al borrado del usuario;
  - acción (`action`, clave cerrada);
  - registro afectado (`subject_type`, `subject_id`, `subject_label`);
  - cambios resumidos (`changes` JSON, solo campos permitidos);
  - IP y fecha.
- **Qué se registra:** acciones sensibles de administrativos y del dueño:
  - altas y bajas de clientes, edificios y ascensores;
  - asignaciones de técnicos;
  - frecuencias de visita;
  - creación, cancelación y cierre de órdenes;
  - envío, aprobación y anulación de presupuestos;
  - documentos subidos o borrados;
  - remitos compartidos;
  - pagos registrados y anulados;
  - movimientos de stock;
  - permisos y altas o bajas de administrativos;
  - exportaciones.
- **Nunca se registran:** contraseñas, tokens, firmas, archivos ni el contenido de campos no listados. Hay una lista explícita de campos permitidos por modelo.
- **Integridad:**
  - solo se escribe desde el servicio;
  - no hay edición ni borrado desde la app;
  - el borrado definitivo de la empresa (SuperAdmin) lo incluye en `CompanyEraser`.
- **Visibilidad:** el dueño lo ve en «Actividad»; un administrativo, no. Es también el «historial de cambios relevantes» de 6.2.

## 5. Dependencias entre las Etapas 6, 7 y 8

**No hay una razón técnica para cambiar el orden 6 → 7 → 8. Al contrario, conviene hacer primero los PR 1 y 2 de la 6:**
- **Etapa 7.2:** pide que «el administrador autorizado» genere, desactive y regenere el QR y elija qué se publica.
- **Etapa 7.4:** manda avisos a «otro responsable autorizado».
- **Etapa 8.4:** suma una sección administrativa nueva (componentes en reparación).

Con el modelo de permisos ya hecho, esas pantallas nacen con su permiso (por ejemplo, «QR y datos públicos», «Reparaciones») y no hay que reabrirlas.

**Lo único de la Etapa 6 que depende de la 8**, y queda fuera de los cinco PR hasta entonces:
- seguimiento de componentes enviados a reparar (6.2);
- indicador de reparaciones pendientes (6.4);
- reparaciones en la ficha del cliente (6.3).

Cuando exista la 8.4, se suman como un área de permisos más.

**Ninguna parte de la 7 depende de las tareas, el CRM o los indicadores** (PR 3 a 5). Si hiciera falta adelantar la 7, se puede hacer después del PR 2.

## 6. Las 46 comprobaciones de `isAdmin()`

Suma: 13 + 5 + 6 + 15 + 2 + 5 = 46. Criterio: lo que hoy significa «dueño» sigue igual; lo que significa «puede operar el panel en X» pasa al permiso del área. Para el dueño, todos los reemplazos devuelven lo mismo que hoy (lo verifica una prueba que recorre todos los casos con el dueño actual).

| Grupo | Comprobaciones | Cambio |
|---|---|---|
| **Reservado al dueño** (13) | `Subscription` (×2), `CompanySettings`, `CompanyExports`, `CompanyExportService` (×2), `CompanyExportDownloadController`, `WhatsAppController`, `User::canUseOnboarding`, `NotifyNewCompanyRegistration`, `EnsureActiveSubscription` (redirección a pagar), `Gate view-user-template`, `TemplateController` | Sin cambios |
| **Entrada al panel** (5) | `User::canAccessPanel`, `User::homeUrl`, `AdminMiddleware`, `NotificationInboxController` (×2) | Pasan a `Access::panelUser` (dueño, SuperAdmin o administrativo con acceso vigente) |
| **Pantallas del panel** (6) | `Agenda` (×2), `AttentionCenterPage`, `IndicatorsPage`, `BuildingsMap`, `ScopedToCurrentCompany` | `Access::can` con el permiso de cada pantalla o recurso |
| **Datos y archivos** (15) | `Report`, `VisitPhoto`, `VisitPhotoService`, `VisitService` (×2), `InspectionReportService` (×2), `DeliveryNoteController` (×4), `ElevatorDocumentController`, `QuoteDocumentController`, `WorkOrderController` (×2, la vista y «tomar trabajo») | Dueño, o administrativo con el permiso del área (ver o gestionar según la operación) |
| **App del técnico** (2) | `WorkOrderController::finish`, `User::canReceiveWorkOrderPush` | Sin cambios: el administrativo no usa la app del técnico ni recibe avisos de órdenes |
| **Avisos y textos** (5) | `User::canReceiveAdminPush`, `TestPushNotification` (×2), `TelegramWebhookController`, más la definición de `isAdmin()` | PR 1: sin cambios (los avisos siguen yendo solo al dueño). Avisos por permiso, en el PR 2 |

Las 34 comparaciones directas con `role` se revisan igual. Las que buscan «técnicos» con `role = 'technician'` no cambian.

## 7. Riesgos detectados

1. **«Técnico» definido por exclusión (alto).** `PlanLimit::Technicians`, `User::planLimit`, la asignación masiva (`BuildingsTable`), la guía (`AdminOnboarding`) y `AdminStats` cuentan o listan como técnico a todo el que no es `admin` ni `client`. Con el rol nuevo, un administrativo:
   - ocuparía cupo de técnicos;
   - aparecería como asignable a edificios.

   En el PR 1 pasan a `role = 'technician'` (la definición explícita), con pruebas.
2. **La app del técnico acepta a cualquier usuario de la empresa (alto).** `DashboardController` manda a `/admin` solo al `admin`; cualquier otro rol entra a la app del técnico y podría, por ejemplo, crear reportes. El PR 1 hace que un administrativo nunca entre a las rutas del técnico (redirección al panel y 403 en las acciones).
3. **Archivos con controles propios (medio).** Fotos, PDF de presupuestos, documentos y remitos verifican `isAdmin()` en su controlador. Si se olvida uno, un administrativo sin permiso podría bajar un archivo por URL. Una prueba recorre cada ruta de archivo con un administrativo sin permisos.
4. **Acciones de Filament fuera de las Policies (medio).** Acciones personalizadas («Asignar técnicos», «Compartir con el portal», «Entrar», etc.) no pasan solas por las Policies. Cada una declara su permiso, y una prueba lista todas las acciones de cada recurso y falla si alguna no lo declara.
5. **Livewire manipulado (medio).** Un administrativo podría llamar a métodos públicos de páginas que no ve. Las páginas reservadas abortan en `mount` y en cada método público, no solo ocultándolas.
6. **Bajada de plan (bajo).** Sesiones abiertas de administrativos suspendidos: la verificación es por pedido, así que se cortan al siguiente.
7. **Volumen del cambio (alto).** Toca casi todos los recursos. Mitigación:
   - PR 1 sin cambios visibles para el dueño;
   - prueba de «el dueño ve y hace exactamente lo mismo que antes» sobre todas las pantallas;
   - el rol `staff` todavía no se puede crear desde la interfaz hasta el PR 2.

## 8. Indicadores nuevos: definiciones

Período: el mes calendario elegido en la pantalla, en la zona horaria de la aplicación. Siempre filtrado por la empresa.

- **Inspecciones realizadas:**
  - **Se cuentan:** remitos firmados en el período cuyo trabajo es una inspección (visita periódica de inspección, u orden de trabajo de tipo inspección) y que están marcados como **realizados**.
  - **No se cuentan:** visitas previstas, visitas en curso sin remito ni remitos «no realizado».
- **Edificios atendidos:**
  - **Se cuentan:** edificios distintos con al menos un remito firmado como realizado en el período, de cualquier tipo (mantenimiento, inspección u orden).
  - **No se cuentan:** edificios con trabajos solo previstos o no realizados.
- **Presupuestos pendientes:**
  - **Se cuentan:** presupuestos en estado **enviado**, no borrados, cuyo vencimiento no pasó (sin fecha de validez, o con fecha de hoy o posterior). Es una foto del momento, no del período.
  - **No se cuentan:** borradores, aprobados, rechazados, anulados ni vencidos.

Los tres van en «Indicadores de empresa» (Profesional y Empresa), protegidos con `PlanFeature::CompanyIndicators` en el servidor. Cada uno muestra su definición en pantalla.

## 9. Los cinco PR

1. **Infraestructura de permisos y registro de actividad** (detalle abajo).
2. **Gestión de administrativos:**
   - alta, permisos, desactivación y reactivación (solo el dueño);
   - límites por plan y suspensión al bajar de plan;
   - navegación según permisos;
   - avisos por permiso.
3. **Tareas y recordatorios, e historial de contactos con clientes:**
   - tablas `tasks` y `client_interactions`;
   - alertas de tareas vencidas;
   - presupuestos enviados sin respuesta;
   - datos incompletos de clientes y edificios.
4. **Ficha CRM del cliente.** Dentro de Clientes: edificios y ascensores, presupuestos, órdenes y reclamos, reportes, cobros, documentos, tareas y contactos.
5. **Indicadores adicionales** de la sección 8.

## 10. Alcance del PR 1: infraestructura de permisos y registro de actividad

**Incluye:**
- **Datos:**
  - migración nueva que agrega `users.permissions` (JSON, nullable);
  - límite `admin_users` en los planes (0, 1 y 5) y la tabla `activity_logs`.
  - Solo suma columnas, tabla y claves; no toca datos existentes.
- **Código central:**
  - enum `Permission`, servicio `Access`, `Gate::before` y Policies para los 16 recursos del panel;
  - `canAccess` de las páginas según la sección 6.
- **Comprobaciones:** reemplazo controlado de las 46 de `isAdmin()` según la tabla de la sección 6.
- **Riesgos altos de la sección 7:**
  - definición explícita de técnico (riesgo 1);
  - bloqueo de la app del técnico para el rol `staff` (riesgo 2).
- **Registro:** servicio `ActivityLog` y registro de las acciones sensibles de la sección 4 para el dueño (ya útil sin administrativos), más la inclusión en `CompanyEraser`.

**No incluye:**
- crear administrativos desde la interfaz;
- la pantalla de permisos;
- la pantalla «Actividad»;
- los avisos por permiso.

Todo eso va en el PR 2. Los administrativos solo existen en las pruebas.

**Pruebas del PR 1:**
- **El dueño no cambia:** recorre todas las pantallas, acciones y archivos actuales con el resultado de hoy.
- **Administrativo sin permisos:** nada del panel, de las rutas directas, de los archivos ni de las llamadas Livewire manipuladas (403 o 404).
- **Por área:** cada permiso de ver o gestionar habilita exactamente su área y nada más.
- **Reservado al dueño:** suscripción, «Mi empresa», usuarios, exportación y SuperAdmin, siempre bloqueados, aunque el administrativo tenga todos los permisos.
- **Aislamiento entre empresas:** con todos los permisos, ningún dato ni archivo de otra empresa.
- **Cobertura:** cada recurso, página, acción de Filament y ruta de archivo declara su permiso; si aparece una sin declarar, la prueba falla.
- **Técnicos:** un administrativo no ocupa cupo de técnicos ni aparece como asignable.
- **Registro:** guarda actor, acción, registro y fecha; no guarda secretos; sobrevive al borrado del usuario; se borra con la empresa.
- **Límites:** los de administrativos quedan en la base (su uso se prueba en el PR 2).
- **Ejecución:** SQLite y MySQL, build y vistas.
