# Ascento Conecta

QR público como **pasaporte del ascensor**, **«Reportar un problema»** desde el QR y **portal de transparencia** para la administración. Reemplaza al pedido de ayuda por atrapamiento, que se retiró.

## 1. Auditoría del QR y del pedido de ayuda (antes de este cambio)

### Relaciones
- **QR → edificio y empresa:** el QR es **por edificio**, no por ascensor (`building_public_pages`).
  - El enlace usa un token aleatorio de 48 caracteres. El edificio y la empresa salen siempre del token, en el servidor.
  - El ascensor se elegía en el pedido: era opcional y solo podía ser uno publicado de ese edificio.

### Qué hacía el botón «Estoy atrapado en el ascensor»
Estaba en `PublicEmergencyController` y `EmergencyRequestService`.

- **Disponibilidad:** solo con el plan Profesional o Empresa y el interruptor de la empresa (apagado por defecto).
- **Qué hacía al enviarlo:**
  1. Guardaba primero una **solicitud** (`emergency_requests`), dentro de una transacción con bloqueo.
  2. Después **avisaba** con notificación interna (campanita), evento en tiempo real por Reverb, correo y push.
  3. Los destinatarios eran el dueño, los administrativos con «Órdenes de trabajo», los técnicos de guardia en ese momento y el portal de la administración del edificio.
  4. Registraba cada intento en `emergency_deliveries`.
- **No creaba órdenes de trabajo.** No llamaba, no mandaba SMS, no usaba geolocalización.
- **Defensas:**
  - comprobante firmado (HMAC), que también era la clave de idempotencia;
  - límites por IP de 10 por minuto y 40 por hora;
  - incidentes repetidos del mismo edificio sumados al abierto;
  - topes de 3 por hora por edificio y 20 por hora por empresa.
- **Sin conexión:** no llegaba nada. La página decía «No pudimos confirmar…» y remitía a la alarma y al 911. **No había ningún mecanismo sin Internet.**
- **Servidor caído:** lo mismo. El navegador no recibía respuesta y se mostraba el mismo mensaje.
- **Evento WebSocket sin nadie conectado:** el aviso quedaba guardado en la base y aparecía al abrir Ascento. El evento en tiempo real no se confirma ni se reintenta.
- **Confirmación humana:** **no existía**. Lo único verificable era «abierto en Ascento» (`read_at` de la notificación) y el cierre manual de la empresa como atendido o falsa alarma.
- **Comunicación fuera de la aplicación:** correo (aceptado o fallido según el servidor de correo, con reintento cada 2 minutos), push del navegador (si cada persona lo activó) y el teléfono de la empresa, si el QR lo publicaba.
- **Obligación técnica o contractual:** no encontré ninguna en el código ni en los documentos. Era una función **opcional, apagada por defecto**, que se presentaba como «no es un servicio de emergencias».
  - No es parte de los medios de comunicación exigibles de un ascensor: alarma e intercomunicador. Ascento no los opera ni los reemplaza.
  - Sí puede haber **hojas impresas** pegadas en edificios con el recuadro «¿Quedaste atrapado?» (ver la sección 7).

## 2. Qué cambió en el pedido de ayuda

- **Página pública:** ya no muestra el botón.
- **Hoja impresa:** ya no tiene el recuadro de atrapamiento. Ahora dice «¿Notaste un problema?… no es un servicio de emergencias» y mantiene siempre la indicación de usar la alarma, el intercomunicador y el 911.
- **`POST /qr/{token}/ayuda`:** sigue existiendo solo para responder a una página vieja abierta en un teléfono.
  - Devuelve **410** con «Esta opción ya no está disponible… No se registró ningún pedido y no se avisó a nadie», más la alarma y el 911.
  - Nunca registra ni avisa: `EmergencyRequestService::availableFor()` devuelve `false`.
- **Historial:** se conserva intacto.
  - Panel: «Pedidos de ayuda (historial)», que aparece solo si la empresa tiene alguno. Permite cerrar los abiertos.
  - Portal: aparece solo si sus edificios tienen alguno.
  - Siguen el reintento de correos de los ya registrados y la exportación.
- **Interruptor de «Mi empresa»:** se reemplazó por el de los reportes. La columna `emergency_requests_enabled` se conserva sin uso y no se borra ningún dato.

## 3. «Reportar un problema» desde el QR

