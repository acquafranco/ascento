# Visitas técnicas (llegada, trabajos, remitos y salida)

Una **visita técnica** es la presencia física de **un técnico** en **un edificio**: empieza con «Llegué al edificio» y termina con «Finalizar visita y retirarme». Adentro puede haber varios trabajos (órdenes, urgencias, mantenimiento, inspección) y varios remitos. Cada trabajo conserva su estado, su historial y su remito.

Tabla `site_visits` (modelo `SiteVisit`, servicio `App\Services\WorkOrders\SiteVisits`). No es `building_visits`: esa sigue siendo la visita del período (mantenimiento de octubre) o la de una orden, una por remito.

## Cómo funcionaba antes

- La llegada y la salida (d777b75) eran **por orden**: cada orden tenía su deslizador «Llegué» y «Terminé y me retiro».
- El remito se podía firmar sin llegada. El botón «Hacer remito y finalizar» aparecía junto a la llegada.
- Con varias órdenes en el mismo edificio, había que registrar la llegada y la salida de cada una. Los mantenimientos e inspecciones no tenían llegada ni salida.
- El remito de una orden completaba la orden, y eso parecía «terminar el trabajo». La salida se recordaba en el listado de remitos, orden por orden.

## Qué entra en una visita

Siempre son del **mismo técnico**, la **misma empresa** y el **mismo edificio**, mientras la visita está abierta. Nada se agrupa por fecha ni por edificio solamente.

- **Al llegar**, entran las órdenes asignadas a él en ese edificio que están en curso y sin remito.
- **Estando ahí**, entra lo que toma («Tomar trabajo») o abre (intervención o urgencia). No repite la llegada: su llegada es la hora en que entró en la visita, nunca anterior a la orden.
- **Remitos**: los que firma mientras la visita está abierta (`building_visits.site_visit_id`).
- **Otros técnicos**: las órdenes de otro técnico nunca entran. Si dos técnicos van juntos, cada uno registra su llegada.
- **Una sola visita abierta por técnico.** Para llegar a otro edificio, primero tiene que finalizar la que tiene. No se cierra sola ni se inventa una salida.
- **Copia de horarios a cada orden.** La llegada y la salida de la visita se copian a cada orden (`arrived_at`, `left_at`). Así los tiempos de atención, el historial del ascensor, la planilla y el panel siguen funcionando igual. Una llegada que la orden ya tenía no se pisa.

## Qué ve el técnico en cada paso

1. **Tomar el trabajo.** Como siempre; no registra la llegada. En el listado, la orden muestra «Llegué al edificio» (lleva al detalle), no el remito. En Inicio: «Tomaste la orden #N en X. Cuando llegues, registrá la llegada.»
2. **Llegué al edificio.** El deslizador está en la orden y también en el formulario del remito, si llega por ahí.
   - Guarda la hora del servidor, una sola vez, y no es editable.
   - No usa GPS. Abrir la orden no registra nada.
   - Si tiene una visita abierta en otro edificio, se le avisa y se le lleva a finalizarla.
3. **Trabajos y remitos.** Después de la llegada aparecen «Hacer remito de esta orden» y «Agregar otro trabajo de esta visita». El formulario del remito muestra «En el edificio desde HH:MM».
   - Sin llegada, el formulario queda oculto detrás del paso «Primero, la llegada».
   - La excepción es «No pude registrar la llegada», que exige un motivo (mínimo 10 caracteres). El motivo queda en `building_visits.arrival_exception` y la llegada queda «Sin registrar».
   - Firmas y validaciones, sin cambios. **El remito no registra la salida ni cierra la visita.**
4. **Finalizar visita y retirarme.** Está en Inicio y en Remitos, y en la orden una vez hecho su remito. La tarjeta «Visita en curso» muestra el edificio, la hora de llegada, los remitos y las órdenes.
   - Si quedan trabajos sin remito, los lista con su enlace. **No bloquea:** esos trabajos siguen en curso y no se cierran.
   - Guarda la hora del servidor una vez y la copia a las órdenes de la visita. Las duraciones salen solas.
   - **Excepción** («No puedo registrar la salida»): cierra la visita **sin salida**, con motivo. Queda quién y cuándo; la salida queda «Sin registrar». Después de **24 h** desde la llegada, ya no se registra la salida: solo queda esta excepción.

Una orden con llegada propia, de antes de las visitas, conserva su «Terminé y me retiro» por orden.

## Módulos

