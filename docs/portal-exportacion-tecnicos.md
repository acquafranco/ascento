# Técnicos, portal del cliente y exportación de datos

Base: `main` @ 6bc05c8 (merge de `planes-agenda-legajo`). Rama: `portal-exportacion-tecnicos`.

## 1. Técnicos, agenda, mantenimientos e inspecciones

El modelo de trabajo no cambió: **una visita fija por edificio, tipo y mes**, que se crea solo al firmar el remito. Nada se marca hecho por abrir una pantalla.

| Estado (agenda) | Cuándo |
|---|---|
| Hecho | Remito firmado y "realizado" en ese mes. |
| **No realizado** (nuevo) | Remito firmado con "no se pudo realizar". Antes contaba como hecho. |
| Pendiente | Hay técnico asignado y el mes no terminó. |
| Vencido | El mes terminó sin remito. |
| Sin técnico | Hay contrato y nadie asignado. |

- **Reasignación:** la visita guarda quién la hizo (`building_visits.user_id`). El técnico anterior deja de ver el edificio y no puede firmar (403).
- **Doble finalización:** dos técnicos no pueden duplicar la visita del mes. La creación se serializa por empresa y el segundo recibe "Este remito ya fue generado". Una orden tiene un solo remito (409).
- **Conectividad limitada:** antes de enviar un remito o reporte se verifica `navigator.onLine`. Si no hay señal, se avisa y no se envía, y lo escrito queda en el formulario. **No hay cola offline**: la app instalable no cachea páginas.
- **Inspecciones:** son independientes del mantenimiento (otro tipo de asignación y otra visita). Hay un inspector por edificio.
- Tests: `tests/Feature/Flows/TechnicianWorkFlowTest.php`.

## 2. Portal del cliente

- **Usuarios: identidad global con accesos por empresa** (desde la rama `auditoria-comercial`).
  - La persona es un `users` con `role = client`.
  - Cada acceso a una empresa es una fila de `portal_memberships` (persona + empresa + cliente comercial de esa empresa, más los edificios autorizados).
  - El mismo email puede ser cliente de dos empresas de ascensores **sin duplicar la cuenta ni mezclar datos**. Arriba aparece un selector de empresa, y cada empresa solo ve y administra sus propios accesos.
  - Se eligió esta opción (B) frente a una cuenta por empresa (A) porque A obliga a usar emails distintos o rompe la recuperación de contraseña.
  - Un email que ya es admin o técnico no se puede invitar como cliente; el rechazo es genérico y no revela a qué empresa pertenece.
  - Tests: `tests/Feature/Portal/PortalIdentityTest.php`.
- **Plan:** solo Profesional y Empresa (`PlanFeature::ClientPortal`). Ver la sección 4.
- **Alta por invitación** (Clientes → editar → pestaña "Acceso al portal" → "Invitar al portal"):
  1. El admin carga nombre, email y los edificios de ese cliente que la persona puede ver.
  2. Si el email es nuevo, la cuenta se crea con una contraseña aleatoria que nadie conoce. Si ya existe como cliente (de otra empresa), solo se suma el acceso y se le avisa por correo, sin token.
  3. Ascento manda un correo con un enlace `/portal/activar/{token}`. Usa el mecanismo de "olvidé mi contraseña" de Laravel con un broker propio (`portal_invitations`): token aleatorio guardado con hash, de un solo uso, que vence a las 72 h.
  4. La persona elige su contraseña, la cuenta queda "Activa" y los admins reciben un aviso.
  5. Entra por `/portal/ingresar`.
  - Nunca se envía una contraseña. Un token de recuperación de un admin o técnico no sirve como invitación.
  - El estado aparece en la tabla: Invitación sin enviar / enviada / vencida, Activo, Desactivado.
  - "Reenviar invitación" invalida el enlace anterior.
