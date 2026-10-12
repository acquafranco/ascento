# Visitas, asignaciones y fotos (Etapa 5)

## Frecuencia de visitas

- **Dónde se configura:** Edificios → «Frecuencia de visitas» (por fila) o en la asignación masiva. Mantenimiento e inspección tienen frecuencias independientes.
- **Opciones:** mensual, quincenal (2 visitas en el mes) y cada N meses (de 2 a 12).
- **Tabla `building_visit_plans`:** cada cambio es una fila que rige desde su mes de inicio (`starts_on`).
  - Solo se permite el mes actual o uno futuro, así los meses anteriores conservan la frecuencia que tenían y el historial no se reinterpreta.
  - Un edificio sin filas sigue mensual, como antes de la etapa.
  - **Un cambio nunca corta un período en curso** (`VisitPlanService::effectiveStart`): si el mes elegido cae en medio de un período de varios meses, rige desde el siguiente período válido y se avisa («rige desde noviembre 2026»). Ejemplo: trimestral agosto–octubre y el admin pide mensual «desde octubre» → rige desde noviembre; agosto–octubre queda intacto (hecho, o pendiente hasta octubre) y ningún mes pasado aparece vencido. Al inicio de un período, o con frecuencia mensual o quincenal, rige desde el mes elegido. En la asignación masiva, el resumen muestra desde cuándo rige en cada edificio.
  - Las frecuencias quedan versionadas: cada cambio es una fila con su mes de inicio, así los períodos anteriores se siguen calculando con la frecuencia que tenían.
- **No es la frecuencia de cobro:** `maintenance_services.frequency` no interviene. Cambiar la frecuencia de visitas no crea servicios ni cobros y no modifica precios.

### Fuente de verdad: `App\Services\Visits\VisitSchedule`

- **Período del mes:**
  - mensual o quincenal: el mes;
  - cada N meses: bloques de N meses contados desde `starts_on`;
  - si la frecuencia cambia en medio de un bloque, ese período termina antes.
- **Previstas:** 1 por período (2 si es quincenal).
- **Realizadas:** visitas con remito que no esté marcado como «no realizado». Una visita «en curso» (abierta desde Visitas para cargar fotos, todavía sin remito) ocupa su lugar pero está pendiente.
  - Un remito «no realizado» ocupa su lugar pero no cuenta como realizada; la nueva visita se coordina con una orden de trabajo, igual que antes.
  - En el motor: realizadas + no realizadas + pendientes = previstas. En pantalla (progreso), «pendientes» = previstas − realizadas, y se informa aparte cuántas tienen remito «no realizado».
- **Hecho en el período:** en frecuencias de varios meses, si la visita del período ya se hizo en otro mes, este mes no tiene nada previsto. Se muestra así, no como pendiente.
- **Vencido:** el período terminó con visitas faltantes. Un período en curso nunca figura como vencido.
- **Dónde se usa:** pantalla de Visitas del técnico, inicio del técnico, Agenda, mapa, indicadores, recordatorios, ficha del servicio y alta del remito.

### Identidad de cada visita

- **Columnas nuevas en `building_visits`:**
  - `slot`: número de visita del período (1 o 2);
  - `period_key`: por ejemplo `maintenance:2026-10:2`, la segunda visita del período que empieza en octubre.
- **Índice único (`building_id`, `period_key`):** dos visitas del mismo lugar no pueden duplicarse, aunque dos técnicos firmen a la vez. Si ocurre, el segundo recibe un aviso y no se crea nada.
- **Visitas de órdenes de trabajo:** quedan con `period_key` NULL y no participan.
- **Quincenal:** el primer remito del mes es la visita 1 y el segundo la visita 2, cada uno con su remito. Un tercero se rechaza.
- **Número de visita:** lo decide el servidor, nunca el formulario.
- **Reenvíos del mismo remito:** cada formulario lleva su identidad (`building_visits.submission_token`, única). Si el primer envío se guardó pero la respuesta no llegó y se reenvía, no se crea otra visita (en quincenal se habría registrado la 2ª visita con el mismo trabajo): responde como el primero.
- **Concurrencia:** el alta de remitos se serializa por empresa (bloqueo de fila), y además la base rechaza dos visitas con la misma identidad aunque ese bloqueo fallara.

