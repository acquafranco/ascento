# Operación en producción (Forge · ascento.online)

Guía para desplegar la rama `auditoria-comercial` y operar lo que agrega:
avisos en tiempo real, push en todos los perfiles, videos, presupuestos
enviados por correo y backups. **Nada de esto se aplicó en producción**:
hay que hacerlo cuando se autorice el merge.

`php artisan ascento:check-production` es de solo lectura, no muestra secretos
y revisa casi todo lo que sigue. Usalo antes y después de cada deploy.

| Marca | Qué significa | ¿Hace fallar el comando? |
|---|---|---|
| `✓` | Comprobado y bien | No |
| `✗` crítico | Algo no funciona o se pueden perder datos | Sí (sale con error) |
| `!` advertencia | Conviene corregirlo; Ascento funciona igual | No |
| `?` no verificable | La consola no lo puede ver: dice dónde mirarlo (Forge o el panel) | No |
| `·` informativo | Contexto | No |

La consola corre con la **PHP CLI**, que no es la que atiende la web
(**PHP-FPM**). Por eso los límites de subida se leen de los archivos de PHP-FPM
y de Nginx (si son legibles), y el valor que realmente usa la web se ve en el
panel: Inicio del SuperAdmin → **Subida de archivos (PHP de la web)**.

Si la configuración está cacheada (`php artisan optimize`), el comando y la app
ven lo cacheado: después de cambiar el `.env`, corré `php artisan optimize`
antes de volver a chequear.

## Corregir lo que marca el chequeo

### `APP_NAME` es "Laravel"

El código ya usa "Ascento" por defecto; el valor viene del `.env` de
producción. Ojo: si `SESSION_COOKIE` y `CACHE_PREFIX` **no** están en el
`.env`, se arman con `APP_NAME`:

| Variable | Si no está en el `.env` | Con `APP_NAME=Laravel` | Con `APP_NAME=Ascento` |
|---|---|---|---|
| `SESSION_COOKIE` | `Str::slug(APP_NAME)-session` | `laravel-session` | `ascento-session` |
| `CACHE_PREFIX` | `Str::slug(APP_NAME)-cache-` | `laravel-cache-` | `ascento-cache-` |

Si cambiás solo `APP_NAME`, el navegador sigue mandando la cookie vieja, la app
busca la nueva y **todos (técnicos incluidos) tienen que volver a ingresar una
vez** (quien tildó "Recordarme" vuelve a entrar solo: esa cookie no depende de
`APP_NAME`). La caché también arranca vacía: se pierden los contadores de
intentos de ingreso y las marcas de "aviso ya enviado", con riesgo de algún
aviso repetido.

Para cambiar el nombre sin esos efectos:

1. Forge → sitio → **Environment**: fijate si ya existen `SESSION_COOKIE` o
   `CACHE_PREFIX`. **Si existen, no los toques**: el nombre de la app ya no
   los afecta. Cambiá solo `APP_NAME=Ascento`.
2. Si no existen, cambiá estas tres líneas juntas:

   ```env
   APP_NAME=Ascento
   SESSION_COOKIE=laravel-session
   CACHE_PREFIX=laravel-cache-
   ```

   Comprobado el 11/10/2026: la cookie de sesión que manda
   `https://ascento.online` se llama `laravel-session` (DevTools → Application
   → Cookies). Si ves otro nombre, usá ese.
3. `php artisan optimize` (la configuración está cacheada: sin esto el cambio
   no se aplica). El deploy de Forge lo hace si el script lo incluye.
4. `VITE_APP_NAME` se fija al compilar: el próximo `npm run build` lo toma.

Los nombres de cada empresa no dependen de esto. `MAIL_FROM_NAME="${APP_NAME}"`
pasa a decir "Ascento"; si está fijo como `MAIL_FROM_NAME=Ascento`, no cambia.

### `SESSION_SECURE_COOKIE`