- **Recuperación de contraseña:** la estándar (`/forgot-password`). Sirve para clientes, técnicos y admins. El cliente vuelve al login del portal. El admin también puede "Enviar cambio de contraseña"; nunca la ve.
- **Desactivar** marca el acceso de **esa empresa** (`deactivated_at`) y lo corta de inmediato, incluso con una sesión abierta. La persona conserva sus accesos a otras empresas.
- **Edificios:** autorización explícita por acceso (`client_portal_buildings`). Solo se pueden elegir edificios de ese cliente, y el servidor lo vuelve a filtrar.
- **Qué ve:** solo lo marcado **"En portal"** (`shared_with_client`, privado por defecto) en remitos, reportes con sus fotos, presupuestos y documentos del legajo. Se comparte uno por uno o en lote, y queda `shared_at`. Lo histórico no se comparte.
- **Autorización en backend:**
  - middleware `portal` (rol, cliente y empresa válidos, suscripción activa);
  - `PortalAccess::ensureBuilding` y `ensureShared`;
  - cualquier otra cosa devuelve **404**;
  - las fotos y los documentos se sirven por controlador desde el disco privado.
- **Roles separados:**
  - el usuario del portal no entra al panel ni a las rutas de técnicos (lo redirige al portal o recibe 403);
  - no cuenta como técnico para los límites del plan;
  - no aparece en listas de asignación ni de participantes.
- **UI:** layout propio con la marca de la empresa.
  - Secciones: Inicio, Documentos, Mantenimientos, Inspecciones y Avisos.
  - Mantenimientos e inspecciones van separados.
  - Documentos tiene paginación y filtros en el servidor: tipo, edificio, estado, fechas, búsqueda y orden.
  - Responsive, con estados vacíos. Sin chat, pagos ni facturación.
  - Tests: `PortalDocumentsTest`, `PortalPushTest`.
- Tests: `tests/Feature/Portal/ClientPortalTest.php` y el escenario de validación.

## 3. Exportación de datos de la empresa

- **Dónde:** panel → "Exportar datos" (solo admins de la empresa; el superadmin no la ve). También hay un enlace desde "Mi empresa".
- **Cómo se genera:**
  1. Se pide desde la pantalla, que crea un registro `requested`.
  2. El scheduler (`exports:process`, cada minuto, `withoutOverlapping`) lo pasa a `generating`.
  3. Termina como `completed` o `failed`.
  - Sin Redis ni servicios nuevos. Corre por CLI, sin el límite de tiempo de PHP-FPM.
- **Protecciones:**
  - una exportación pendiente a la vez y un máximo de 5 por día por empresa (bloqueo de fila);
  - una exportación trabada más de 30 minutos se marca como fallida.
- **Contenido del ZIP:**
  - `datos-{empresa}.xlsx`: una hoja por entidad real (ver abajo). Las hojas vacías se omiten.
  - `LEEME.txt`: explica que **no es un backup del servidor**.
  - `adjuntos/reportes/{edificio}/reporte-{id}-foto-{n}.jpg`.
  - `adjuntos/documentos/{edificio}/{equipo}/{id}-{título}.{ext}`.
  - Hoja "Archivos": índice de adjuntos (incluido / no incluido y motivo).
- **Hojas:**
  - Empresa, Clientes, Edificios, Ascensores, Documentos de ascensores;
  - Contratos, Usuarios, Asignaciones, Mantenimientos, Inspecciones;
  - Órdenes de trabajo, Materiales usados, Reportes, Remitos;
  - Presupuestos, Ítems de presupuestos, Stock, Movimientos de stock;
  - Cobranzas, Pagos recibidos, Archivos.
  - Las relaciones se muestran como ID + nombre.
- **Excluido:**
  - contraseñas, hashes, `remember_token`, tokens públicos de remitos y presupuestos;
  - firmas dibujadas, credenciales de WhatsApp, Telegram y Mercado Pago, `.env`;
  - rutas internas y cualquier dato de otra empresa. Todas las consultas filtran explícitamente por `company_id`, también sin usuario autenticado.
