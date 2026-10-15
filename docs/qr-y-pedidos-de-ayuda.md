# QR público, pedido de ayuda por atrapamiento y sección «Conecta a todos»

## Hoja imprimible del QR

- **Dónde:** en el panel, «QR públicos» → edificio → «Hoja para imprimir (A4)» o «Versión compacta» (A6). Ruta `GET /qr-codes/{edificio}/hoja/{a4|compacta}`, con las mismas reglas que la descarga: área «QR y datos públicos» y el plan con QR.
- **Contenido:**
  - la empresa que mantiene el ascensor, con su logo si lo tiene; si no, solo el nombre, sin huecos;
  - «Información de este ascensor» y la instrucción de escanear;
  - el código grande (118 mm en A4, 68 mm en la compacta), con su margen libre y nada superpuesto;
  - el edificio y su dirección;
  - si el pedido de ayuda está habilitado al imprimir, el recuadro «¿Quedaste atrapado?»;
  - siempre, la indicación de usar la alarma del ascensor y el 911;
  - al pie, «Funciona con Ascento».
- **Sin recursos remotos:** el código (SVG) y los logos van embebidos (`data:`), así que se imprime igual sin conexión.
- **Verificado:** el código se decodificó desde la hoja A4 y la compacta con el lector de códigos de Chrome y lleva a la URL pública. No se probó un escaneo físico desde el papel.
- **Si cambia la configuración** (por ejemplo, se apaga el pedido de ayuda), conviene reimprimir: la hoja refleja el estado del momento en que se imprimió.

## Página pública del QR (`/qr/{token}`)

- **Contenido:** el mismo de antes (lista cerrada de `PublicBuildingInfo`), con un diseño nuevo:
  - **Encabezado de la empresa:** logo, «Mantenimiento a cargo de…», edificio y dirección, y el color de la empresa solo como borde superior (no afecta el contraste).
  - **Pie de la plataforma**, separado de la empresa: «Tecnología de gestión por Ascento», con el logo local.
- **Logo:** se publica desde el disco público, donde ya estaba (solo PNG, JPG o WebP). No se expone ningún archivo privado. Si la imagen no carga, se oculta.

## Pedido de ayuda por atrapamiento

- **Disponibilidad:** el botón aparece solo si se cumplen todas estas condiciones, que el servidor verifica al mostrarlo y al recibir el pedido:
  - el QR está activo y el edificio también;
  - la empresa tiene acceso vigente;
  - el plan incluye `public_qr` y `emergency_requests` (Profesional y Empresa; no Inicial);
  - el interruptor de la empresa está encendido: «Mi empresa» → «Pedidos de ayuda desde el QR», que viene **apagado** para todas las empresas.
- **Experiencia de la persona:**
  1. Toca «Estoy atrapado en el ascensor».
  2. Ve una confirmación breve. Si el QR publica los equipos, puede elegir el ascensor (opcional, «No sé» por defecto).
  3. Toca «Sí, pedir ayuda ahora».
  - Sin cuenta, sin datos personales, sin CAPTCHA.
  - Funciona sin JavaScript (formulario común). Con JavaScript, el envío es en segundo plano y muestra «Enviando…».
  - Si no hay respuesta en 25 s o falla la red, dice que **no se pudo confirmar** el registro y deja reintentar sin duplicar.
- **Estados que ve la persona** (decididos por el servidor):
  - **registrado:** con código, fecha y hora, y «avisamos por Ascento a…; no podemos confirmar que lo hayan visto»;
  - **sumado a uno ya registrado;**
  - **registrado sin nadie a quien avisar:** recomienda usar la alarma y el 911;
  - **página vencida:** pide reabrirla;
  - **no se pudo registrar desde acá:** respuesta genérica.
  - Siempre recuerda usar la alarma o el intercomunicador del ascensor y, si hay peligro, el 911.

### Defensa contra abuso (sin servicios externos)

1. **Límites por IP** en la ruta: 10 por minuto y 40 por hora (throttle de Laravel en la caché de la base, compartida entre procesos).
2. **Comprobante firmado:** un HMAC con la clave de la app, el QR y la hora. Lo entrega la página, vale 3 horas y regenerar el QR lo invalida. Sin él no se registra nada. Reemplaza al CSRF de sesión (así funciona con cookies bloqueadas), y su hash es la **clave de idempotencia (UNIQUE)**: un doble clic o un reintento devuelven la misma solicitud.
3. **Mismo incidente:** una solicitud abierta del mismo edificio de las últimas 2 h (y del mismo ascensor, si se indicó uno distinto) **suma** el pedido como repetición. Se vuelve a avisar como mucho cada 10 minutos, hasta 6 rondas.
4. **Topes de incidentes nuevos:** 3 por hora por edificio y 20 por hora por empresa. Nunca bloquean una emergencia en curso, que se suma a la abierta.
5. **Concurrencia:** transacción con bloqueo del QR del edificio, más la clave única.
6. **Datos que se ignoran:** empresa, edificio, cliente y destinatarios se resuelven en el servidor desde el token. Un ascensor ajeno o inventado se ignora y el pedido no se pierde. La IP no se guarda: solo un HMAC, para analizar abusos.