Desde esta versión, si la variable **no está** en el `.env`, la cookie de
sesión es segura cuando `APP_URL` empieza con `https://`. En local con
`APP_URL=http://...` queda como antes (no segura) y se puede ingresar por http.

- Comprobado el 11/10/2026: la cookie de `https://ascento.online` ya sale con
  `secure`. Si el chequeo igual lo marca, es configuración cacheada vieja:
  `php artisan optimize` y volver a correrlo.
- Si en Forge figura `SESSION_SECURE_COOKIE=false`, cambiala a `true` (o
  borrala: con `APP_URL` en https da lo mismo). No cierra sesiones: la cookie
  se vuelve a mandar como segura en la siguiente visita.
- Con la cookie segura **solo se puede ingresar por https**. Si alguna vez
  `APP_URL` dice https y entrás por http (por ejemplo una copia local), no vas
  a poder iniciar sesión: poné `SESSION_SECURE_COOKIE=false` en ese entorno.

### Límite de subida de PHP y Nginx (videos de 50 MB)

Son tres capas independientes; un envío pasa solo si las tres lo aceptan:

| Capa | Ajuste | Si falta |
|---|---|---|
| Nginx | `client_max_body_size` (si no está, 1 MB) | error 413 antes de llegar a PHP |
| PHP-FPM (la web) | `upload_max_filesize` (por archivo) y `post_max_size` (envío entero, ≥ `upload_max_filesize`) | el archivo llega vacío o el formulario sin datos |
| PHP CLI (comandos) | los mismos | no afecta las subidas; es lo que muestra `ini_get` en la terminal |

Hace falta **≥ 60 MB** en Nginx y en PHP-FPM (video de 50 MB + fotos +
margen). Recomendado: 64 MB. `memory_limit` de PHP-FPM: 128M alcanza (los
archivos no se cargan en memoria); 256M da margen para achicar fotos grandes.

1. Forge → servidor → **PHP** → *Max File Upload Size*: `64` → **Update**.
   Según la documentación de Forge, esto ajusta PHP y Nginx, pero **no está
   verificado en este servidor**: comprobá cada capa con el paso 3.
2. Si Nginx sigue en un valor menor: Forge → sitio → **Nginx** (Edit Nginx
   Configuration) → `client_max_body_size 64M;` dentro del bloque `server` →
   guardar (Forge recarga Nginx).
3. Comprobar cada capa por separado:
   - **PHP-FPM (lo que usa la web):** Panel → Inicio del SuperAdmin → **Subida
     de archivos (PHP de la web)** tiene que decir `64 MB / 64 MB`. Es el valor
     real del proceso que atiende la web. Si no cambió, Forge → servidor →
     PHP → reiniciar PHP-FPM.
   - **Nginx:** `sudo grep -R client_max_body_size /etc/nginx/` → ningún valor
     menor a `64M`. Prueba real: subir desde el celular un video de unos 40 MB.
   - **PHP CLI:** `php -i | grep -E "upload_max_filesize|post_max_size"`
     (informativo).
   - `php artisan ascento:check-production` → **Fotos y videos** resume las tres;
     lo que no puede leer lo marca `?` en vez de darlo por bueno.

Mientras PHP de la web acepte menos que `MEDIA_VIDEO_MAX_MB`, el formulario del
técnico ya muestra y valida el límite real (no deja subir un video que va a
fallar al terminar).

### Trabajos pendientes en la cola

**Ninguna función de Ascento necesita un worker de colas**: los avisos y
correos se mandan al terminar cada request o desde el scheduler. Se revisaron
todas las rutas que podrían encolar: no hay clases `ShouldQueue` propias, ni
`Mail::queue`, ni exportaciones/importaciones de Filament, y los eventos de
tiempo real son `ShouldBroadcastNow`.