- **Dónde vive:** se reutiliza el módulo de **reclamos** (`client_claims`) con `origin = qr`. No es un módulo nuevo: la misma bandeja, los mismos estados, historial, fotos privadas y la acción explícita de orden de trabajo.
- **Pasos** (sin cuenta, funcionan sin JavaScript):
  1. Categoría (observaciones, no diagnósticos), ascensor si el QR publica los equipos y descripción de 5 a 1000 caracteres.
  2. **Resumen antes de enviar**, con foto opcional (JPG, PNG o WebP, hasta 5 MB, tipo verificado por el contenido).
  3. **Resultado real**, decidido por el servidor.
- **Mensaje final:** «Tu reporte fue registrado… La empresa responsable podrá revisarlo a través de Ascento. Enviar este formulario no garantiza una respuesta inmediata ni que alguien ya lo haya visto». Nunca dice que va un técnico ni que se va a resolver.
- **Sin conexión:** con JavaScript, el envío es en segundo plano y dice «No pudimos confirmar que tu reporte se haya registrado». Se puede reintentar con el mismo comprobante, sin duplicar.
- **Defensas:**
  - comprobante firmado `QrReportNonce`: vence a las 2 horas, exige al menos 3 segundos de uso y su hash es la clave de idempotencia (UNIQUE);
  - campo trampa;
  - límites por IP: 5 por minuto y 20 por hora en el envío;
  - tope por edificio: 10 por hora;
  - tope por empresa: 60 por hora;
  - el mismo texto del mismo edificio en 10 minutos cuenta como el mismo reporte;
  - la IP se guarda solo como HMAC.
- **Datos del navegador que se ignoran:** empresa, edificio y cliente salen del token. Un ascensor ajeno se rechaza.
- **Disponibilidad:**
  - QR y edificio activos;
  - empresa con acceso;
  - plan con `public_qr` y la función `emergency_requests`, que ahora se llama «Reportes de problemas desde el QR» (misma clave y mismos planes: Profesional y Empresa);
  - `companies.qr_reports_enabled`, **encendido por defecto**.
- **Avisos:**
  - **Primero se guarda** el reporte, en una transacción. Después se avisa.
  - **Empresa:** dueño y administrativos con «Órdenes de trabajo» (`ClientClaimService::companyRecipients`).
  - **Técnicos:** los asignados a ese edificio (`building_user`). Tienen una pantalla propia de solo lectura: `GET /{empresa}/reportes-qr/{id}`.
  - **Canales:** notificación interna, Reverb si está configurado, correo y push. No se usan servicios pagos nuevos.
  - **Registro:** `client_claim_deliveries` guarda el intento, no la lectura. El panel muestra «Aviso registrado (sin abrir)» o «Aviso abierto el…» (lo segundo solo con un `read_at` real).
- **Estados:** Recibido, En revisión, En gestión, Asociado a una orden de trabajo, Resuelto y Cerrado.
  - Cada cambio queda en el historial con usuario y fecha.
  - **Resuelto y Cerrado exigen el motivo.**
  - «Asociado» lo pone la acción «Crear orden de trabajo»: explícita, nunca automática y una sola vez.
  - Aclaración en panel, portal y QR: los estados son seguimiento administrativo, no confirman una reparación ni la seguridad del equipo.
- **Visibilidad:**
  - Por defecto, solo la empresa.
  - «Compartir con la administración» lo muestra en el portal de quienes tienen ese edificio autorizado. **Nunca las fotos ni las notas internas.**
  - «Mostrar su estado en el QR» publica solo la categoría, la fecha y el estado.

## 4. Pasaporte del ascensor (QR)

Bloques nuevos, **apagados** hasta que la empresa los elige en «QR públicos»:

- **Historial de servicios:** fecha, tipo, número de remito y si se realizó. Sin técnicos, firmas, descripciones ni enlace al remito.
- **Este período:** solo si el edificio tiene una **frecuencia de visitas cargada** (`building_visit_plans`). Dice «registrado» o «previsto, todavía sin registrar», según `VisitSchedule`. Nunca da por hecha una visita sin registro.
- **Documentación publicada:** solo documentos con `public_on_qr`, que se publican **uno por uno** desde el legajo del ascensor.
  - La acción exige permisos de Documentos y de QR, y queda registrado quién y cuándo.
  - Se descargan por `GET /qr/{token}/documentos/{id}`, que verifica el QR, el bloque encendido, el mismo edificio y empresa, y el documento publicado. Cualquier otro caso devuelve 404.
