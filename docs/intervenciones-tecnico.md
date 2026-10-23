# Intervenciones del técnico (urgencias y trabajos no programados)

Rama `mejora/intervenciones-tecnico`.

## Problema

- **Antes:** el remito de un trabajo que no era el mantenimiento o la inspección mensual salía solo de una orden de trabajo creada por la oficina.
- **Fuera de horario:** fines de semana, de noche o con reclamos que llegan por WhatsApp, el técnico no podía documentar la urgencia ni pedir la firma sin esperar a la oficina.
- **Varios problemas en una visita:** no había forma de registrarlos; cada orden tenía un solo texto y un solo remito.

## Modelo elegido

| Concepto | Dónde vive | Notas |
|---|---|---|
| La **visita** | `work_orders`, tipo `claim`, `origin = 'technician'` y `created_by` = técnico | Se crea asignada a él y **en curso**. Las órdenes existentes tienen `origin = null` (oficina). |
| Cada **problema atendido** | `reports`, `kind = 'intervention'` y `work_order_id` = la orden | Guarda el problema informado, el canal (`channel`: WhatsApp, teléfono, encargado, detectado, otro), las fotos y, al firmar, `work_done` y `result`. |
| La **documentación** | El remito de siempre (`DeliveryNoteController`) | Firma del técnico y del cliente, materiales y stock, participantes, visita. **Un remito por orden**, como antes. |

Por qué este modelo:
- **Una visita con tres problemas** es una orden, una firma del cliente y un remito que lista los tres trabajos, cada uno con su problema, lo hecho y su resultado.
- **Sin duplicados:** no se crean órdenes ni remitos de más, y cada trabajo sigue siendo trazable por separado.
- **Orden de la oficina en curso:** el técnico asignado también le puede sumar trabajos. La orden sigue siendo de la oficina.
- **Remito firmado:** no se reescribe. Un trabajo posterior es otra intervención con su propio remito.

## Flujo del técnico (desde su celular, cualquier día y hora)

1. **Reportes → «Registrar intervención o urgencia».**
2. **Datos del reclamo:**
   - edificio y equipo;
   - si es una urgencia o una intervención no programada;
   - cómo llegó el reclamo;
   - el problema;
   - fotos (opcional).
3. **Orden creada:** se crea la orden y se abre su detalle, con «Trabajos de esta visita».
4. **Otros problemas:** «Agregar otro trabajo de esta visita», para cada problema más que resuelva ahí.
5. **Remito:** «Hacer remito y finalizar». Para cada trabajo, qué hizo y cómo quedó (resuelto, resuelto parcialmente, requiere otra visita o no se pudo resolver). Después, los materiales si los usó, su firma y la del responsable del edificio.
6. **Al firmar:**
   - la orden queda completada;
   - cada trabajo queda «Resuelto» o, si no se resolvió del todo, «En revisión» para la oficina;
   - se avisa a la oficina («Trabajo terminado»).

La oficina recibe el aviso en el momento en que se abre cada intervención: «Urgencia atendida por un técnico» o «Intervención de un técnico».

## Qué ve la oficina (panel)

- **Órdenes de trabajo:**
  - columna «Origen»: «Oficina» o «Técnico: nombre», con la cantidad de trabajos;
  - columna «Remito»;
  - filtros «Origen» y «Documentación pendiente (en curso sin remito)».
- **Detalle de la orden:** pestaña «Trabajos de la visita», con técnico, equipo, canal, problema, lo realizado, el resultado y las fotos.
- **Reportes:** por defecto muestran fallas e intervenciones, en todos los planes. Cada intervención muestra el canal, el resultado, la orden (con su origen) y el remito.
- **El remito:** en la web, el PDF y el enlace público, lista los trabajos con su resultado.

## Seguridad

- **Quién puede usarlo:** solo los técnicos y el dueño de la empresa. Nunca los usuarios del portal, los proveedores, el SuperAdmin ni los administrativos.
- **Edificios permitidos:**
  - los activos de su empresa que tiene asignados;
  - todos los de su empresa mientras está **de guardia** en ese momento, o si tiene la **autorización de urgencias** (ver más abajo);
  - el dueño, todos.
- **Lo que sale del servidor, nunca del navegador:** empresa, autor, tipo, origen, orden y prioridad. El equipo se valida contra el edificio.
- **Sumar trabajos:** solo si la orden es de su empresa, está en curso, está **asignada a él** y no tiene remito, con bloqueo de fila.
- **Al firmar el remito:**
  - se exigen lo realizado y el resultado de **cada** trabajo de esa orden;
  - lo que se mande para trabajos de otras órdenes se ignora;
  - un técnico no asignado recibe 403, igual que antes.
- **Reenvíos:** `submission_token`, único por usuario, hace que un reenvío del mismo formulario devuelva lo ya creado.
- **Fotos:** se guardan con el mismo servicio y los mismos permisos que las de los reportes.
- **Sin permisos administrativos nuevos** para el técnico.

## Edificios, guardias y autorización de urgencias (aprobado el 23/10/2026)

- **Edificios donde un técnico puede abrir una intervención:**
  - los activos de su empresa que tiene asignados;
  - todos los de su empresa **mientras está de guardia** (turno vigente y horario de la guardia que cubre ese momento);
  - todos los de su empresa si tiene la **autorización de urgencias**;
  - el dueño, todos.
- **Autorización de urgencias** (`UrgentCoverage`, columnas `users.urgent_coverage_granted_at` / `_by`, migración 2027_01_24):
  - **Dónde:** en la ficha del técnico (Técnicos → ver), con «Autorizar urgencias en cualquier edificio» o «Revocar». También figura en el listado (columna «Urgencias») y en el subtítulo de la ficha, con quién la otorgó y cuándo.
  - **Quién la cambia:** el dueño, o un administrativo activo con permiso para **gestionar Guardias** que además vea Técnicos. Nunca el propio técnico, otro técnico, el dueño de otra empresa ni el SuperAdmin. Las columnas no son asignables desde formularios.
  - **Alcance:** solo abrir intervenciones. No cambia mantenimientos, inspecciones, edificios asignados, clientes ni órdenes ajenas, ni lo que ve en la app.
  - **Revocar:** vale desde el pedido siguiente, también para pedidos directos al backend. Las intervenciones ya registradas siguen siendo suyas.
  - **Historial:** cada alta y cada baja queda en la actividad de la empresa (`technician.urgent_coverage.granted` / `.revoked`).
- **Guardias:** sin cambios en su funcionamiento. Una intervención abierta mientras el técnico está de guardia queda vinculada a ese turno (`work_orders.on_call_shift_id`) y aparece en «Trabajos durante la guardia». Fuera del horario de la guardia no se vincula, aunque tenga la autorización.

## Indicadores

- Cada problema atendido en una intervención cuenta **una vez y en su propio equipo** en los indicadores («reclamos»), en el análisis de fallas recurrentes y en el historial del ascensor.
- La orden que abre el técnico no se suma aparte (`WorkOrder::scopeClaimSignals`).
- No consumen el cupo de reportes ni cuentan como «fallas reportadas».

## Decisiones pendientes

1. **Facturación:** ni las órdenes ni los remitos están vinculados a presupuestos o cobros; se cargan aparte, como con las órdenes de la oficina. Si se quiere «Generar cobro desde el remito», hay que definir la regla.
2. **Reclamos del portal y del QR:** sin cambios. La oficina crea la orden desde el reclamo; no se vinculan solos con las intervenciones del técnico.