La única excepción era el aviso de **"Falló un backup"** a la campanita del
SuperAdmin: usaba una notificación de Filament que se encola, y sin worker
quedaba en la tabla `jobs` sin llegar nunca. Esta versión lo manda en el
momento. Los trabajos que hayan quedado de antes aparecen en el chequeo como
`Filament\Notifications\DatabaseNotification`. No hace falta hacer nada; si
querés entregarlos, `php artisan queue:work --stop-when-empty` los procesa y
termina. **No cambies a `QUEUE_CONNECTION=sync` ni borres la tabla.**

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
| `MEDIA_VIDEO_MAX_MB` / `MEDIA_VIDEO_MAX_SECONDS` | `50` / `90` | Límites del video de un reporte. Se validan siempre en el servidor. |
| `ASCENTO_ADMIN_NOTIFICATION_EMAIL` | correo del equipo de Ascento | Aviso por correo de cada empresa nueva. Vacío: solo campanita, push y Telegram de los SuperAdmin. |
| `BACKUP_OFFSITE_DISK` / `BACKUP_OFFSITE_PATH` | vacío / `ascento-backups` | Copia externa automática de cada backup cifrado (p. ej. `s3` con las variables `AWS_*`). Ver docs/backups.md. |
| `ASCENTO_VERIFIED_SENDER_SINCE` | `2026-10-12` (por defecto) | Las cuentas creadas desde esa fecha confirman su correo antes de enviar presupuestos o invitaciones al portal. Las anteriores no se bloquean. Vacío: desactivado. |
| `ASCENTO_WHATSAPP_NUMBER` | `5491173728421` (por defecto, confirmado el 13/10/2026) | WhatsApp Business oficial: lo usan el asistente de consultas y el pie de la web. Un valor inválido deja el contacto por correo. |
| `ASCENTO_WHATSAPP_HOURS` | «todos los días, las 24 horas, incluidos fines de semana y feriados» (por defecto) | Completa «Horario de atención: …» en el asistente. Definida y vacía, no se muestra. |
| `SUPER_ADMIN_EMAIL` | ya no tiene un valor por defecto en el código | Lo exige el seeder del SuperAdmin. |
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

**Leer el resultado de «La app publicó un mensaje de prueba en Reverb»**

El chequeo publica un mensaje vacío en el canal público `ascento-diagnostico`,
que nadie escucha, y muestra adónde (`https://HOST:PUERTO`, sin secretos). Si
falla, dice de qué tipo es el problema y dónde se arregla:

| Mensaje | Qué significa | Dónde mirar |
|---|---|---|
| «no pudo armar el cliente de Reverb (configuración, no red)» | Falta una variable `REVERB_*` o tiene un valor inválido. | El `.env` del sitio; después `php artisan optimize`. |
| «no pudo conectarse a Reverb en …» + `cURL error 6` | No se resuelve `REVERB_HOST`. | DNS del host. |
| … + `cURL error 7` | Nada atiende en ese host y puerto. | Que el daemon esté corriendo; que `REVERB_HOST`/`REVERB_PORT` sean los públicos. |
| … + `cURL error 28` o un error SSL | El puerto no responde o no habla https (por ejemplo, https contra el puerto interno de Reverb). | `REVERB_PORT`/`REVERB_SCHEME`: la app usa los **mismos** valores que el navegador, así que tienen que ser los públicos (con Forge, normalmente 443 y https a través de Nginx). |
| «respondió con error» + HTTP 401 | Reverb rechazó la firma: la clave o el secreto de la app no son los que cargó el proceso de Reverb. | Reiniciar el daemon de Reverb después de cambiar `REVERB_APP_*`. |
| … + HTTP 404 | Reverb no conoce ese `REVERB_APP_ID`, o `REVERB_HOST` apunta a un sitio que no es Reverb. | `REVERB_APP_ID` y `REVERB_HOST`. |
| … + HTTP 502/504 | Nginx no llega al proceso de Reverb. | Daemon caído o puerto distinto entre el daemon y el proxy del sitio. |