- **Fórmulas:** las celdas se escriben tipadas (texto, número, fecha). Un texto como `=HYPERLINK(...)` queda como texto y no se ejecuta al abrir el archivo. El dato original no se modifica.
- **Memoria:**
  - escritura en streaming (OpenSpout) y lectura por bloques (`chunkById`);
  - medición local: 80.073 registros en 14 s, con un aumento de memoria de unos 32 MB;
  - los adjuntos se agregan por ruta, sin cargarlos en memoria.
- **Adjuntos seguros:** solo rutas dentro de la carpeta de la empresa, sin `..`, con `realpath` dentro del disco y sin symlinks. Lo faltante o sospechoso no se incluye y queda anotado como advertencia.
- **Descarga:**
  - ruta `/files/exports/{id}` (`auth`, `subscription`, `throttle:20,1`);
  - solo un admin de la misma empresa; cualquier otro recibe 404;
  - disco privado;
  - cada descarga queda en `company_export_downloads` (usuario y fecha).
- **Vencimiento:** 7 días. `exports:prune` (diario, 03:30) borra el archivo y los temporales viejos, y **conserva el registro** del historial (`file_deleted_at`).
- **Fallas:**
  - el usuario ve un motivo entendible con el email de soporte;
  - el log registra la clase y la ubicación del error, no el mensaje (puede contener datos);
  - se puede reintentar.
- Tests: `tests/Feature/Exports/CompanyExportTest.php` y el escenario de validación.

## 4. Planes (cómo se aplican)

| | Inicial | Profesional | Empresa |
|---|---|---|---|
| Edificios / clientes / técnicos | 20 / 50 / 3 | 70 / 150 / 10 | 300 / 420 / 25 |
| Reportes por mes | 15 | sin límite | sin límite |
| Operación, mapa, agenda, legajo, stock, servicios, cobranzas | ✓ | ✓ | ✓ |
| Exportación de datos | ✓ | ✓ | ✓ |
| Presupuestos, remitos digitales | — | ✓ | ✓ |
| **Portal para clientes** | — | ✓ | ✓ |
| Video en reportes (uno por reporte) | — | ✓ | ✓ |
| Avisos en tiempo real y push | ✓ | ✓ | ✓ |
| Funciones avanzadas (indicadores, alertas) | — | parcial | ✓ |

- **Prueba gratis:** 30 días con Profesional. `companies.trial_ends_at` se fija al registrarse y no se reinicia al contratar (`TrialTimelineTest`).
- **Cambio de criterio respecto de la versión anterior:** antes el portal estaba en todos los planes. Ahora no está en Inicial (migración `2026_10_26_100000`, que solo suma la clave a Profesional y Empresa).
- **Dónde se valida** (no alcanza con ocultar botones):
  - **Portal (pantallas, descargas, avisos):** middleware `portal`. Sin portal en el plan, o con la suscripción vencida, responde 403 "no disponible", aunque la cuenta exista.
  - **Compartir:** `SharesWithClient::shareWithClient(true)` lanza `PlanFeatureUnavailableException`. La columna y las acciones se ocultan. "Dejar de compartir" siempre se puede.
  - **Invitar, reenviar, reactivar o cambiar edificios:** las acciones se ocultan y además se valida en el servidor. Desactivar siempre se puede.
  - **Avisos a clientes:** `Notifier` no los entrega sin el plan.
  - **Exportación:** todos los planes (solo admins).
- **Bajar de plan o vencer la suscripción:** lo ya compartido queda marcado pero inaccesible. Al volver a un plan con portal, vuelve a verse.
- Los usuarios del portal no ocupan cupos de técnicos.

## 5. Notificaciones

