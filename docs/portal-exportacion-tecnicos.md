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

- **Usuarios:** son `users` con `role = client` y `client_id`. No se duplicó la entidad cliente, y un cliente puede tener varios usuarios. Se crean desde Clientes → editar → "Usuarios del portal" (el admin define la contraseña inicial).
- **Edificios:** autorización explícita por usuario (`client_portal_buildings`). Solo se pueden elegir edificios de ese cliente, y el servidor lo vuelve a filtrar.
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
- **UI:** layout propio, liviano y responsive, con estados vacíos. Sin chat, pagos ni facturación.
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

## 4. Datos de validación

`php artisan db:seed --class=DemoValidationSeeder`:
- **No corre en producción.**
- **No toca nada** si las empresas demo ya existen.
- Crea dos empresas con técnicos, clientes, edificios, ascensores, contratos de cada frecuencia, remitos (hechos, no realizados y pendientes), inspecciones, órdenes con materiales, reportes con fotos, presupuestos, stock, cobranzas, documentos privados y compartidos, y usuarios del portal.
- `tests/Feature/Flows/ValidationScenarioTest.php` lo usa para correr los flujos reales:
  - agenda y firma del técnico;
  - portal de cada usuario;
  - exportación de cada empresa, abriendo el Excel y contando filas;
  - todo con `preventLazyLoading` (sin N+1).

## 5. Migraciones (no destructivas)

| Migración | Qué hace |
|---|---|
| `2026_10_25_100000_create_client_portal` | Agrega `users.client_id` (nullable), la tabla `client_portal_buildings` y `shared_with_client` (default false) + `shared_at` en `reports`, `delivery_notes`, `quotes` y `elevator_documents`. |
| `2026_10_25_100100_create_company_exports_tables` | Agrega las tablas nuevas `company_exports` y `company_export_downloads`. |

No modifican ni borran datos existentes. Todo lo histórico queda privado.

## 6. Deploy (cuando se autorice)

1. Backup de la base de producción y de `storage/app/private`.
2. Merge del PR y deploy normal de Forge (`composer install --no-dev`, que incluye `openspout/openspout`, ya presente como dependencia transitiva y ahora declarada).
3. `php artisan migrate --force`.
4. Verificar que el scheduler de Forge siga activo (`schedule:run` cada minuto). Sin él, las exportaciones quedan en "Solicitada".
5. Verificar que `APP_DEBUG=false` en el `.env` de producción.
6. Prueba manual en producción con la cuenta propia:
   - pedir una exportación, descargarla y abrirla;
   - crear un usuario de portal de prueba para un cliente propio, compartir un remito y verlo.
