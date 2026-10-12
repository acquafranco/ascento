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

- **Se gestionan desde Visitas, no desde el remito.** El remito es el documento que se firma al terminar y ya no tiene carga de fotos (ni la bloquea).
  - En Visitas, «Empezar visita y cargar fotos» abre la **visita en curso** del período (`building_visits.in_progress`). Abrirla de nuevo, con un doble toque, un reintento u otro técnico, devuelve la misma.
  - La visita en curso ocupa su lugar (identidad única) pero **no cuenta como realizada** ni aparece en el historial, el portal, las planillas ni los indicadores.
  - El remito de ese período **completa esa misma visita**: no se crea otra. Sin visita en curso, el remito crea la visita como siempre.
  - Las fotos se pueden cargar antes, durante o **después** del remito, desde la pantalla de la visita.
  - Una visita en curso sin remito se puede descartar con sus fotos.
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
- **Quitar una foto:** solo quien la subió; se borran también sus archivos.

### Habilitación por tipo de trabajo

- **Dónde:** «Mi empresa» → «Fotos de los trabajos», con tres interruptores (mantenimientos, inspecciones, órdenes de trabajo). Vienen **apagados** para todas las empresas, también las existentes.
- **Es por empresa**, no global. Sin restricciones por plan.
- **Apagado:**
  - el técnico no ve controles de carga para ese tipo;
  - el servidor rechaza cargas nuevas aunque se fuerce la petición;
  - las fotos ya cargadas no se borran y las siguen viendo los mismos usuarios;
  - no cambia ninguna visita, remito ni orden.
- **Una visita tiene un solo tipo de trabajo:** rige el interruptor de ese tipo. Los permisos de un tipo no dan acceso a fotos de otro (un inspector del edificio no ve las fotos del mantenimiento).

### Quién las ve (siempre verificado en el servidor)

- **El admin de la empresa:** todas, cargue quien las cargue. En el panel, «Operaciones → Fotos de trabajos» las lista con filtros, y se pueden abrir y descargar.
- **Quien la subió.**
- **Quien trabaja en esa visita u orden:**
  - técnico asignado al edificio para ese tipo de trabajo;
  - técnico asignado a la orden;
  - quien hizo la visita o participó.
- **Nunca:** clientes, otras empresas, técnicos sin relación con la visita.
- Todo pasa por `/files/visit-photos/{id}`; `?download=1` descarga. No existe ninguna URL pública al disco privado.
- **No aparecen** en el portal del cliente, el enlace público ni el PDF del remito.

### Secciones, tablas y limpieza

- **Secciones:** Operaciones → «Secciones de fotos». Se pueden agregar, renombrar, ordenar y desactivar. Desactivar conserva las fotos históricas.
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
- El técnico (Visitas e Inicio) y la Agenda del admin filtrada por ese técnico muestran los mismos números.
- Cada pantalla explica los números con «¿Qué significa cada número?».

## Firma del remito

- **Validación en el servidor** (`App\Support\DeliveryNotes\SignatureImage`):
  - la firma tiene que ser una imagen PNG o JPEG real, de tamaño razonable y **con trazo**;
  - un lienzo en blanco o algo que no es una imagen se rechaza;
  - se guarda re-codificada como PNG, sin datos agregados;
  - tolera el leve ruido que algunos navegadores (por ejemplo, Brave) agregan al leer el lienzo.
- **Si el remito vuelve por otro error**, la firma vuelve cargada: no hay que firmar de nuevo. Solo se reimprime si es una imagen válida.
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

## Riesgos y decisiones pendientes

- **El técnico elige el mes del remito** (como antes de la etapa), también meses futuros o pasados del año: una visita registrada en un mes futuro cuenta en ese período.
- **Visitas en curso abandonadas:** quedan como pendientes hasta que se firma el remito o se descartan. No se cierran solas.

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