Hasta esta versión el chequeo mostraba `TypeError` **aunque todo estuviera
bien**: con ningún canal ocupado, Reverb responde una lista vacía y la librería
de Pusher fallaba al leerla. Ese `TypeError` indicaba que Reverb había
respondido y aceptado la firma.

Cuando falla un aviso real, el log dice `No se pudo emitir el aviso en tiempo
real` con `kind` (config, connection, http o app), `detail` y `target`:
`grep "tiempo real" storage/logs/laravel.log | tail -5`.

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

- **PHP-FPM y Nginx:** al menos `64M` (pasos en «Corregir lo que marca el
  chequeo» → Límite de subida).
- **FFmpeg (recomendado):** `sudo apt install ffmpeg` (instala también `ffprobe`).
  - Con FFmpeg, `media:process-videos` (cada minuto, en el scheduler) pasa los videos a:
    - H.264 720p, calidad constante CRF 26 con techo de 2,5 Mbps;
    - 30 cuadros por segundo como máximo;
    - audio AAC y faststart.
  - Escribe en un temporal y lo renombra; el original se borra.
  - Si FFmpeg no puede leer el video (dañado o códec raro), el video queda "falló" y se borra; el reporte lo explica y se puede volver a subir.
  - Sin FFmpeg, se guardan tal como se subieron, ya validados en el servidor:
    - formato real (MP4, MOV o WebM leídos del contenido);
    - tamaño;
    - duración, leída del encabezado del archivo. Si no se puede leer, se rechaza.
  - Un MOV de iPhone en HEVC puede no reproducirse en Chrome sin la conversión.
  - Verificá que existan `/usr/bin/ffmpeg` y `/usr/bin/ffprobe`, o configurá `FFMPEG_BINARY` y `FFPROBE_BINARY`.
- **Límite real de un envío:** un reporte puede llevar 6 fotos y 1 video. Las
  fotos se achican en el celular antes de subir, pero las HEIC o muy grandes
  que el navegador no pueda achicar van enteras (hasta 10 MB cada una). Con
  64M el caso extremo puede superar el límite: el formulario lo informa como
  "los archivos pesan más de lo que acepta el servidor" y no se pierde nada.
  Subir a `128M` es opcional.
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

`npm run build` es necesario: la rama `mejoras-producto` suma la entrada
`resources/js/signature.js` (la firma de remitos ya no depende de un CDN) y
cambia la app del técnico (Tailwind compilado). La rama anterior sumó la entrada
(`resources/js/portal.js`, para el push del portal). Si el build ya se hace de
otra forma, conservá la que funciona.

**Desde la rama `revision-final` también las páginas públicas** (inicio,
ingreso, registro, portal, términos) usan CSS compilado
(`resources/css/public.css`) y tipografías propias, sin el CDN de Tailwind ni
Google Fonts. Sin `npm run build` esas páginas se ven sin estilos: el deploy
tiene que fallar si el build falla (el `&&` del script ya lo hace).

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

1. `php artisan ascento:check-production`: sin `✗` (críticos). Las `!` y `?`
   se revisan una por una (algunas son decisiones tuyas, como FFmpeg o la
   copia externa).
2. `php artisan ascento:check-production --mail-to=tu@correo`: llega el correo
   con el logo y el remitente "Ascento".
3. Panel → **Backups** (SuperAdmin) → "Crear backup ahora". En 1 a 2 minutos
   queda "Completo". Después, en el servidor:
   - `php artisan backup:verify {id}` (el `{id}` es el número del backup; sin
     número verifica el último completo). Confirma que el archivo está
     **íntegro**, no que se pueda restaurar;
   - para comprobar que es **restaurable**: `php artisan backup:restore {id} --database=ascento_restore_test`
     en una base aparte (se niega a usar la base de la app; crea la base antes
     si tu usuario de MySQL no puede crearla);
   - borrá esa base y la carpeta de archivos al terminar.
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
