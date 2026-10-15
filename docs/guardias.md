# Guardias técnicas

Cobertura de atención técnica fuera del horario habitual. Sirve para organizar quién cubre, consultar quién está de guardia en cualquier momento y vincular los trabajos que surgen durante una guardia con las órdenes de trabajo y los remitos de siempre.

No es un sistema de despacho ni de emergencias: no garantiza atención ni responde solo. Tampoco liquida horas extra ni pagos.

## Dónde está

- **Panel:** Operaciones → **Guardias** (`OnCallScheduleResource`, área de permiso «Guardias»).
  - **Arriba**, sin abrir nada:
    - de guardia ahora;
    - «¿Quién está de guardia el…?» (fecha y hora);
    - próximas franjas de 7 días, con el técnico de cada una;
    - últimos cambios.
  - **Lista:** cada guardia con su horario, modalidad, quién está ahora y huecos de los próximos 14 días.
  - **Ficha de una guardia:**
    - resumen, con los huecos detallados;
    - pestañas **Turnos** (con las acciones), **Historial de cambios** y **Trabajos durante la guardia**.
- **App del técnico:** pestaña **Guardias** en la barra principal (`/{empresa}/guardias`). En el Inicio aparece un aviso breve cuando está de guardia en ese momento.

## Modelo

| Tabla | Qué guarda |
|---|---|
| `on_call_schedules` | La guardia: nombre, días y horario cubiertos, cuántos técnicos a la vez, modalidad (manual o rotativa) e indicaciones para los técnicos. |
| `on_call_members` | Participantes de una rotación, en orden, con exclusión temporal (desde/hasta). |
| `on_call_shifts` | Asignaciones efectivas: técnico + período (sin fin = permanente). Nunca se borran: se marcan «modificado» o «cancelado» y se crean los turnos resultantes. |
| `on_call_changes` | Historial inmutable: qué cambió, técnico anterior y nuevo, período, motivo, quién y cuándo. |
| `work_orders.on_call_shift_id` | La orden se originó durante ese turno de guardia. |

La lógica está en `App\Services\OnCall\OnCallService` (asignar, cambiar, cancelar, finalizar, consultar, huecos) y en `RotationService`. Cada cambio corre en una transacción con bloqueo de la guardia, así dos operaciones simultáneas no se pisan ni dejan superposiciones.

## Horarios

- Cada franja empieza los días elegidos a la hora «Desde» y termina a la hora «Hasta».
  - Si «Hasta» es menor o igual a «Desde», termina al día siguiente (viernes 18:00 → sábado 08:00).
  - «00:00» es medianoche.
  - Si «Desde» y «Hasta» son iguales, la franja dura 24 horas.
- El inicio está incluido y el fin no: a las 08:00 en punto la franja ya terminó.
- Las horas se toman en la zona horaria de la aplicación (`America/Argentina/Buenos_Aires`; Argentina no tiene horario de verano).
- Un técnico está **de guardia** en un momento cuando tiene un turno vigente **y** el horario de la guardia cubre ese momento.
- **Ejemplo:** un turno de lunes a lunes en una guardia nocturna cubre solo las noches de esa semana.
- **El horario no se cambia una vez que la guardia tuvo turnos:** el historial depende de él. Para otro horario, se finaliza la guardia y se crea una nueva. El nombre, las indicaciones y la rotación sí se pueden editar.
- **Límite:** todas las franjas de una guardia tienen el mismo horario y duran 24 horas como máximo. Una cobertura continua de viernes 18:00 a lunes 08:00 se arma con dos guardias, o con franjas de 24 h que empiezan los viernes, sábados y domingos.

## Asignaciones

- **Permanente:** técnico, «desde» y sin «hasta». Se termina con «Finalizar», desde ahora o desde una fecha. Lo cubierto antes queda en el historial.
- **Por período:** técnico, «desde» y «hasta» (una semana, un fin de semana, un feriado, las vacaciones de otro técnico).
- **Rotativa:**
  - Se configuran los participantes (en orden), la duración de cada turno en días y la fecha de inicio.
  - Cada turno empieza a la hora «Desde», así una franja nunca queda partida entre dos técnicos.
  - Se generan solo turnos **futuros**, hasta 8 semanas adelante, y el comando diario `oncall:rotations` los mantiene.
  - Al cambiar el orden, los participantes, la duración o una exclusión, se recalculan solo los turnos futuros que generó la rotación. Los pasados, los que están en curso y los períodos con cambios manuales no se tocan.
  - Los turnos recalculados quedan «cancelados» con motivo, y se avisa a quien los perdió.
  - Un técnico excluido o desactivado se saltea y toma el turno el siguiente. Si no hay nadie disponible, el turno queda sin técnico: se informa como hueco y no se inventa una asignación.