### Datos existentes

- **Lo que hace la migración:** todas las visitas existentes eran mensuales, así que cada una pasa a ser la visita 1 de su mes.
- **Duplicados del mismo edificio, tipo y mes** (antes solo los impedía el código, no la base):
  - una sola recibe la clave: la que tiene remito, o la más antigua si ninguna tiene;
  - las demás quedan como historial sin clave y no se cuentan dos veces;
  - no se borra ni modifica nada más;
  - la migración registra en el log solo cuántos duplicados encontró.
- **Antes de migrar en producción:** `php artisan visits:check-duplicates`.
  - Es de solo lectura (solo SELECT) y muestra solo ids.
  - Para cada grupo duplicado dice qué visita va a conservar la identidad, cuáles quedan como historial y cuántos remitos tiene cada una.
  - «Revisar» marca los grupos con más de un remito: esos remitos siguen existiendo y se ven en el historial, pero solo uno cuenta en el progreso del mes.
  - Se puede correr antes y después de migrar.
- **Las migraciones se pueden volver a correr:** si una se corta a mitad (en MySQL los cambios de estructura no son transaccionales), `php artisan migrate` completa lo que faltó. El relleno de identidades va en una transacción y por tandas de edificios.
- **Revertir** (`migrate:rollback`) no borra visitas ni remitos, pero **pierde las frecuencias de visitas, el número de visita y las fotos de visitas** (tablas y columnas nuevas). Es para desarrollo, no para producción con datos cargados.

## Asignación masiva

- **Dónde:** Edificios → botón «Asignar técnicos» arriba de la lista (se eligen los edificios en el primer paso). También tildando edificios y eligiendo «Asignar técnicos» en el menú de seleccionados. Mismo asistente, mismo resumen y mismas validaciones del servidor (hasta 2 de mantenimiento y 1 inspector por edificio; edificios de otra empresa no son opciones válidas y el servicio los ignora).

- **Dónde:** Edificios → tildar varios → «Asignar técnicos».
- **Paso 1:**
  - tipo (mantenimiento o inspección);
  - técnicos, hasta 2 de mantenimiento o 1 inspector (los límites de siempre);
  - modo:
    - **agregar:** suma a los técnicos actuales;
    - **reemplazar:** deja exactamente a los elegidos;
    - **conservar:** solo asigna donde no hay nadie de ese tipo;
  - opcionalmente, la frecuencia (sin técnicos, cambia solo la frecuencia).
- **Paso 2 (resumen):** muestra qué cambia en cada edificio, quién lo tiene hoy y cuáles se saltean y por qué:
  - superaría el límite;
  - ya tiene inspector;
  - edificio desactivado;
  - no es de la empresa.
- **Al confirmar:** `BulkAssignmentService::apply`.
  - Revalida en el servidor empresa, técnicos (solo con rol técnico, de la misma empresa) y límites.
  - Bloquea los edificios y aplica en una sola transacción: ante un error inesperado no queda nada a medias.
  - Los avisos a los técnicos salen después de guardar.
- **La acción individual** «Asignar empleado» no cambió.

## Fotos de antes y después

- **Se gestionan dentro de cada trabajo, nunca en el remito.** El remito es el documento que se firma al terminar: no muestra fotos en la pantalla del técnico, el detalle, la impresión ni el PDF. Las fotos quedan guardadas en su visita u orden y se ven desde ese trabajo.
- **En el celular del técnico, «Fotos del trabajo»** (`resources/views/photos/manager.blade.php`, el mismo en la visita y en la orden):
  1. elegir «Antes» o «Después»;
  2. elegir la parte (sección), si hay más de una;
  3. «Tomar foto» (`<input type="file" accept="image/*" capture="environment">`: en la mayoría de los celulares abre la cámara trasera; si el navegador no soporta `capture`, ofrece elegir) o «Elegir de la galería» (sin `capture`, varias a la vez);
  4. cada foto muestra «Subiendo…», después «✓ Foto guardada en …» o un error con «Reintentar».
