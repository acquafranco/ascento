# Tiempos de atención (aviso, llegada y salida)

> **Actualización:** la llegada y la salida ahora son de la **visita técnica** (un técnico en un edificio, con varios trabajos y remitos). Ver [visitas-tecnicas.md](visitas-tecnicas.md). Los campos de la orden (`arrived_at`, `left_at`) se siguen llenando, copiados de la visita; la salida por orden («Terminé y me retiro») queda solo para órdenes con llegada propia anteriores a las visitas.

Rama `mejora/tiempos-de-atencion`. Migración `2027_01_26_100000_add_attention_times`: solo agrega columnas que pueden quedar vacías.

## Qué había antes (auditoría)

| Dato | Qué guardaba | Problema |
|---|---|---|
| `work_orders.created_at` | Cuándo se **cargó** la orden. | No es la hora del aviso. |
| `work_orders.started_at` | «Tomar trabajo». En las intervenciones del técnico, la hora en que la registró. | Se mostraba como **«Entrada»**, y la planilla del técnico la usaba como entrada al edificio. |
| `work_orders.finished_at` | La firma del remito. | Se mostraba como **«Salida»**, pero firmar no es irse. |
| `client_claims.created_at` | Cuándo llegó el reclamo del portal o del QR. | Es un dato real de recepción, pero no se pasaba a la orden. |
| `client_claims.occurred_at` | Cuándo **pasó** el problema, según quien reclama. | No es el aviso. |
| Ubicación del técnico | Voluntaria, solo la última posición, sin historial (`docs/ubicacion-tecnicos.md`). | No prueba la llegada. Guardarla en cada llegada crearía el historial que esa función promete no tener, así que **no se usa**. |

## Qué se agregó

- **Aviso** (`call_received_at`, más `call_source` y `call_recorded_by`). Nunca se deduce de la hora de carga. Sale de:
  - **el técnico**, al abrir su intervención, con «¿Cuándo te avisaron?» (opcional): por ejemplo, la hora del mensaje de WhatsApp;
  - **la oficina**, con «Aviso recibido» en la orden. Queda quién lo cargó y una línea en la actividad (`work_order.call_time`);
  - **un reclamo del portal o del QR**: al crear la orden desde el reclamo, la hora en que llegó ese reclamo.
  - No se acepta un aviso futuro, de más de 7 días antes de la orden ni posterior a la llegada.
- **Llegada** (`arrived_at`, `arrived_by`). La registra el técnico asignado deslizando «Llegué al edificio»:
  - se guarda la hora del servidor, una sola vez (con bloqueo de fila);
  - no se puede editar;
  - hace falta haber tomado el trabajo, y la orden no puede tener remito;
  - abrir la orden no registra nada.
- **Salida** (`left_at`, `left_by`): «Terminé y me retiro».
  - Solo después de la llegada, una vez, con la hora del servidor.
  - Se puede registrar antes o después de firmar el remito, hasta 24 h después de la firma.
  - **No cierra la orden ni reemplaza la firma.**
  - Después de firmar, el listado de remitos recuerda registrar la salida.
- **Duraciones:**
  - respuesta = llegada − aviso;
  - en el lugar = salida − llegada;
  - total = salida − aviso.
  - Solo se calculan con los dos horarios necesarios y en orden. Si falta uno, «Sin registrar».
- **Dónde se ven:**
  - la orden en la app del técnico;
  - la orden en el panel (sección «Tiempos de atención»);
  - la columna «Respuesta» del listado de órdenes;
  - el historial del ascensor.
  - La tarjeta de la orden ahora dice «Tomado» en lugar de «Entrada».
  - La planilla del técnico usa la llegada y la salida reales cuando están registradas; si no, lo de antes.
- **Informe de cada trabajo:** «Cómo quedó el equipo» (obligatorio, con los mismos estados del QR público, sin cambiar el estado que publica la empresa) y «Qué quedó pendiente» (obligatorio si el trabajo no quedó resuelto). Se ven en el remito, en la orden y en el panel.

## Permisos

- **Llegada y salida:** solo el técnico **asignado** a la orden. Estar de guardia no da acceso a órdenes de otros.
- **Otra empresa:** 404.
- **Hora del aviso:**
  - en el panel, quien puede editar la orden (permisos existentes);
  - en la app, el técnico al abrir su propia intervención.
- **Formularios:** ninguna hora es asignable desde ningún formulario; las escribe el servicio `AttendanceTimes`.

## Verificar a mano

- **En un celular real (iPhone y Android):** deslizar «Llegué al edificio». En las pruebas automáticas se verificó con eventos táctiles de Chrome: se registra solo al llegar al final, y si se suelta antes, vuelve sin registrar.
- **Horario de las órdenes existentes:** su «Entrada» y «Salida» pasan a mostrarse como «Tomado» y al lado no aparece llegada. Es lo correcto, porque esos horarios nunca fueron la llegada.
