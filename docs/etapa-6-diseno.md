# Etapa 6: rol administrativo, herramientas, CRM e indicadores (propuesta)

Estado: **propuesta para aprobar**. No hay código de esta etapa todavía.

## Punto de partida (relevado en el código, rama desde `main` `6f8d623`)

- **Roles hoy:** `users.role` = `admin` (el que registró la empresa), `client` (portal) o técnico (cualquier otro valor; `job_type` = mantenimiento o inspección). `is_super_admin` aparte.
- **Una empresa tiene un solo admin en la práctica.** El panel crea usuarios técnicos; no hay forma de dar el rol de admin. `Company::owner()` es el admin más antiguo.
- **Autorización del panel:** un único control. Entra quien es admin o SuperAdmin (`User::canAccessPanel`); adentro, todo admin puede todo. No hay Policies ni Gates. Hay 16 recursos y 10 páginas; 56 métodos `canAccess` / `canCreate` / `canEdit` / `canDelete` / `shouldRegisterNavigation` sueltos, y 46 llamadas a `isAdmin()` (9 en controladores de la app web, 7 en páginas del panel).
- **Ya existe para 6.2:** Agenda (vencidas, pendientes, sin técnico, visitas en curso), Centro de atención (16 alertas: visitas no realizadas o vencidas, edificios sin técnico, órdenes sin tomar o trabadas, órdenes urgentes, reportes graves, presupuestos que vencen en 5 días, stock bajo, cobros vencidos, fallas repetidas, ficha técnica incompleta, contratos por terminar, deudas viejas), Indicadores, Cobranzas, Stock y Presupuestos con eventos (`QuoteEvent`).
- **Falta para 6.2:** tareas y recordatorios; historial de cambios relevantes; seguimiento de componentes enviados a reparar (no existe el concepto: depende de la Etapa 8, reparaciones).
- **CRM hoy:** cliente con contacto, teléfono, correo y notas; edificios y ascensores por cliente; presupuestos con historial de envíos; cobranzas; portal. **Falta:** historial de contactos, tareas por cliente y una ficha que lo reúna.
- **Indicadores hoy (por plan, verificado en el servidor):** básicos en los tres planes (cumplimiento de mantenimientos del mes, órdenes abiertas y su antigüedad, reportes y reclamos contra el mes anterior); de empresa (Profesional y Empresa: evolución de 6 meses, actividad por técnico, fallas que se repiten, aprobación de presupuestos); avanzados (Empresa).

## 6.1 Rol administrativo

**Recomendación: reutilizar el panel actual**, con un rol nuevo y permisos por capacidad. Un panel aparte duplicaría recursos, formularios y pruebas, y cada pantalla nueva habría que hacerla dos veces.

### Modelo
- **Rol nuevo:** `users.role = 'staff'` (en pantalla, «Administrativo»). No es técnico ni admin: no usa la app del técnico y no cuenta como propietario.
- **Capacidades:** columna JSON `users.capabilities` con una lista cerrada de claves (enum `Capability`), que elige el admin para cada administrativo. Sin capacidades, no ve nada del panel salvo su inicio.
- **Quién decide:** solo el admin de la empresa (hoy, el propietario) crea administrativos, les asigna capacidades y los desactiva. Un administrativo nunca puede editar usuarios, roles ni capacidades (tampoco las suyas).
- **Un único punto de control:** `Gate::before` y Policies por modelo. Filament las usa solas en listar, ver, crear, editar, borrar y acciones masivas. Además, `canAccess` de cada página y los controladores de la app web llaman al mismo servicio. Los 46 `isAdmin()` actuales se revisan uno por uno: los que significan «propietario» quedan así; los que significan «puede gestionar X» pasan a la capacidad correspondiente.

### Capacidades propuestas (ver / gestionar)
| Capacidad | Qué habilita |
|---|---|
| Clientes (ver / gestionar) | Clientes, contactos y su ficha |
| Edificios (ver / gestionar) | Edificios, mapa |
| Ascensores (ver) y Documentos (gestionar) | Legajo técnico y documentos del ascensor |
| Coordinar técnicos | Asignar técnicos a edificios y órdenes (sin crear ni editar usuarios) |
| Programar visitas | Agenda, frecuencias, visitas en curso |
| Mantenimientos (ver) / Inspecciones (gestionar) | Listados, detalle, fotos y reportes (fotos solo con plan Empresa) |
| Órdenes de trabajo (gestionar) | Crear, asignar, cerrar |
| Presupuestos (preparar / enviar) | Preparar sin enviar; enviar es aparte |
| Remitos (ver) | Lectura y PDF |
| Reportes (ver / gestionar estado) | Reportes de falla |
| Indicadores (ver) | Según el plan de la empresa |
| Tareas y recordatorios | Las propias y las asignadas |

### Siempre fuera del administrativo
- Suscripción, plan y pagos (salvo que se decida una capacidad «ver suscripción», solo lectura).
- «Mi empresa»: datos fiscales, marca y los interruptores de fotos.
- Usuarios: crear, editar, desactivar, roles y capacidades.
- Exportación completa de datos, porque contiene todo, incluidas cobranzas y usuarios.
- Herramientas del SuperAdmin y otras empresas: el aislamiento por empresa no cambia.
- Cobranzas, stock y servicios no estaban en tu lista. Propongo dejarlos fuera por ahora; se pueden sumar como capacidades después.