- **Dónde están los botones (sin cambiar de pantalla)** — `resources/views/photos/quick.blade.php`:
  - Visitas: en la tarjeta de cada edificio, debajo de «Marcar mantenimiento», el recuadro «Fotos de mantenimiento» con Antes / Después, la zona, «Tomar foto» y «Elegir de la galería». La primera foto abre la visita en curso del período (`POST visits/start` en JSON); el remito después completa esa misma visita. La inspección no tiene este recuadro: tiene «Crear reporte» / «Ver reporte» (ver abajo).
  - Trabajos: en la tarjeta de cada orden asignada al técnico, arriba de «Hacer remito y finalizar».
  - Pantalla del remito: sin fotos (ni botones, ni miniaturas, ni cantidad).
  - Cada recuadro muestra las fotos que se van subiendo con su estado, «✓ Foto guardada», el error con «Reintentar» y «Ver todas (N)» (la visita o la orden con todas sus fotos, observación y equipo).
  - Solo aparecen si la empresa habilitó las fotos para ese tipo de trabajo y hay trabajo pendiente o una visita en curso. Un trabajo ya hecho conserva «Fotos del trabajo (N)» para ver o agregar fotos después.
- **Visita en curso:** «Fotos del trabajo» abre la **visita en curso** del período (`building_visits.in_progress`). Abrirla de nuevo, con un doble toque, un reintento u otro técnico, devuelve la misma.
  - La visita en curso ocupa su lugar (identidad única) pero **no cuenta como realizada** ni aparece en el historial, el portal, las planillas ni los indicadores.
  - El remito de ese período **completa esa misma visita**: no se crea otra. Sin visita en curso, el remito crea la visita como siempre.
  - Las fotos se pueden cargar antes, durante o **después** del remito, desde la pantalla de la visita.
  - Una visita en curso sin remito se puede descartar con sus fotos. Un técnico solo puede descartarla si todas sus fotos las cargó él; si hay fotos de otra persona, la descarta la oficina.
  - La oficina ve las visitas en curso en la Agenda («en curso desde el …, sin remito») con un enlace para abrirlas y descartarlas.
  - **Más de 7 días sin completarse** (`VisitService::STALE_DAYS`): la Agenda muestra arriba una alerta con edificio, tipo de trabajo, fecha de inicio, estado y «Abrir la visita», y el Centro de atención la cuenta («Visitas en curso hace más de 7 días»). Nunca se cierran, se marcan realizadas ni se borran solas: el admin decide si se continúa o se descarta.
- **Órdenes de trabajo:** desde la orden, «Fotos de antes y después». Antes del remito quedan colgadas de la orden (`visit_photos.work_order_id`); al firmar, pasan también a la visita de la orden y al remito.
- **Cada foto se guarda al subirla** (de a una, achicada en el celular), así un error del remito, un reintento o un corte de señal no la pierde.
  - Cada foto lleva una identidad que genera el celular (`client_key`, única por visita y por orden): «Reintentar» tras una respuesta perdida no la duplica.
  - Salir de la pantalla con fotos subiéndose o con error pide confirmación.
- **Por sección:** equipo (opcional, solo de ese edificio) y observación (opcional), que se aplican a las fotos de esa sección.
- **Límites:**
  - 3 por sección y estado (antes / después);
  - 30 por visita y 60 MB por visita (controlados bajo bloqueo de la visita u orden, también con varios técnicos a la vez);
  - 10 MB por archivo;
  - 50 megapíxeles por imagen (se controla antes de decodificarla);
  - formatos JPG, PNG, WEBP o HEIC.
- **Procesamiento:** el mismo de las fotos de reportes (`App\Services\Media\PhotoProcessor`):
  - se vuelve a codificar a JPEG;
  - se orienta y se quitan los datos EXIF/GPS;
  - se achica a 2000 px y se genera una miniatura;
  - se guarda en el disco privado `visits/{empresa}/`.
- **Quitar una foto:** solo quien la subió, mientras las fotos de ese tipo estén habilitadas; se borran también sus archivos.
- **Una foto que termina de subir después de que su visita se descartó** no se guarda (el servidor responde «Esta visita ya no existe») y no deja archivos.

### Habilitación por tipo de trabajo

