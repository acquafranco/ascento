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
  - todos los de su empresa mientras está **de guardia** en ese momento;
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

## Decisiones a confirmar

1. **Edificios no asignados:**
   - Un técnico que no está de guardia solo ve sus edificios asignados.
   - Si las urgencias de fin de semana las atiende alguien sin guardia cargada, conviene cargar la guardia en «Guardias».
   - La otra opción es habilitar todos los edificios de la empresa.
2. **Cupo del plan:** las intervenciones no consumen el cupo mensual de reportes, para que una urgencia nunca quede bloqueada por el plan. Tampoco cuentan como «fallas» en los indicadores; sí cuentan como órdenes completadas.
3. **Revisión de la oficina:** no hay aprobación previa (no frena la urgencia). Lo que no quedó «Resuelto» pasa a «En revisión» y la oficina lo ve en Reportes y en el filtro de documentación pendiente.
4. **Prioridad:**
   - una urgencia crea la orden con prioridad urgente;
   - una intervención no programada, con prioridad media;
   - sumar una urgencia a una visita sube su prioridad y nunca la baja.
5. **Reclamos del portal y del QR:** siguen igual, con «Crear orden» desde el reclamo. No se vinculan con las intervenciones del técnico.
