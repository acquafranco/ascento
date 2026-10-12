# Visitas, asignaciones y fotos (Etapa 5)

## Frecuencia de visitas

- **Dónde se configura:** Edificios → «Frecuencia de visitas» (por fila) o en la asignación masiva. Mantenimiento e inspección tienen frecuencias independientes.
- **Opciones:** mensual, quincenal (2 visitas en el mes) y cada N meses (de 2 a 12).
- **Tabla `building_visit_plans`:** cada cambio es una fila que rige desde su mes de inicio (`starts_on`).
  - Solo se permite el mes actual o uno futuro, así los meses anteriores conservan la frecuencia que tenían y el historial no se reinterpreta.
  - Un edificio sin filas sigue mensual, como antes de la etapa.
- **No es la frecuencia de cobro:** `maintenance_services.frequency` no interviene. Cambiar la frecuencia de visitas no crea servicios ni cobros y no modifica precios.

### Fuente de verdad: `App\Services\Visits\VisitSchedule`

- **Período del mes:**
  - mensual o quincenal: el mes;
  - cada N meses: bloques de N meses contados desde `starts_on`;
  - si la frecuencia cambia en medio de un bloque, ese período termina antes.
- **Previstas:** 1 por período (2 si es quincenal).
- **Realizadas:** visitas con remito que no esté marcado como «no realizado».
  - Un remito «no realizado» ocupa su lugar pero no cuenta como realizada; la nueva visita se coordina con una orden de trabajo, igual que antes.
  - Realizadas + no realizadas + pendientes = previstas.
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

### Datos existentes

- **Lo que hace la migración:** todas las visitas existentes eran mensuales, así que cada una pasa a ser la visita 1 de su mes.
- **Duplicados del mismo edificio, tipo y mes** (antes solo los impedía el código, no la base):
  - una sola recibe la clave: la que tiene remito, o la más antigua si ninguna tiene;
  - las demás quedan como historial sin clave y no se cuentan dos veces;
  - no se borra ni modifica nada más;
  - la migración registra en el log solo cuántos duplicados encontró.
- **Antes de migrar en producción:** `php artisan visits:check-duplicates` es de solo lectura y lista los grupos duplicados (solo ids).

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

- **Quién y cuándo:** el técnico las carga mientras completa el remito de un mantenimiento o una inspección. Son opcionales.
- **Cómo se suben:** de a una, achicadas en el celular. Si una falla queda con «Reintentar» y el remito no se envía hasta resolverla, para que nada se pierda sin aviso.
- **Si el remito vuelve con errores:** las fotos ya subidas se conservan.
- **Por sección:** el técnico elige el equipo (opcional) y escribe una observación (opcional).
- **Límites:**
  - 3 por sección y estado (antes / después);
  - 30 por visita;
  - 60 MB guardados por visita;
  - 10 MB por archivo;
  - formatos JPG, PNG, WEBP o HEIC.
- **Procesamiento:** el mismo de las fotos de reportes (`App\Services\Media\PhotoProcessor`):
  - se vuelve a codificar a JPEG, así no pasa nada que no sea una imagen;
  - se orienta y se quitan los datos EXIF/GPS;
  - se achica a 2000 px y se genera una miniatura;
  - se guarda en el disco privado `visits/{empresa}/`.
- **Tablas:**
  - `photo_sections`: por empresa; Pozo, Techo y Sala de máquinas se crean la primera vez;
  - `visit_photos`: visita, remito, sección (y el nombre que tenía), ascensor, antes/después, autor, fecha y observación.
- **Quién las ve:**
  - el admin de la empresa;
  - los técnicos que hicieron la visita o participaron;
  - mientras se completa el remito, solo quien la subió.
  - Todo pasa por `/files/visit-photos/{id}`, que verifica empresa, usuario y visita. Cualquier otro caso devuelve 404.
- **Dónde NO aparecen:** el portal del cliente, el enlace público y el PDF del remito. Agregarlas al PDF o al portal es una decisión pendiente.
- **Secciones:** Operaciones → «Secciones de fotos». Se pueden agregar, renombrar, ordenar y desactivar. No se borran: desactivar conserva las fotos históricas.
- **Sin archivos huérfanos:**
  - borrar una foto borra su archivo;
  - una visita que se borra (solo posible sin remito) borra sus fotos;
  - las fotos subidas para un remito que nunca se firmó se borran a las 24 h (`visit-photos:prune`, diario).
- **Planes:** disponible en todos los planes, sin cambios de planes ni de precios.

## Pruebas manuales pendientes

1. **Celular real (Android y iPhone):**
   - cargar fotos con la cámara y desde la galería;
   - cortar la señal a mitad de una subida y comprobar «Reintentar»;
   - firmar el remito.
2. **Edificio quincenal:**
   - dos remitos en el mes;
   - el tercero se rechaza;
   - el progreso dice 2 de 2 en Visitas, Inicio y Agenda.
3. **Edificio trimestral:**
   - hecho en un mes, los otros meses del período muestran «No corresponde visitar este mes»;
   - recordatorio solo en el último mes del período.
4. **Asignación masiva con muchos edificios:** ver el resumen previo, los salteados con motivo y los avisos a los técnicos.
5. **Antes de migrar en producción:** correr `php artisan visits:check-duplicates` y revisar los duplicados.
6. **Límites de subida del servidor:** comprobar en producción que `upload_max_filesize` sea ≥ 10 MB (ver `docs/operacion-produccion.md`).