**Canales:**
- **Dentro de Ascento:**
  - admins: campanita del panel (Filament);
  - técnicos: `/notificaciones`, con contador en la barra y en el menú del celular;
  - clientes: `/portal/notificaciones`, con contador en el encabezado;
  - **en tiempo real para los tres perfiles** con Laravel Reverb (canal privado por usuario). Sin Reverb, o si se corta la conexión, se consulta cada 60 s (y la campanita del panel cada 30 s). Ver `docs/operacion-produccion.md`;
  - todos: marcar leído al abrir y "Marcar todos como leídos".
- **Correo:** solo para lo que hay que saber fuera de la plataforma (ver la matriz).
- **Push:** admins, técnicos y clientes del portal, si la persona lo activó (el permiso se pide solo al tocar "Activar avisos"). **Telegram:** el existente.

| Evento | Quién lo recibe | Interno | Correo | Push |
|---|---|---|---|---|
| Invitación al portal | la persona invitada | — | ✓ | — |
| Recuperación de contraseña | quien la pide | — | ✓ | — |
| Cuenta del portal activada | admins de la empresa | ✓ | — | — |
| Reporte nuevo de un técnico | admins | ✓ (existente) | — | ✓ |
| Trabajo terminado / **NO realizado** | admins | ✓ (existente, ahora distingue "NO realizado") | — | ✓ |
| Límite de reportes del plan | admins | ✓ (existente) | — | ✓ |
| Exportación lista / fallida / interrumpida | el admin que la pidió | ✓ | ✓ | — |
| Visitas vencidas del mes anterior (días 1 a 5) | admins | ✓ | ✓ | — |
| Orden asignada / modificada | técnicos asignados | ✓ (nuevo: antes solo push) | — | ✓ |
| Quitado de una orden / orden cancelada | ese técnico (sin detalles de la orden) | ✓ | — | ✓ |
| Edificio asignado / quitado | ese técnico | ✓ | — | ✓ |
| Visitas pendientes del mes (desde el día 20) / vencidas (días 1 a 5) | cada técnico, solo sus asignaciones vigentes | ✓ | — | ✓ |
| Remito / reporte / presupuesto / documento **compartido** | usuarios del portal con ese edificio autorizado | ✓ (uno por registro) | ✓ (uno por acción) | ✓ |
| Acceso al portal de otra empresa | la persona (ya tenía cuenta) | — | ✓ | — |
| Presupuesto enviado | el email indicado por el admin | — | ✓ (con PDF y enlace firmado) | — |
| Backup fallido | SuperAdmin | ✓ | ✓ (si `BACKUP_NOTIFY_EMAIL`) | — |

**Criterio para el correo:** solo si hay que actuar fuera de Ascento (activar la cuenta, recuperar el acceso, descargar antes de que venza, algo vencido) o si el destinatario no entra todos los días (el cliente). Lo rutinario del día a día queda dentro de la app y en push.

**Garantías (`App\Services\Notifications\Notifier`):**
- **Destinatarios:** misma empresa, cuenta no desactivada, nunca el SuperAdmin, empresa con acceso vigente. Clientes: plan con portal y el edificio autorizado en ese momento.
- **Sin duplicados:** `dedupe_key`, única por destinatario. Repetir el proceso o correrlo dos veces a la vez no duplica, y volver a compartir no reenvía.
- **Fallas de correo:**
  - el aviso interno queda;
  - `mailed_at` se marca solo si el proveedor aceptó el envío; si no, `mail_failed_at` y un log sin datos personales (clase de la excepción e IDs).
- **Enlaces:** relativos. Al abrirlos, la pantalla destino vuelve a comprobar los permisos. La bandeja solo busca entre los avisos del propio usuario (de otro: 404).
- **Contenido:** sin tokens, contraseñas ni datos de lo que el destinatario no puede ver. A un técnico quitado no se le manda el detalle.
- **Sin colas:** el correo sale después de responder (`dispatch()->afterResponse()`) o dentro del scheduler. No hace falta un worker.
- **No hay avisos automáticos al cliente cuando termina una intervención.** Terminar un trabajo y compartirlo son acciones distintas: el cliente se entera cuando la empresa comparte.