- **Dónde:** «Mi empresa» → «Fotos de los trabajos», con tres interruptores (mantenimientos, inspecciones, órdenes de trabajo). Vienen **apagados** para todas las empresas, también las existentes.
- **Se guardan al tocarlos** (con un aviso «Fotos de …: activadas/desactivadas»). Antes había que bajar hasta «Guardar cambios» y, si no, el cambio se perdía al salir.
- **Es por empresa**, no global. Sin restricciones por plan.
- **Apagado:**
  - el técnico no ve controles de carga para ese tipo;
  - el servidor rechaza cargas nuevas aunque se fuerce la petición;
  - tampoco se pueden quitar fotos ni cambiar el equipo o la observación de una sección: lo cargado queda como está;
  - al volver a habilitarlo, las fotos siguen ahí con sus relaciones y se pueden volver a completar o quitar (quien las subió);
  - las fotos ya cargadas no se borran y las siguen viendo los mismos usuarios;
  - no cambia ninguna visita, remito ni orden.
- **Una visita tiene un solo tipo de trabajo:** rige el interruptor de ese tipo. Los permisos de un tipo no dan acceso a fotos de otro (un inspector del edificio no ve las fotos del mantenimiento).

### Quién las ve (siempre verificado en el servidor)

- **El admin de la empresa:** todas las de su empresa, cargue quien las cargue, **dentro de cada trabajo** (no hay una galería global):
  - mantenimientos: al abrir uno (Operaciones → Mantenimientos), «Fotos de antes y después», por zona, Antes y Después uno al lado del otro; inspecciones: al abrir una, «Reporte de inspección» con «Ver reporte»;
  - órdenes de trabajo: al abrir la orden, la sección «Fotos del trabajo»;
  - separadas en «Antes» y «Después», por sección, con equipo, observación, quién y cuándo; «Ver» y «Descargar»;
  - una visita en curso (todavía sin remito) se abre desde la Agenda.
- **Quien la subió.**
- **Quien trabaja en esa visita u orden:**
  - técnico asignado al edificio para ese tipo de trabajo;
  - técnico asignado a la orden;
  - quien hizo la visita o participó.
- **Nunca:** clientes, otras empresas, técnicos sin relación con la visita.
- Todo pasa por `/files/visit-photos/{id}`; `?download=1` descarga. No existe ninguna URL pública al disco privado.
- **No aparecen** en el portal del cliente, el enlace público ni el PDF del remito.

### Secciones, tablas y limpieza

- **Zonas (definidas por el sistema, `App\Support\PhotoZones`):** ya no hay pantalla para crear, renombrar u ordenar secciones. Mantenimiento: Pozo, Sala de máquinas, Techo de cabina y Cabina y puertas. Órdenes de trabajo (reparaciones/reclamos, modernizaciones, instalaciones): Trabajo realizado y las mismas cuatro zonas. Inspección: ninguna (las fotos van en su reporte). Cada empresa tiene una fila por zona en `photo_sections` (`code`), creada sola. Las secciones que ya existían con el nombre de una zona pasan a ser esa zona (migración `2026_11_20_100000`); las propias que no coinciden no se ofrecen más, pero sus fotos se conservan y se ven con su nombre.
- **Tablas:**
  - `photo_sections`;
  - `visit_photos` (visita, orden, remito, sección y su nombre, ascensor, antes/después, autor, fecha, observación).
- **Fotos ya cargadas en esta etapa:** conservan su visita, su remito y su lote. Las del flujo anterior que quedaron «en preparación» (sin visita ni orden) se limpian solas.
- **Limpieza** (`visit-photos:prune`, diaria a las 03:45 con el scheduler de Laravel, sin superposición):
  - borra **solo** fotos sin visita y sin orden de más de 24 h;
  - una foto con visita u orden nunca se toca;
  - vuelve a verificar cada foto bajo bloqueo;
  - si una falla, sigue con las demás y sale con código de error;
  - requiere el cron de `php artisan schedule:run` (ver `docs/operacion-produccion.md`).
- **Sin archivos huérfanos:** quitar una foto, descartar una visita en curso o borrar una visita sin remito borra los archivos.
- **Planes:** disponible en todos los planes, sin cambios de planes ni de precios.

## Edificios: cada ascensor y montacargas con su tracción