### Destinatarios (relaciones reales)

- **Empresa que mantiene el ascensor:**
  - dueño y administradores de esa empresa;
  - administrativos activos con permiso de «Órdenes de trabajo»;
  - técnicos **de guardia en ese momento**, si la empresa usa guardias.
- **Administración del consorcio:** usuarios del portal con membresía activa del cliente del edificio en esa empresa **y** con ese edificio autorizado.
- **Casos sin destinatario:**
  - Si el cliente no tiene usuarios del portal, la administración no recibe el aviso: no se inventa un destinatario. El pedido queda registrado igual.
  - El correo de contacto del cliente no es un usuario y no se usa.
- **Controles del Notifier:** vuelve a verificar empresa, cuenta activa, plan con portal y edificio autorizado.

### Entrega y verificación

Por cada destinatario y ronda se crea una fila en `emergency_deliveries`, visible en el panel, en «Avisos» del pedido:

| Estado | Qué significa |
|---|---|
| Aviso en Ascento | Notificación interna creada: campanita del panel, bandeja del técnico o del portal. |
| Push intentado | Se envía después de responder; un fallo queda en el log, sin datos personales. |
| Correo aceptado por el servidor de correo / fallido / pendiente | Se registra en la notificación (`mailed_at` / `mail_failed_at`). |
| Leído en Ascento | Solo si el destinatario abrió el aviso. |

- **Nunca se afirma que alguien lo vio o lo atendió** salvo esas marcas reales.
- **Reintento:** `emergency:retry-mail`, cada 2 minutos, reintenta los correos fallidos hasta 3 intentos, sin reenviar los que salieron.
- **Si falla el envío, la solicitud no se pierde:** ya está registrada antes de avisar.

### Consulta y cierre

- **Panel:** Operaciones → «Pedidos de ayuda» (área «Órdenes de trabajo»: «ver» consulta, «gestionar» cierra), con un contador de abiertos. Se cierra como atendido, falsa alarma o cerrado, con una nota.
- **Historial:** se conserva aunque se apague el interruptor o cambie el plan.
- **Portal:** «Pedidos de ayuda» lista solo los de los edificios autorizados del usuario, de solo lectura y sin la nota interna.
- **Técnicos:** no ven pedidos; el de guardia recibe el aviso con enlace a su pestaña «Guardias».
- **No hay consulta pública por código.**
- **Órdenes de trabajo:** no se crean automáticamente.

## Landing: «El cuidado del ascensor conecta a todos»

- **Ubicación:** sección nueva `#conecta`, entre «Desde cualquier lugar» y «Beneficios».
- **Actores:** tres tarjetas conectadas (empresa, administración del consorcio y quienes usan el edificio), en fila en pantallas grandes y apiladas con conector vertical en el celular.
- **Planes:** el portal y el pedido de ayuda muestran su plan desde la configuración real.
- **Nota al pie:** el pedido depende del plan y de que la empresa lo active, y no es un servicio de emergencias.
- **Tabla comparativa:** suma «Pedido de ayuda por atrapamiento desde el QR» (Profesional y Empresa). El asistente comercial lo menciona en el tema del QR.

## Limitaciones reales

- **Conexión:** el pedido necesita llegar al servidor. Sin datos móviles ni wifi no se puede registrar; la página lo dice y remite a la alarma del ascensor y al 911.
- **Push:** depende de que cada destinatario lo haya activado en su dispositivo. El correo depende del servidor de correo configurado.
- **Recepción:** Ascento no puede confirmar que alguien vea el aviso; solo registra lo que intentó y lo que se leyó en la app.
- **Abuso distribuido:** con muchas IPs distintas, el abuso solo se frena con los topes por edificio y por empresa. Con el tope alcanzado y sin un incidente abierto, un pedido nuevo no se registra hasta que pase la hora: se informa a la persona y se la remite a la alarma y al 911.
- **IP compartida:** el límite por IP es por conexión; vecinos con la misma IP comparten el cupo (10 por minuto).
- **QR por edificio:** el QR es por edificio, no por ascensor; el ascensor se elige en el pedido (opcional).
