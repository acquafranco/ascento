# Etapa 7: QR público, ascensores y pruebas de paracaídas

Alcance: puntos 7.1 a 7.4 del plan maestro. Lo que depende de otra etapa o necesita una definición tuya está al final.

## 1. QR público de un edificio (7.1)

- **Qué es:** un código por edificio. Quien lo escanea (residentes, personal del edificio, visitantes) ve una página de **solo lectura**, sin cuenta.
- **Dirección:** `/qr/{token}`, con un token aleatorio de 48 caracteres.
- **Qué muestra:** sale de `PublicBuildingInfo`, una **lista cerrada** de datos. Cada uno lleva su categoría para que no se lea como una certificación.

| Dato | Categoría | Se publica |
|---|---|---|
| Nombre de la empresa y logo; nombre y dirección del edificio | Información administrativa | Siempre |
| Ascensores activos (identificación y tipo) | Información administrativa | Por defecto (se puede apagar) |
| Estado informado de cada ascensor: en servicio, fuera de servicio, en mantenimiento o reparación, con su fecha | Información administrativa | Solo si se habilita y la empresa cargó un estado |
| Fecha del último mantenimiento con remito **realizado** | Trabajo realizado | Por defecto |
| Fecha de la última inspección con remito **realizado** (inspección periódica u orden de tipo inspección) | Inspección registrada | Por defecto |
| Última prueba de paracaídas registrada: fecha, y si **no fue satisfactoria** | Inspección registrada / Observación pendiente | Solo si se habilita |
| Próxima prueba de paracaídas prevista, **solo cuando ya corresponde avisarla** (dentro de los días de aviso, o vencida y todavía sin registrar) | Observación pendiente | Con el bloque anterior |
| Trabajos pendientes: cantidad por tipo y estado, nunca notas | Observación pendiente | Solo si se habilita |
| Teléfono y correo **de la empresa** | Contacto | Solo si se habilita |
| Fecha de actualización (la más reciente de lo mostrado) | — | Siempre |

- **Nunca se muestran:** datos del cliente o propietario, contactos del edificio, técnicos, residentes, firmas, fotos, documentos, notas internas, observaciones de las pruebas, nombres de inspectores, montos ni contratos.
- **No es una certificación:** el estado es **informativo**, lo decide la empresa y figura así. Ascento no evalúa ni certifica, y la página siempre lo aclara: «No es una evaluación ni una certificación de seguridad y no reemplaza la de un profesional habilitado».
- **Solo lectura:**
  - no hay formularios, enlaces al portal ni al panel;
  - un `POST` responde 405;
  - no se crea ni se pide ninguna sesión de cliente.

## 2. Seguridad del QR (7.2)

- **Token:** aleatorio (`Str::random(48)`) y único. Nunca el id: no se pueden recorrer edificios. Un token de otro largo ni se busca.
- **Gestión** (pantalla «QR públicos», en Gestión, con un acceso también desde la edición del edificio):
  - generar, descargar (SVG para imprimir, o PNG), desactivar y regenerar;
  - elegir qué bloques se publican;
  - cargar el estado informado de cada ascensor;
  - ver la vista previa de lo que verá quien escanee.
- **Desactivar o regenerar** cambia el token. El enlace anterior responde 404 **al instante**: la página busca el token en cada pedido y se sirve con `Cache-Control: no-store`. Volver a generar nunca revive un enlace viejo.
- **Cuándo no muestra nada:** un token inexistente, viejo o desactivado, un edificio desactivado o una empresa sin acceso vigente devuelven la misma página «no disponible» (404), sin pistas.
- **Abuso:**
  - límite de 60 pedidos por minuto por IP, también para tokens inválidos;
  - `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`.
- **Imagen del QR:** se genera en el servidor con `chillerlan/php-qrcode`. **Ya estaba instalada** (Filament la usa para la verificación en dos pasos); solo se declaró en `composer.json`, sin descargar nada nuevo. Codifica solo el enlace público.
- **Permisos:** nueva área de administrativos **«QR y datos públicos»**, prevista en el diseño de la Etapa 6.
  - «Ver»: la pantalla, la vista previa y la descarga.
  - «Gestionar»: todo lo que cambia algo. Lo verifica `PublicQrService` en el servidor, además de ocultar los botones.
  - El dueño, como siempre, puede todo.
  - Otra empresa: 404.
- **SuperAdmin:** sin una empresa elegida, «QR públicos» lista los QR de **todas** las empresas (empresa, edificio, estado y el enlace público si está activo). «Abrir» entra a la empresa de ese QR, igual que «Entrar», y muestra el detalle con la vista previa. Con una empresa elegida, ve lo mismo que su dueño.
- **Registro de actividad:** generar, desactivar, regenerar y los cambios de lo publicado o del estado informado. Nunca se guarda el token.

## 3. Pruebas de paracaídas (7.3)

- **Qué existía:**
  - los documentos del ascensor con tipo «certificado» y vencimiento;
  - las inspecciones periódicas, que son visitas con remito.

  Ninguno registra una prueba de paracaídas con resultado ni periodicidad, así que se creó un módulo específico.
- **Periodicidad:**
  - la del **ascensor**; si no tiene, la de su **cliente** («Periodicidad por cliente»);
  - si tampoco, **ninguna**: el equipo queda «Sin periodicidad definida», sin próxima fecha;
  - Ascento **no asume** una periodicidad universal ni requisitos legales;
  - se acepta entre 1 y 120 meses.