- **Formulario del edificio:** sección «Ascensores y montacargas».
  - Se indica cuántos ascensores y montacargas hay; la lista crea un equipo por cada uno.
  - Cada equipo tiene su **tipo** (ascensor / montacargas) y su **tracción** (`elevators.machine_type`: tracción con o sin sala de máquinas, hidráulico u otro).
  - No hace falta migración: el modelo `elevators` ya tenía tipo y tracción por equipo.
- **Las cantidades del edificio** (`elevator_count`, `freight_elevator_count`) se calculan desde los equipos activos. Las columnas `traction_elevator_count` y `hydraulic_elevator_count` del edificio dejan de usarse (no se borran sus datos).
- **Tracción:** obligatoria en equipos nuevos. Los existentes sin dato pueden seguir «Sin especificar» (se marcan en la lista).
- **Quitar un equipo:**
  - pide confirmación;
  - el equipo queda inactivo con su historial, no se borra;
  - bajar la cantidad nunca quita equipos ya registrados (avisa que hay que quitarlos uno por uno).
- **Numeración:** un equipo nuevo toma el número siguiente de su tipo («Ascensor 3»), sin renumerar a los demás (reportes, órdenes y presupuestos los nombran así). El tipo de un equipo existente no cambia.
- **Edificios sin equipos:**
  - no se pueden crear sin al menos un equipo;
  - uno con equipos no puede quedarse sin ninguno;
  - los existentes sin equipos se pueden seguir editando;
  - no son candidatos a visitas: en la Agenda y el mapa figuran «Sin equipos», no suman previstas ni pendientes, no reciben recordatorios y el servidor rechaza su remito periódico y abrir una visita.

## Indicadores de progreso

- **Edificios asignados:** edificios con técnico para ese trabajo. En el total, un edificio con mantenimiento e inspección cuenta una vez.
- **Previstas:** visitas que corresponden al período según la frecuencia real de cada edificio (quincenal = 2 en el mes; trimestral = 1 cada 3 meses; sin equipos = ninguna).
- **Realizadas:** con remito firmado como realizado.
- **Pendientes:** previstas − realizadas (incluye remitos «no realizado», que se informan aparte, y visitas en curso).
- **Ejemplo:** 30 edificios asignados, 25 visitas previstas, 15 realizadas → «15 de 25», 60 %.
- Se cuentan visitas, nunca ascensores.
- El técnico (Visitas e Inicio), la Agenda del admin filtrada por ese técnico y los Indicadores del admin («¿Estamos cumpliendo los mantenimientos de este mes?» y la evolución mensual) usan la misma cuenta de visitas (`MaintenanceAgenda::progress`). Antes los Indicadores contaban edificios por estado.
- **Ejemplos** (un mes, un edificio cada uno):
  - mensual sin remito: 1 prevista, 0 realizadas, 1 pendiente;
  - quincenal con 1 remito: 2 previstas, 1 realizada, 1 pendiente;
  - quincenal con 1 remito y otro «no realizado»: 2 previstas, 1 realizada, 1 pendiente (1 no realizada);
  - trimestral, con su visita hecha este mes: 1 prevista, 1 realizada; en los otros meses de ese período: 0 previstas («Hecho en el período»);
  - trimestral sin visita todavía: 1 prevista y pendiente en cada mes del período, hasta que se haga (una sola visita, nunca 3);
  - con una visita en curso (fotos, sin remito): sigue pendiente;
  - los cuatro primeros juntos: 6 previstas, 3 realizadas, 3 pendientes (1 de ellas no realizada) → «3 de 6», 50 %.
- Cada pantalla explica los números con «¿Qué significa cada número?».

## Firma del remito

- **Validación en el servidor** (`App\Support\DeliveryNotes\SignatureImage`):
  - la firma tiene que ser una imagen PNG o JPEG real, de tamaño razonable y **con trazo**;
  - un lienzo en blanco o algo que no es una imagen se rechaza;
  - se guarda re-codificada como PNG, sin datos agregados;
  - tolera el leve ruido que algunos navegadores (por ejemplo, Brave) agregan al leer el lienzo.