- **Varios técnicos a la vez:** «Técnicos a la vez» indica cuántos cubren al mismo tiempo. Nunca se asignan más, ni el mismo técnico dos veces en el mismo período de una guardia. Entre guardias distintas, un mismo técnico puede estar en las dos, porque cada guardia tiene su propia cobertura.

## Cambios de guardia e historial

- **Cambiar técnico** (todo el turno o una parte, por ejemplo el sábado):
  - el turno original queda «modificado», con su técnico, motivo, quién y cuándo;
  - se crean el tramo anterior (mismo técnico), el reemplazo y el tramo posterior.
- **Cancelar:** solo turnos que todavía no empezaron. Su período queda sin técnico y se ve como hueco.
- **Finalizar guardia:** cancela lo futuro, corta lo vigente desde ahora y conserva todo el historial.
- **El pasado no se modifica:** un cambio empieza ahora o más adelante.
- **Trabajos ya vinculados:** una orden vinculada a un turno sigue apuntando a ese turno y a su técnico aunque después haya un reemplazo.

## Trabajos durante una guardia y remitos

- En la orden de trabajo, el campo **«Guardia (si se originó durante una)»** lo elige quien la carga.
  - **Nunca se deduce por la hora:** un trabajo nocturno sin ese dato no es de guardia.
  - **Opciones:** los turnos vigentes o de los últimos 3 días, de la propia empresa (el servidor lo vuelve a verificar).
  - **Ayuda en el formulario:** muestra quién está de guardia ahora, para asignarle el trabajo.
- **Se reutiliza el circuito existente:** quién hace el trabajo son los técnicos asignados a la orden, y la ejecución y el remito siguen igual.
- **En las listas:** el listado de órdenes tiene la columna y el filtro «Guardia», y la guardia muestra sus «Trabajos durante la guardia».
- **Remitos:** no hay un tipo nuevo. En la vista del remito en el panel aparece una línea discreta «Guardia». El PDF, el portal y lo que firma el cliente no cambian. Una vez que la orden tiene remito, su guardia ya no se cambia.

## Pestaña «Guardias» del técnico

- **Qué muestra:**
  - si está de guardia ahora (destacado, hasta qué hora, con las indicaciones de la guardia);
  - sus guardias vigentes y próximas, con la próxima franja;
  - los trabajos de guardia que **tiene asignados** (enlazan a «Trabajos», donde los ejecuta);
  - los cambios que lo afectaron («Te reemplaza…», «Reemplazás a…», cancelaciones);
  - sus guardias anteriores.
  - Si no tiene ninguna, un mensaje claro.
- **Solo consulta:** no hay rutas para que el técnico asigne, cambie o cancele. Todas las consultas se filtran en el servidor por el técnico autenticado y su empresa.
- **No duplica:** no repite órdenes, remitos ni mantenimientos; distingue «tener guardia» de «tener un trabajo asignado» y de «trabajo realizado».

## Avisos

Se usa el `Notifier` existente: bandeja interna, y push o Telegram si el técnico los activó, sin duplicar.

- **Nueva guardia asignada:** asignación manual o cambio de guardia a su favor. Los turnos que genera una rotación no avisan uno por uno; avisa el recordatorio.
- **Cambió una de tus guardias:** cancelación, finalización, reemplazo o turno futuro recalculado por la rotación.
- **Tu guardia empieza pronto:** el comando `oncall:remind` corre cada hora y avisa una sola vez por turno, dentro de las 24 horas previas.

Los avisos se generan y se intentan entregar; el sistema no confirma que el técnico los haya leído.

## Permisos y aislamiento

- **Área «Guardias»:** con «ver» se consulta y con «gestionar» se crea, asigna, cambia, cancela y finaliza. Cada acción lo verifica en el servidor.
- **Trabajos de la guardia:** la pestaña «Trabajos durante la guardia» exige el permiso de Órdenes de trabajo.
- **Técnicos:** no entran al panel; en su app solo ven lo propio.
- **Empresas:** todo se filtra por empresa. No se puede asignar un técnico de otra empresa, ni vincular una orden a la guardia de otra.
- **Exportación:** incluye las hojas «Guardias», «Turnos de guardia» y «Cambios de guardia».
- **Borrado:** el borrado de empresa elimina sus guardias.

## No incluido (decisiones)

- Horas extra, nómina o pagos.
- Despacho automático, central telefónica o atención al público.
- Solicitudes de cambio iniciadas por el técnico: las coordina la oficina y quedan registradas.
- Mostrar las guardias en la Agenda de mantenimientos: es otra cosa, y mezclarlas confundiría. La Agenda no cambia.
