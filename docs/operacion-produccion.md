# Operación en producción (Forge · ascento.online)

Guía para desplegar la rama `auditoria-comercial` y operar lo que agrega:
avisos en tiempo real, push en todos los perfiles, videos, presupuestos
enviados por correo y backups. **Nada de esto se aplicó en producción**:
hay que hacerlo cuando se autorice el merge.

`php artisan ascento:check-production` es de solo lectura, no muestra secretos
y revisa casi todo lo que sigue. Usalo antes y después de cada deploy.

## 1. Antes del deploy

1. **Backup de producción.** Hasta que esta versión esté desplegada no existe
   el backup automático, así que hacelo a mano:
   - base: el backup de base de datos de Forge o `mysqldump --single-transaction`;
   - archivos: `storage/app/private` y `storage/app/public`.
2. **Anotá el commit desplegado hoy** (Forge → Deployments). Es el punto de
   vuelta atrás.
3. **Cargá las variables nuevas** en Forge → Environment (ver sección 2). La app
   arranca igual sin ellas: sin Reverb los avisos se actualizan al recargar o
   cada 2 minutos, y sin la contraseña de backup el backup sale sin cifrar
   (el chequeo lo marca).

## 2. Variables de entorno nuevas o a revisar

| Variable | Valor recomendado | Para qué |
|---|---|---|
| `APP_NAME` | `Ascento` | Títulos y remitente (hoy puede decir "Laravel"). |
| `MAIL_FROM_NAME` | `Ascento` | Nombre del remitente. **El resto de `MAIL_*` no se toca.** |
| `SESSION_SECURE_COOKIE` | `true` | La cookie de sesión viaja solo por https. |
| `BROADCAST_CONNECTION` | `reverb` | Activa el tiempo real. |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | los genera Forge al activar Reverb, o `php artisan reverb:install` en local | Credenciales del servidor de WebSockets. El secreto nunca llega al navegador. |
| `REVERB_HOST` | `ascento.online` | Host público al que se conectan el navegador y la app. |
| `REVERB_PORT` / `REVERB_SCHEME` | `443` / `https` | wss por el mismo dominio, a través de Nginx. |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `127.0.0.1` / `8080` | Dónde escucha el proceso (solo local; Nginx hace de proxy). |
| `REVERB_ALLOWED_ORIGINS` | `ascento.online` | Solo ese sitio puede abrir el WebSocket. |
| `BACKUP_ARCHIVE_PASSWORD` | una clave larga | Cifra cada backup (AES-256). **Guardala también fuera del servidor**: sin ella el backup no se abre. |
| `BACKUP_NOTIFY_EMAIL` | correo del responsable | Aviso por correo si un backup falla. Además se avisa al SuperAdmin dentro de la app. |
| `BACKUP_MYSQLDUMP` / `BACKUP_MYSQL` | opcional | Ruta de los binarios si no están en el `PATH`. |
| `MEDIA_VIDEO_MAX_MB` / `MEDIA_VIDEO_MAX_SECONDS` | `50` / `120` | Límites del video de un reporte. |
| `FFMPEG_BINARY` / `FFPROBE_BINARY` | `/usr/bin/ffmpeg` / `/usr/bin/ffprobe` | Opcional: comprimir videos y medir su duración. |

`APP_DEBUG` y las credenciales actuales **no se cambian** en este deploy;
confirmá solamente que `APP_DEBUG=false` (el chequeo lo informa).

## 3. Avisos en tiempo real (Laravel Reverb)

Reverb es parte de Laravel y gratuito: corre como un proceso más en el mismo
servidor. No hace falta Pusher ni ningún servicio pago.

**Cómo funciona**
- Cada aviso se publica en el canal privado `App.Models.User.{id}`. Solo el
  dueño se puede suscribir (`routes/channels.php`), y los usuarios
  desactivados quedan afuera.
- Llega a la campanita de Filament (admins), a `/notificaciones` (técnicos) y
  al portal (clientes) sin recargar la página.
- Se publica sin cola (`ShouldBroadcastNow`), así que no hace falta un worker.
  Si Reverb está caído, el aviso igual se guarda: hay timeouts de 1 a 2 s y un
  log de advertencia.
- Si el navegador pierde la conexión, consulta cada 60 s hasta reconectar y
  al reconectar se pone al día.