- **Si el remito vuelve por otro error**, la firma vuelve cargada: no hay que firmar de nuevo. Solo vuelve si el servidor la considera una firma válida (imagen real con trazo); una en blanco o rechazada no vuelve como «firmada».
- **Volver atrás** después de enviar (por ejemplo, desde la página de error del navegador sin señal): si el navegador restaura la página desde su caché, el botón «Generar remito» se rehabilita. El token del formulario evita un remito duplicado.
- **El recuadro de firma** se empaqueta como `resources/js/remito-pad.js`, con un nombre neutro. Si no carga (por ejemplo, porque lo bloquea una extensión), el formulario lo avisa en vez de dejar un recuadro que no dibuja.
- **Brave:** no se pudo reproducir el problema. Ver «Pruebas manuales pendientes».

## Antes de desplegar las migraciones en producción

1. Backup de la base (y del disco privado).
2. `php artisan visits:check-duplicates` y revisar los grupos marcados «Revisar».
3. `php artisan migrate --pretend` para ver las sentencias; después `php artisan migrate --force` (cuatro migraciones: `2026_11_02_100000`, `100100`, `100200` y `2026_11_10_100000`; si las tres primeras ya corrieron, solo queda la última). Si alguna se corta, volver a correr `php artisan migrate --force`: completa lo que faltó (las tablas nuevas que hayan quedado vacías e incompletas se recrean).
4. `php artisan visits:check-duplicates` de nuevo: la columna «Con identidad» tiene que mostrar la misma visita que antes.
5. `php artisan schedule:list` debe mostrar `visit-photos:prune` diario a las 03:45.
6. `upload_max_filesize` y `post_max_size` de PHP (y `client_max_body_size` de Nginx) ≥ 10 MB.
7. `npm run build`: el recuadro de firma ahora es `remito-pad.js` (`php artisan ascento:check-production` lo verifica en el manifest).
8. Edificios sin equipos: después de migrar, revisar en la Agenda los que figuran «Sin equipos» y cargarles sus equipos (no reciben visitas hasta entonces).
9. Las fotos quedan apagadas para todas las empresas: cada una las habilita en «Mi empresa».

## Rollback (volver a la versión anterior)

- **No hay pérdida de datos al desplegar.** Las fotos históricas conservan su visita, su remito y su lote; las visitas existentes quedan como realizadas (`in_progress = false`); las empresas que nunca habiliten las fotos no ven ningún cambio salvo los equipos de los edificios.
- **Un rollback de la migración `2026_11_10_100000` sí puede perder datos nuevos:**
  - borra los tres interruptores de fotos de cada empresa (al volver a migrar, quedan apagados);
  - borra `in_progress`: las visitas en curso pasarían a contar como **realizadas** sin remito;
  - borra `visit_photos.work_order_id`: las fotos de órdenes todavía sin remito quedan sin visita ni orden y **la limpieza diaria las borraría** a las 24 h.
- **Un rollback solo del código** (sin revertir la migración) tiene dos de esos efectos: el código anterior no conoce `in_progress` (las visitas en curso cuentan como realizadas) y su limpieza borra toda foto sin visita, incluidas las de órdenes sin remito.
- **Si hiciera falta volver atrás:**
  1. backup de la base y del disco privado;
  2. pausar el scheduler (o al menos `visit-photos:prune`);
  3. en la Agenda, resolver o descartar las visitas «en curso»;
  4. firmar los remitos de las órdenes con fotos pendientes, o anotar sus ids;
  5. recién entonces volver el código y, solo si es imprescindible, `migrate:rollback`.

## Riesgos y decisiones pendientes

- **El técnico elige el mes del remito** (como antes de la etapa), también meses futuros o pasados del año: una visita registrada en un mes futuro cuenta en ese período.
- **Visitas en curso abandonadas:** quedan como pendientes hasta que se firma el remito o se descartan. No se cierran solas.
  - No bloquean: el próximo remito del mismo período (de ese técnico o de otro asignado) completa esa misma visita.
  - Si el período termina sin remito, ese período queda «Vencido» y el siguiente no se ve afectado.
  - La oficina las ve en la Agenda y las descarta desde ahí.
  - **Propuesta, no implementada (requiere tu decisión):** que la Agenda y el Centro de atención muestren aparte las visitas en curso de más de N días (por ejemplo 7) para que la oficina las resuelva. **No se propone un cierre automático:** cerrarla como realizada inventaría un trabajo sin remito ni firma, y descartarla sola borraría fotos.