## 6. Producción (Forge)

> Lo agregado en la rama `auditoria-comercial` (Reverb, videos, backups, variables nuevas, deploy y rollback) está en `docs/operacion-produccion.md` y `docs/backups.md`.

`php artisan ascento:check-production` (solo lectura) revisa todo esto y no muestra secretos. Con `--mail-to=vos@dominio` manda un correo de prueba.

- **Correo** (imprescindible para las invitaciones y la recuperación):
  - `MAIL_MAILER` (smtp / ses / postmark / resend; **no** `log`);
  - `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` (tls/ssl), o la API key del proveedor;
  - `MAIL_FROM_ADDRESS` de un dominio verificado (SPF/DKIM), `MAIL_FROM_NAME`;
  - `APP_URL` con https: de ahí salen los enlaces.
- **Scheduler:** `schedule:run` cada minuto. Mueve:
  - `exports:process` (cada minuto; también deja la señal que lee el chequeo);
  - `exports:prune` (03:30);
  - `notifications:visits` (08:00);
  - `billing:generate`, etc.
- **Colas:** no se usan para esto; `QUEUE_CONNECTION` puede quedar como está.
- **Push:** `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`. Sin ellas, el push no sale y el resto funciona.
- **Archivos:** escritura en `storage/app/private` y espacio libre (el chequeo avisa por debajo de 2 GB).
- **`APP_DEBUG=false`** y `APP_ENV=production`.

## 7. Datos de validación

`php artisan db:seed --class=DemoValidationSeeder`:
- **No corre en producción.**
- **No toca nada** si las empresas demo ya existen.
- Crea dos empresas con técnicos, clientes, edificios, ascensores, contratos de cada frecuencia, remitos (hechos, no realizados y pendientes), inspecciones, órdenes con materiales, reportes con fotos, presupuestos, stock, cobranzas, documentos privados y compartidos, y usuarios del portal.
- `tests/Feature/Flows/ValidationScenarioTest.php` lo usa para correr los flujos reales:
  - agenda y firma del técnico;
  - portal de cada usuario;
  - exportación de cada empresa, abriendo el Excel y contando filas;
  - todo con `preventLazyLoading` (sin N+1).

## 8. Migraciones (no destructivas)

| Migración | Qué hace |
|---|---|
| `2026_10_25_100000_create_client_portal` | Agrega `users.client_id` (nullable), la tabla `client_portal_buildings` y `shared_with_client` (default false) + `shared_at` en `reports`, `delivery_notes`, `quotes` y `elevator_documents`. |
| `2026_10_25_100100_create_company_exports_tables` | Agrega las tablas nuevas `company_exports` y `company_export_downloads`. |
| `2026_10_26_100000_add_client_portal_to_plans` | Suma `client_portal` a `feature_keys` de Profesional, Empresa y el plan histórico. No cambia precios ni límites. |
| `2026_10_26_100100_add_portal_invitations_and_notification_tracking` | Agrega `users.portal_invited_at` y `users.portal_activated_at`; en `notifications`: `company_id`, `dedupe_key` (única por destinatario), `mailed_at` y `mail_failed_at`. Todo nullable. |

No modifican ni borran datos existentes. Todo lo histórico queda privado.

## 9. Deploy (cuando se autorice)

1. Backup de la base de producción y de `storage/app/private`.
2. Merge del PR y deploy normal de Forge (`composer install --no-dev`, que incluye `openspout/openspout`, ya presente como dependencia transitiva y ahora declarada).
3. `php artisan migrate --force`.
4. `php artisan ascento:check-production` y corregir lo que marque en el `.env` de Forge (correo, `APP_URL`, `APP_DEBUG`, scheduler). Probar el correo con `--mail-to=`.
6. Prueba manual en producción con la cuenta propia:
   - pedir una exportación, descargarla y abrirla;
   - crear un usuario de portal de prueba para un cliente propio, compartir un remito y verlo.