| Módulo | Llegada | Remito | Salida |
|---|---|---|---|
| Orden de la oficina (reclamo, reparación, etc.) | Después de «Tomar trabajo» | Después de la llegada (o excepción con motivo) | De la visita |
| Intervención o urgencia del técnico (también de guardia) | Al abrirla queda en curso; si ya está en el edificio, entra en su visita sin repetir | Igual | De la visita |
| Mantenimiento e inspección del mes | En el formulario del remito | Igual; varios remitos en la misma visita | De la visita |
| Oficina (permiso Documentos) | No registra llegadas | Como siempre | — |

- **Guardia.** Estar de guardia **no** permite abrir visitas en cualquier edificio. La llegada exige tener el edificio asignado o una orden en curso asignada ahí. Su intervención de guardia (regla de guardias existente) le da esa orden.
- **Formularios viejos.** Un formulario abierto antes del despliegue (sin `visit_flow`) se acepta como antes: el remito queda sin visita y con la llegada «Sin registrar».

## Administrador

- Crea y asigna órdenes como siempre. **Nunca carga** la llegada ni la salida.
- En la orden, «Tiempos de atención» muestra el aviso y su origen, la llegada, la salida y las tres duraciones («Sin registrar» si falta un horario). Ahora también muestra **«Visita técnica»**: técnico, llegada, salida, cuántas órdenes y remitos, y si sigue en curso o se cerró sin salida (quién, cuándo y motivo).
- **«Cerrar visita sin salida».** Acción del encabezado de la orden, solo con permiso de gestionar Órdenes. Exige motivo, queda en la Actividad y nunca inventa una hora.

## Recordatorios

El comando `site-visits:remind` corre cada 15 minutos. Usa el Notifier de siempre (bandeja más push si el técnico lo activó, sin correo) y no agrega ningún servicio pago.

- **Llegada.** Se manda si tomó un trabajo hace más de 60 min, sin llegada ni visita, y sin remito. Va una vez por orden (`work_orders.arrival_reminded_at` y la clave del aviso).
- **Salida.** Se manda si la visita está abierta, sin trabajos pendientes y con el último remito de hace más de 30 min, o si está abierta hace más de 6 h. Va una vez por visita (`site_visits.exit_reminded_at`).
- **Nunca** dice que salió y no se repite. En Inicio, los avisos desaparecen cuando el paso está hecho.

## «Generar remito» con dos toques

- **Reproducción.** En Chrome móvil (Puppeteer táctil) un toque funcionaba. Se reprodujo el caso real del celular: el último campo (la aclaración) queda enfocado justo arriba del botón. Al tocar el botón, el campo pierde el foco, el teclado se cierra y la página se reacomoda. El «click» cae fuera del botón y no pasa nada, así que hay que tocar otra vez. Simulando ese reacomodo, el primer toque **no navegaba**; con la corrección, **navega**.
- **Corrección.** En `pointerdown` táctil sobre «Generar remito», si hay un campo enfocado, se conserva el foco hasta el click. El botón no se mueve bajo el dedo, el envío sale con un toque y el teclado se cierra al navegar.
- **Relacionado:**
  - Sin respuesta en 15 s, el botón volvía a verse activo pero quedaba bloqueado. Ahora vuelve a andar de verdad, con su texto.
  - Un reintento del remito de una **orden** (respuesta perdida, otro toque) devolvía «Esta orden ya tiene un remito» (409). Ahora el formulario de la orden también lleva `submission_token` y el reintento da la misma confirmación, sin duplicar.

## Migración

`2027_01_27_100000_create_site_visits` es solo aditiva: crea la tabla `site_visits` y agrega columnas nullables (`work_orders.site_visit_id`, `work_orders.arrival_reminded_at`, `building_visits.site_visit_id`, `building_visits.arrival_exception`). No toca registros existentes. Se probó en MySQL descartable: migrar, revertir y volver a migrar, con los datos iguales.

## Pendiente o a decidir

- **Plazos.** La salida admite hasta 24 h desde la llegada. Los recordatorios salen a los 60 min de tomar el trabajo y a los 30 min del último remito (o a las 6 h). Son constantes en el código; si la empresa quiere otros valores, se pueden pasar a configuración.
- **Dos técnicos juntos.** Cada uno registra su visita. La orden guarda la llegada del primero que llegó (como antes).
- **Auditoría de técnicos.** La «Actividad» del panel registra solo a usuarios del panel. Las excepciones del técnico quedan en la fila (motivo, quién y cuándo) y se ven en la orden.