- **Al completar una visita en curso**, los participantes son los que declara el remito (como siempre); quien la abrió conserva sus fotos y su autoría.

## Pruebas manuales pendientes

1. **Celular real (Android y iPhone):**
   - empezar una visita y cargar fotos con la cámara y la galería;
   - cortar la señal a mitad de una subida y comprobar «Reintentar»;
   - hacer el remito y verificar que completa la misma visita.
2. **Firma en Brave, Firefox y Safari reales**, en celular y escritorio:
   - con Shields en el nivel habitual y con bloqueo de huella digital «estricto/agresivo»;
   - con uBlock Origin si se usa.
   - Si falla, anotar el mensaje que aparece y si la consola muestra un error al cargar `remito-pad`.
3. **Edificio quincenal:** dos remitos en el mes, el tercero se rechaza, y el progreso dice 2 de 2 en Visitas, Inicio y Agenda.
4. **Edificio trimestral:** cambiar a mensual a mitad del período y comprobar que rige desde el período siguiente.
5. **Edificios:**
   - crear uno con ascensores y montacargas de distinta tracción;
   - editar uno existente y quitar un equipo (confirmación; queda inactivo).
6. **Fotos por empresa:** habilitar y deshabilitar cada tipo en «Mi empresa» y verificar que desaparece la carga sin perder lo histórico.

## Reporte de inspección

- En Visitas, cada inspección tiene «Crear reporte» (abre la inspección en curso y va al reporte) o «Ver reporte». También desde la pantalla de la visita.
- El inspector escribe las observaciones y, si la empresa habilitó las fotos de inspecciones en «Mi empresa», agrega fotos («Tomar foto» / «Elegir de la galería», hasta 6). Con el interruptor apagado el reporte se hace igual, solo con observaciones; las fotos ya cargadas se conservan.
- Reutiliza los reportes (`reports.kind = inspection`, `reports.building_visit_id`, uno por visita): mismas fotos privadas y PDF. No es una reparación: sin prioridad ni estado, no consume el cupo mensual de reportes, no aparece en fallas, alertas, indicadores, «Mis reportes» ni en el portal. En el panel, Reportes muestra los de falla por defecto; los de inspección se ven con el filtro «Tipo».
- Lo ven el admin, quien lo hizo y los inspectores de esa inspección; lo edita quien lo hizo o el admin. Una inspección con reporte no se puede descartar.

## Eliminación definitiva de empresas (SuperAdmin)

- Empresas → ficha o lista (también desactivadas) → «Eliminar definitivamente». El modal identifica la empresa (razón social, CUIT, correo, identificador, estado) y cuánto se borra; hay que escribir el nombre exacto y confirmar que no se puede deshacer.
- `App\Services\Companies\CompanyEraser` borra, en una transacción, todas las filas de la empresa (en orden, sin depender de cascadas), sus usuarios (con sesiones, push, avisos y pivotes) y después sus archivos (`visits/`, `reports/`, `elevators/`, `exports/` de esa empresa, foto vieja de reportes y logo si nadie más los usa). Al final verifica que ninguna tabla con `company_id` conserve filas de la empresa; si quedara alguna, se revierte todo.
- Se conservan: otras empresas, planes, webhooks, respaldos del sistema, el SuperAdmin y los usuarios del portal que también tienen acceso a otra empresa (quedan en esa otra).
- Bloqueos: una suscripción de Mercado Pago que todavía puede cobrar (cancelarla primero) o registros de otra empresa hechos por usuarios de esta.
- Queda registrado en el log de la aplicación («Empresa eliminada definitivamente»: empresa, quién, registros y archivos). No hay tabla de auditoría.
- Los respaldos del sistema ya generados siguen conteniendo los datos de la empresa hasta que rotan.

## Plan Empresa (funciones fotográficas)