### Desactivar y auditar
- **Desactivar:** se usa la desactivación de usuarios que ya existe (no borra). Corta sesiones y acceso; el historial queda a su nombre.
- **Registro de acciones sensibles:** tabla nueva `activity_logs` con empresa, usuario, acción, registro afectado, cambios resumidos (sin secretos), IP y fecha. Registra:
  - cambios de capacidades y desactivaciones;
  - borrados y desactivaciones de clientes y edificios;
  - asignaciones;
  - envío y aprobación de presupuestos;
  - cancelación de órdenes;
  - exportaciones.

  El admin lo ve en «Actividad». Es también el «historial de cambios relevantes» de 6.2.

### Riesgos
Es el cambio de permisos más amplio del plan. Mitigación:
- todo administrativo empieza sin capacidades;
- los admins actuales no cambian de comportamiento;
- una prueba recorre todos los recursos, páginas y rutas, y falla si alguno no declara su capacidad.

## 6.2 Herramientas administrativas
Solo lo que hoy falta y resuelve una necesidad concreta, sin widgets nuevos de relleno:
1. **Tareas y recordatorios:** tabla nueva `tasks` con título, vencimiento, responsable, estado, quién la completó y vínculo opcional a cliente, edificio, ascensor, presupuesto u orden. Aparecen en el Centro de atención (vencidas y de hoy), en la ficha del cliente y con un aviso el día del vencimiento.
2. **Historial de cambios relevantes:** el registro de 6.1.
3. **Presupuestos pendientes de respuesta:** alerta nueva para los enviados sin respuesta hace más de N días. Hoy solo se avisan los que vencen.
4. **Información incompleta:** sumar clientes sin contacto ni correo y edificios sin cliente o sin dirección ubicable. La ficha técnica incompleta ya está.
5. **Componentes enviados a reparar:** queda para la Etapa 8, porque el concepto no existe.

Agenda, visitas próximas y vencidas, asignaciones pendientes y reparaciones abiertas ya existen: se reutilizan, no se duplican.

## 6.3 CRM conectado a la operación
- **Ficha del cliente** (dentro de Clientes, no una pantalla nueva): edificios y ascensores, presupuestos enviados y pendientes, órdenes y reclamos abiertos, reportes recientes, cobros pendientes, documentos vencidos o faltantes, tareas e historial de contactos.
- **Historial de contactos:** tabla nueva `client_interactions` con tipo (llamada, correo, visita, reunión, nota), fecha, resumen, quién y vínculo opcional a edificio o presupuesto. Se carga a mano: no se inventan contactos.
- **Oportunidades:** el sistema ya tiene presupuestos (borrador, enviado, aprobado, rechazado, vencido). Se usan como seguimiento comercial; no se crea otro concepto.

## 6.4 Indicadores por plan
Solo métricas que se pueden calcular hoy con datos verificables, cada una con su definición visible:

| Indicador | Definición | Hoy |
|---|---|---|
| Cumplimiento de visitas | Realizadas sobre previstas del período (VisitSchedule) | Existe |
| Mantenimientos realizados | Visitas de mantenimiento con remito realizado | Existe en la evolución |
| Inspecciones realizadas | Igual, para inspecciones | Nuevo |
| Edificios atendidos | Edificios distintos con al menos una visita realizada en el período | Nuevo |
| Órdenes abiertas | Pendientes y en curso | Existe |
| Tiempo de resolución | Promedio entre el alta y el cierre de las órdenes | Existe |
| Presupuestos pendientes | Enviados sin respuesta | Nuevo |
| Incidencias recurrentes | Ascensores con 3 o más fallas en 90 días (FailureAnalysis) | Existe |
| Actividad de técnicos | Visitas y órdenes por técnico | Existe |

«Reparaciones pendientes» no se puede calcular hasta la Etapa 8. Todo sigue filtrado por empresa y verificado en el servidor (PlanGuard).

## Orden propuesto (PR separados)
1. Núcleo de capacidades, Policies, prueba de cobertura y registro de actividad. Sin cambios visibles para los admins actuales.
2. Alta y gestión de administrativos, navegación según capacidades y desactivación.
3. Tareas y recordatorios, y alertas nuevas.
4. Ficha del cliente e historial de contactos.
5. Indicadores nuevos según el plan que elijas.

## Decisiones que necesito antes de implementar
1. **Plan:** en qué plan va el rol administrativo, y si va con un límite de administrativos por empresa.
2. **Cupo de usuarios:** si los administrativos cuentan dentro del límite de «usuarios técnicos u operativos». Hoy, por cómo está programado, contarían.
3. **Capacidades:** si la lista y lo que queda siempre fuera (suscripción, «Mi empresa», usuarios, exportación, cobranzas, stock y servicios) está bien.
4. **Indicadores nuevos:** en qué plan va cada uno.
5. **Orden:** si avanzo con los pasos 1 y 2 de 6.1 primero, como propongo.