**En Forge**
1. Site → Application → **Laravel Reverb** → Enable. Forge crea el daemon
   (`php artisan reverb:start`) y configura el proxy de Nginx. Si lo hacés a
   mano:
   - Daemon (Server → Daemons): `php artisan reverb:start --host=127.0.0.1 --port=8080`, en el directorio del sitio y con el usuario `forge`.
   - Nginx, dentro del `server` de ascento.online:
     ```nginx
     location /app {
         proxy_http_version 1.1;
         proxy_set_header Host $http_host;
         proxy_set_header Scheme $scheme;
         proxy_set_header SERVER_PORT $server_port;
         proxy_set_header REMOTE_ADDR $remote_addr;
         proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
         proxy_set_header Upgrade $http_upgrade;
         proxy_set_header Connection "Upgrade";
         proxy_pass http://127.0.0.1:8080;
     }
     location /apps {
         proxy_pass http://127.0.0.1:8080;
         proxy_set_header Host $http_host;
     }
     ```
   No hace falta abrir puertos en el firewall: todo va por 443.
2. Cargá las variables `REVERB_*` (sección 2).
3. Agregá al script de deploy, después de `migrate`: `php artisan reverb:restart`.
   Así el daemon toma el código nuevo; el supervisor de Forge lo levanta de nuevo.
4. `php artisan ascento:check-production` comprueba que haya un proceso
   escuchando, que la app pueda publicar y que los orígenes estén restringidos.

**Probarlo:** abrí el panel de admin en una pestaña y, desde otra cuenta, cargá
un reporte como técnico. El aviso aparece sin recargar.

## 4. Notificaciones push

- Las claves `VAPID_*` ya existen en producción y **no hay que tocarlas**.
- Ahora el push funciona para admins, técnicos y clientes del portal. El
  permiso del navegador se pide solo cuando la persona toca "Activar avisos".
- Las suscripciones vencidas (el proveedor responde que ya no existen) se borran solas: lo hace la librería `laravel-notification-channels/webpush`.
- Verificado localmente de punta a punta: servidor → FCM → Chrome → service
  worker, notificación mostrada en unos 1 s en el portal. **No se probó en un
  iPhone ni en un Android reales.** En iPhone el push web solo funciona con
  Ascento agregado a la pantalla de inicio (iOS 16.4 o posterior).

## 5. Videos y fotos

- **PHP-FPM:** `upload_max_filesize` y `post_max_size` de al menos `64M`
  (Forge → Server → PHP).
- **Nginx:** `client_max_body_size 64M;`.
- El chequeo lee los límites de la CLI; confirmá también los de FPM.
- **FFmpeg (opcional):** `sudo apt install ffmpeg`.
  - Con FFmpeg, `media:process-videos` (cada minuto, en el scheduler) pasa los
    videos a H.264 720p con faststart.
  - Sin FFmpeg, se guardan tal como se subieron: ya se validó en el servidor el
    formato real (MP4, MOV o WebM leídos del contenido) y el tamaño.
- **Fotos:** se corrige la orientación, se quitan los metadatos (incluida la
  ubicación GPS), se achican a 2000 px y se generan miniaturas. Las fotos
  viejas reciben su miniatura la primera vez que se piden.

## 6. Scheduler

`schedule:run` cada minuto (ya debería estar). Tareas nuevas:

| Tarea | Cuándo |
|---|---|
| `media:process-videos` | cada minuto |
| `backup:process` (backups pedidos desde el panel) | cada minuto |
| `backup:run` (backup automático) | 03:15 |
| `backup:prune` (retención) | 04:30 |

Horario de `America/Argentina/Buenos_Aires`. `php artisan schedule:list` las muestra.

## 7. Script de deploy sugerido

```bash
cd /home/forge/ascento.online
git pull origin $FORGE_SITE_BRANCH
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize
$FORGE_PHP artisan reverb:restart
( flock -w 10 9 || exit 1; echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
```

`npm run build` es necesario porque hay una entrada nueva de Vite
(`resources/js/portal.js`, para el push del portal). Si el build ya se hace de
otra forma, conservá la que funciona.

## 8. Migraciones de esta rama

Todas suman tablas o columnas. Ninguna borra ni modifica datos existentes.
Se probaron up, down y up en MySQL 8 local y con la suite completa contra MySQL.