- Fotos de antes y después de mantenimientos y órdenes, reporte de inspección (con o sin fotos) y la consulta de todo eso en el panel son exclusivas del plan **Empresa** (`PlanFeature::WorkPhotos`, migración `2026_11_20_100100`, que solo suma la clave a ese plan). Inicial y Profesional no las tienen. La prueba gratis de 30 días es el plan Empresa completo (`SubscriptionPlan::TRIAL_PLAN`), sin suscripción ni cobro; al vencer sin plan contratado no hay acceso (como siempre) y, contratado un plan, rigen sus funciones. Las funciones fotográficas además exigen acceso vigente.
- Se verifica en el servidor en cada operación: abrir la visita en curso (`visits/start`), cargar, quitar o anotar fotos, ver el archivo privado (404), reporte de inspección (ver, crear, editar, quitar fotos, PDF: 403/404), listado de Reportes del panel (sin los de inspección) e interruptores de «Mi empresa» (bloqueados). La interfaz además oculta botones y muestra el aviso del plan.
- Inicial y Profesional siguen con visitas, firmas y remitos como siempre (el remito no pasa por la visita en curso).
- Cambiar de plan no borra nada: fotos, reportes, archivos e interruptores quedan guardados y vuelven a verse al pasar a Empresa.

## Eliminación de empresas: otras empresas y procesos concurrentes

- Un usuario del portal con un acceso (aunque esté desactivado) en otra empresa se conserva y queda en esa empresa; su acceso allí sigue como estaba (desactivado = sin portal). Un usuario que no es del portal vinculado a otra empresa bloquea el borrado (no se borra ni se muda).
- Una exportación que se está generando bloquea el borrado. Si una exportación termina después del borrado, su archivo se descarta. Una foto que se estaba procesando no se guarda (su visita ya no existe) y se borra.
- Red de seguridad: `companies:prune-erased-files` (todos los días a las 03:45) borra las carpetas `visits/`, `reports/`, `elevators/`, `exports/` (y `reports/` del disco público) de ids de empresas que ya no existen. Nunca toca empresas existentes o desactivadas.
- Una inspección cuyo reporte está en la papelera se puede descartar: el reporte se conserva en la papelera, desvinculado. Un reporte en la papelera no figura como hecho.

## Fotos en el panel

- Miniaturas cuadradas de 110 a 160 px (la miniatura achicada del servidor), iguales para fotos de antes y después y del reporte de inspección. Al tocarlas se abren en un visor centrado que entra en la pantalla (proporción conservada) con «Abrir original» y «Descargar». Los archivos originales no se modifican.

## Exportación de datos

- La hoja «Reportes» incluye todos los reportes, también los de inspección (columnas «Tipo» y «Visita ID»), y sus fotos van en los adjuntos, con cualquier plan.

## Despliegue y reversión (PR #37, merge `579e4f7`)

Estado verificado (solo lectura) el 12/10/2026: producción corre `579e4f7` (release `80008438`); `2026_11_20_100000_system_photo_zones_and_inspection_reports` en el lote 20 y `2026_11_20_100100_add_work_photos_to_empresa_plan` en el lote 21. No queda ninguna migración pendiente de esta etapa.

### Verificación después del deploy
1. `php artisan migrate:status`: sin pendientes.
2. `php artisan schedule:list | grep prune-erased`: `companies:prune-erased-files` a las 03:45.
3. Una empresa en prueba gratis ve «Tomar foto» (con el interruptor activado) y «Crear reporte»; una en Inicial o Profesional ve el aviso del plan.
4. Una inspección con foto en el panel: miniatura y visor al tocarla.

### Reversión (no ejecutar sin decidirlo)
- `php artisan migrate:rollback --step=1` revierte **solo** `2026_11_20_100100_add_work_photos_to_empresa_plan` (la última del lote 21): quita la clave `work_photos` de los planes. No borra fotos, reportes, archivos ni interruptores.
- No revertir solo la migración: con el código de `579e4f7` dejaría a todas las empresas (Empresa y prueba gratis) sin funciones fotográficas. Primero volver al release anterior en Forge (`80003698`, código `c9fe4c4`) y, si hace falta, después la migración. Ese código no usa la clave, así que funciona con ella o sin ella.
- `2026_11_20_100000` (zonas y reportes de inspección) no se revierte en este caso: su reversión haría que los reportes de inspección pasen a contarse como fallas.
- Antes de revertir: que no haya exportaciones en curso (`company_exports` con estado `requested` o `generating`).