- **Registro de una prueba realizada:**
  - fecha (nunca futura);
  - resultado: satisfactoria o no satisfactoria;
  - quién la realizó (una persona de la empresa, o un nombre);
  - inspector o profesional responsable, con matrícula opcional;
  - observaciones;
  - documentos o fotos.
  - Los archivos van a un disco privado; el tipo se verifica por su contenido; se aceptan PDF o imágenes de hasta 20 MB.
  - Se sirven solo con el área «Inspecciones y reportes» y nunca en el QR.
- **Estados por ascensor** (pantalla «Pruebas de paracaídas», en Operaciones):
  - **Prevista:** la próxima fecha, que es la última prueba **satisfactoria** más la periodicidad. No es un registro.
  - **Realizada:** un registro.
  - **No satisfactoria:** el último registro tuvo ese resultado. Queda así hasta que haya una prueba satisfactoria posterior.
  - **Vencidas, próximas y al día:** según la fecha prevista y los días de aviso (30 por defecto, configurables de 0 a 365).
  - **Sin pruebas registradas:** hay periodicidad pero ninguna prueba satisfactoria.
- **Historial:** todas las pruebas, con filtro por ascensor y resultado. Las pruebas **no se borran**; se corrigen, y los cambios quedan en el registro de actividad.
- **Permisos:**
  - área **«Inspecciones y reportes»**: «ver» para consultar; «gestionar» para registrar, corregir, configurar y adjuntar (verificado en `ParachuteService`);
  - inspector y responsable: solo el dueño, un técnico o un administrativo vigente con acceso a inspecciones, siempre de la misma empresa.

## 4. Recordatorios (7.4)

- **Cuándo:** el comando `parachute:remind` corre en el scheduler existente, todos los días a las 08:15. No agrega workers ni servicios.
- **Qué avisa:** las pruebas **próximas** (dentro de los días de aviso) y **vencidas** de las empresas con acceso vigente.
- **A quién:** los dueños de la empresa, el **inspector asignado** y el **responsable técnico**, solo si todavía pueden recibirlo. Por ejemplo, un responsable que perdió el acceso a inspecciones deja de recibirlos.
- **Sin duplicados:** un aviso por ascensor, motivo, fecha prevista y destinatario. Repetir el comando no duplica. Si cambia la fecha (una prueba nueva u otra periodicidad), corresponde un aviso nuevo.
- **Estado del aviso:** se registra en los avisos internos existentes: a quién, cuándo y si lo leyó. Se marca como leído desde la campanita, como cualquier otro. La pantalla muestra, por ascensor, el último aviso y cuántos lo leyeron.
- **No es una prueba hecha:** el texto aclara que «todavía no hay una prueba registrada para esa fecha». Un aviso nunca cambia el estado: solo un registro lo hace.
- **Por dónde llega:** campanita y push a los dispositivos que cada persona activó (`wantsPush`), con el mismo sistema de avisos internos. Sin correo, sin WhatsApp y sin servicios externos nuevos. Las pruebas automáticas usan un servicio de push simulado.
- **En el QR:** si el edificio publica las pruebas de paracaídas, el mismo día en que corresponde el recordatorio el QR muestra la fecha prevista («Próxima prueba de paracaídas prevista» o «prevista para el …, todavía sin registrar»). Sale de la misma cuenta que el panel y el recordatorio (`ParachuteService::overview`), así que desaparece al registrar la prueba nueva. La periodicidad la elige el dueño o un administrativo con «Inspecciones y reportes: gestionar».
- **Registro de cambios de fechas y responsables:** los cambios de fecha y resultado de las pruebas, y de periodicidad, aviso, inspector y responsable, quedan en el registro de actividad.

## 5. Datos y migración

- **Migración:** `2026_12_10_100000_create_public_qr_and_parachute_tests`. Solo agrega; es reversible; se puede volver a correr.
  - **Tablas nuevas:** `building_public_pages`, `parachute_schedules`, `parachute_tests` y `parachute_test_files`.
  - **Columnas nuevas, todas opcionales:** `elevators.public_status` y `public_status_updated_at`, y `clients.parachute_interval_months`.
- **Sin claves foráneas hacia `elevators`,** igual que `tasks.elevator_id`: los servicios validan que el ascensor sea de la empresa.
- **Borrado definitivo de una empresa** (`CompanyEraser`): incluye las tablas y archivos nuevos.
- **Exportación completa:** hojas «Política de paracaídas» y «Pruebas de paracaídas», con los archivos de las pruebas.

## 6. Lo que queda fuera o necesita definición

1. **«Reparaciones relevantes» en el QR (7.1):** depende del modelo de componentes en reparación de la **Etapa 8.4**, que no existe todavía. No se publica nada de reparaciones; cuando exista la 8.4, se suma como un bloque más.
2. **Planes:** el plan maestro no dice en qué plan van el QR y las pruebas de paracaídas. No se agregó ninguna restricción ni límite nuevo, así que hoy están en **todos los planes**. Si querés limitarlos a un plan, es una decisión comercial pendiente.
3. **«Un cliente pueda generar un código QR» (7.1):** lo interpreté como la empresa cliente de Ascento (el dueño o un administrativo autorizado), como dice el 7.2. Los usuarios del portal de clientes no generan QR. Si querías que el administrador del consorcio lo haga desde el portal, hay que definirlo.
4. **«Otro responsable autorizado» (7.4):** se cubre con el campo **responsable**, que puede ser el dueño, un técnico o un administrativo con acceso a inspecciones. No hay una lista de destinatarios adicionales por empresa.
5. **Registro desde la app del técnico:** las pruebas se registran desde el panel. El plan no pide hacerlo desde la app del técnico.
6. **Evaluación o certificación profesional:** Ascento no la emite, así que el QR nunca muestra una. Lo más cercano es el nombre del inspector de la prueba, que se guarda pero no se publica para no exponer datos de personas.