| Migración | Qué hace | Impacto |
|---|---|---|
| `2026_10_27_100000_create_portal_memberships` | Tabla `portal_memberships` (identidad global del cliente y accesos por empresa). Copia un acceso por cada usuario de portal existente. | Una fila por usuario de portal. Instantánea. |
| `2026_10_27_100100_create_report_videos_and_photo_thumbnails` | Columna `report_photos.thumb_path` y tabla `report_videos`. Suma `report_videos` a las funciones de Profesional y Empresa. | `ALTER` sobre `report_photos` (tabla chica). No cambia precios ni límites. |
| `2026_10_27_100200_add_numbering_sending_and_history_to_quotes` | `quotes.number` (único por empresa, completado en orden de creación), `sent_at`, `sent_to` y la tabla `quote_events`. | Recorre los presupuestos existentes una vez para numerarlos. |
| `2026_10_27_100300_create_backups_tables` | Tablas `backups` y `backup_downloads`. | Nuevas, vacías. |
| `2026_10_27_100400_add_map_zone_to_buildings` | `buildings.map_zone` (nullable). | `ALTER` sobre `buildings`. Nada se completa solo: las zonas se cargan a mano. |

No se midió su duración con el volumen de producción. Los `ALTER` son sobre
tablas chicas (`report_photos`, `buildings`, `quotes`) y la numeración de
presupuestos recorre cada fila una vez. **No se ejecutaron contra producción.**

## 9. Después del deploy (verificación)

1. `php artisan ascento:check-production`: todo en verde salvo lo que sea
   decisión tuya (por ejemplo FFmpeg).
2. `php artisan ascento:check-production --mail-to=tu@correo`: llega el correo
   con el logo y el remitente "Ascento".
3. Panel → **Backups** (SuperAdmin) → "Crear backup ahora". En 1 a 2 minutos
   queda "Completo". Después, en el servidor:
   - `php artisan backup:verify {id}`;
   - `php artisan backup:restore {id} --database=ascento_restore_test` en una base aparte;
   - borrá esa base al terminar.
4. **Tiempo real:** la prueba de la sección 3.
5. **Push:** "Activar avisos" en el panel o en el portal y, en el portal, "Probar".
6. **Portal:** con un usuario de portal de prueba, compartí un remito y
   verificá que llegue el aviso y que se vea en Documentos.
7. **Presupuesto:** enviá uno a tu propio correo y abrí el enlace y el PDF.
   - Los enlaces sin firma enviados antes de esta versión ya no funcionan:
     muestran "Este enlace ya no está disponible".
   - Si un cliente lo pide, reenviáselo desde el presupuesto.
8. Revisá `storage/logs/laravel.log` los primeros minutos.

## 10. Vuelta atrás (rollback)

1. Forge → Deployments → redeploy del commit anotado en el paso 1.2. O bien
   `git checkout <commit>` y el script de deploy **sin** `migrate`.
2. **Las migraciones pueden quedar aplicadas:** el código anterior ignora las
   tablas y columnas nuevas. No hace falta `migrate:rollback`, y es más seguro
   no correrlo porque perdería los backups registrados y el historial de
   presupuestos.
3. Si hiciera falta revertirlas, hacelo en este orden y solo después de un
   backup: `php artisan migrate:rollback --step=5`.
4. Desactivá el daemon de Reverb, o dejalo corriendo: el código anterior no
   lo usa.
5. **Recuperar datos:** ver `docs/backups.md`. Nunca se restaura encima de
   la base en uso sin una decisión explícita.

## 11. Riesgos conocidos

- **Backups en el mismo servidor.** Protegen de errores y de deploys fallidos,
  no de perder el servidor. Bajá una copia periódica desde el panel, o
  configurá una copia externa (S3 o Backblaze, de pago bajo) cuando se
  autorice. Ver `docs/backups.md`.
- **SMTP de producción.** No se tocó ni se probó desde acá. Los correos nuevos
  (presupuestos con PDF, accesos al portal, fallas de backup) usan la misma
  configuración que ya funciona.
- **npm (solo desarrollo):** 7 avisos en dependencias de compilación
  (Tailwind/Vite: `braces`, `micromatch`, `postcss-selector-parser`). No
  llegan al navegador. Corregirlos requiere `npm audit fix --force`, que
  cambia versiones mayores: no se aplicó. composer y las dependencias de
  producción de npm no tienen avisos.
- **Sin CSP estricta.** Filament y Livewire usan scripts en línea. Las demás
  cabeceras (X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy y
  HSTS) sí están.