- **Incidencias informadas:** solo las publicadas, con categoría, fecha y estado.

La página separa lo **Registrado**, lo **Publicado** y lo **Pendiente**, y aclara que es un historial de documentos, no una certificación, una habilitación ni una garantía de funcionamiento.

## 5. Portal de transparencia

- **Ficha del edificio:**
  - «Este período»: lo previsto según la frecuencia; sin frecuencia, lo dice.
  - «Reclamos e incidencias»: los propios y los reportes del QR compartidos.
  - Botón «Resumen del mes».
- **Resumen mensual:** `GET /portal/edificios/{id}/resumen?mes=AAAA-MM`. Se calcula **al pedirlo** y no se guarda. Incluye:
  - visitas registradas (y las no realizadas);
  - documentos compartidos en el mes por tipo;
  - reclamos e incidencias por estado;
  - órdenes de trabajo vinculadas;
  - presupuestos compartidos por estado;
  - visitas previstas.
  Si falta algo, lo dice; el mes en curso se marca como parcial. No es un certificado.
- **Reclamos e incidencias:** la sección reúne los propios y los reportes del QR compartidos de sus edificios.

## 6. Textos y avisos legales

- **Términos:** se reescribió la sección 07 («QR público y reporte de problemas»). Se ajustaron:
  - el resumen inicial;
  - las obligaciones de la empresa (sección 03);
  - el portal (08);
  - los pagos (10);
  - la responsabilidad (15).
  - **Requiere validación jurídica** (sección 8).
- **Privacidad:** quién reporta, qué se guarda (texto, foto, HMAC de la conexión, sin IP), quién ve la foto y qué muestra el QR.
- **Cookies y eliminación de datos:** menciones actualizadas.
- **Ayuda y comunicación:** ayuda del panel y del portal, preguntas frecuentes, landing (sección Conecta y diagrama), asistente comercial, catálogo de funciones y comparativa de planes.

## 7. Limitaciones que siguen

- **Conectividad:** sin datos móviles ni wifi no se puede reportar. No hay modo sin conexión, SMS ni llamadas.
- **Avisos:** un evento WebSocket emitido no prueba que alguien lo recibió. El push depende de que cada persona lo active; el correo depende del servidor de correo. Ascento solo sabe «abierto en Ascento».
- **Disponibilidad:** si el servidor no responde, no se registra nada. La página no confirma.
- **QR por edificio:** el ascensor se elige en el reporte, si el QR publica los equipos.
- **Abuso distribuido:** con muchas IPs, solo frenan los topes por edificio y por empresa. Al alcanzarlos, un reporte legítimo nuevo no se registra hasta que pase la hora, y se le informa a la persona.
- **Hojas impresas viejas:** las que tienen «¿Quedaste atrapado?» siguen pegadas hasta que la empresa las reemplace. Al escanearlas, la página ya no ofrece esa opción. **Conviene avisar a las empresas que la tenían activada para que reimpriman.** Consultar `companies.emergency_requests_enabled = 1` antes del despliegue.

## 8. Requiere validación jurídica profesional

- **Términos, sección 07:** límites del reporte y de los avisos. Cómo se describe la responsabilidad de la empresa cliente («según sus procedimientos y las obligaciones que le correspondan»).
- **Responsabilidad (sección 15):** la mención a reportes y pedidos de ayuda.
- **Privacidad:** tratamiento de fotos y textos de personas sin cuenta, plazo de conservación (hoy no tiene uno propio: sigue el de los datos de la empresa) y base legal.
- **Recomendación del 911:** si corresponde ajustarla por jurisdicción.
- **Retiro de la función:** si hace falta un aviso formal a las empresas que la tenían activada.

## 9. Despliegue

1. `php artisan migrate`: corre `2027_01_13_100000_ascento_conecta`.
   - Solo agrega columnas y la tabla `client_claim_deliveries`, con valores por defecto.
   - No cambia datos.
   - `down()` la revierte.
2. Sin variables de entorno nuevas. El tiempo real sigue usando la configuración de Reverb que ya existe; sin Reverb, los avisos quedan guardados igual.
3. Sin tareas programadas nuevas. `emergency:retry-mail` queda para los pedidos registrados antes del retiro.
4. Disco: las fotos van al disco `local` (privado), en `client-claims/{empresa}/{reclamo}/`, como las de los reclamos.
5. Después del despliegue, avisar a las empresas que tenían `emergency_requests_enabled = 1` que reimpriman las hojas del QR.
