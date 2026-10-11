# Backups y recuperación

## Qué se guarda

Cada backup es un `.zip` en `storage/app/private/backups/`, cifrado con
AES-256 usando `BACKUP_ARCHIVE_PASSWORD`. Contiene tres cosas.

- **`database.sql`**: la base completa.
  - MySQL: `mysqldump --single-transaction --quick --routines --triggers`, una foto consistente sin bloquear la app.
  - El usuario y la contraseña van en un archivo temporal con permisos 0600, nunca en la línea de comandos ni en los logs.
- **`files/`**: los archivos de `storage/app/private` y `storage/app/public` (fotos, videos, documentos, remitos firmados, logos).
  - Excluye las exportaciones, las subidas temporales y los backups anteriores.
- **`manifest.json`**:
  - fecha y motor de base;
  - filas por tabla, cantidad y peso de los archivos y el hash SHA-256 del volcado;
  - la lista de archivos que la base menciona y no están en disco (`missing_referenced_files`).

La base se vuelca primero y los archivos después, para que lo que la base
menciona esté en el backup. Si un archivo se borra entre los dos pasos, queda
anotado en el manifiesto.

## Cuándo se hace

- **Automático:** todos los días a las 03:15 (`backup:run`).
- **Manual:** Panel → Backups → "Crear backup ahora".
  - Solo lo ve el SuperAdmin.
  - Se procesa en segundo plano en menos de un minuto (`backup:process`).
  - No se pueden pedir dos a la vez.
- **Retención** (`backup:prune`, a las 04:30): se conservan los últimos 7
  diarios, 4 semanales y los manuales de los últimos 30 días.
  - Nunca se borra el último completo.
  - El registro queda en la tabla aunque el archivo se borre.
- **Fallas:** quedan registradas sin datos sensibles. Avisan al SuperAdmin
  dentro de la app y, si está configurado, a `BACKUP_NOTIFY_EMAIL`.

## Acceso

- **Descarga:** solo el SuperAdmin, desde el panel (`/files/backups/{id}`).
  - Cada descarga queda registrada con usuario, IP y fecha (`backup_downloads`).
  - Un admin de empresa o un técnico recibe 404.
  - No hay URL pública.
- **Sin la contraseña el `.zip` no se abre.** Guardala en un gestor de
  contraseñas fuera del servidor. Si se pierde, los backups existentes no se
  pueden recuperar.
- **El `.env` no está en el backup** (tiene las claves). Guardá también fuera
  del servidor una copia del `.env` de producción: `APP_KEY`,
  `BACKUP_ARCHIVE_PASSWORD`, las claves VAPID (sin ellas los dispositivos
  tienen que volver a activar las notificaciones push), Reverb, correo y
  Mercado Pago. Los datos de la base no están cifrados con `APP_KEY`, así que
  se restauran igual.

## Si un backup falla

1. `php artisan ascento:check-production` → sección **Backups**: muestra el
   último intento fallido con la causa en palabras (por ejemplo «MySQL rechazó
   el usuario o la contraseña» o «el usuario de la base no tiene el permiso
   PROCESS») y la fecha.
2. Detalle técnico (sin contraseñas): `grep -i backup storage/logs/laravel.log | tail -20`.
   La salida de `mysqldump` se guarda en el log con las contraseñas tapadas.
3. Causas comunes:
   - **mysqldump no instalado** → `sudo apt install mysql-client` (Forge ya lo trae).
   - **Permisos del usuario de MySQL** → el usuario que crea Forge suele tener
     todos los permisos sobre su base. Si el error menciona un permiso, el de
     `DB_USERNAME` necesita `SELECT`, `SHOW VIEW`, `TRIGGER` y, por
     `--routines`, `SHOW_ROUTINE` (MySQL 8). Ya se usa `--no-tablespaces` para
     no pedir `PROCESS`.
   - **Sin espacio** → el chequeo muestra el espacio libre; cada backup ocupa
     lo mismo que las fotos, videos y documentos.
   - **PHP sin cifrado ZIP** (libzip sin AES) → con `BACKUP_ARCHIVE_PASSWORD`
     cargada, el backup falla a propósito en vez de guardarse sin cifrar.
4. Corregida la causa: `php artisan backup:run` (o "Crear backup ahora" en el
   panel) y verificalo con `php artisan backup:verify {id}`.

Los avisos de falla (campanita y `BACKUP_NOTIFY_EMAIL`) se mandan en el
momento; si uno falla, los demás igual salen y el backup fallido queda
registrado en el panel y en el chequeo.

## Comandos

| Comando | Qué hace |
|---|---|
| `php artisan backup:run` | Hace un backup ahora (desde la terminal). |
| `php artisan backup:verify {id}` | Abre el `.zip`, comprueba la contraseña, el hash del volcado y que el manifiesto sea legible. |
| `php artisan backup:restore {id} --database=NOMBRE [--files=CARPETA]` | Restaura en una base y una carpeta **aisladas** y compara las filas tabla por tabla. Se niega a usar la base de la app. |
| `php artisan backup:prune` | Aplica la retención. |

## Prueba de restauración realizada

En local, MySQL 8, con datos demo realistas:
- se hizo un backup real de 140 MB, que pasó `backup:verify`;
- se restauró en una base nueva aparte;
- coincidieron las filas de las 29 tablas del manifiesto;
- se recuperaron los 136 archivos;
- hubo cero relaciones huérfanas.

La suite automática repite el ciclo con SQLite en cada corrida:
- contenido;
- cifrado;
- verificación de alteraciones y de contraseña equivocada;
- restauración comparada;
- negativa a restaurar sobre la base de la app;
- retención;
- permisos;
- fallas.

**No se probó en el servidor de producción.** Hacelo después del deploy
(paso 3 de la sección 9 de `docs/operacion-produccion.md`).

## Recuperación

### A. Recuperar algo puntual (lo más común)

Restaurá en una base aparte y copiá solo lo que falta.

```bash
php artisan backup:restore 42 --database=ascento_recovery --files=/home/forge/recovery-42
```

Después consultá `ascento_recovery` y copiá las filas o los archivos
necesarios. Al terminar, borrá la base y la carpeta.

### B. Servidor perdido o base dañada (recuperación completa)

1. Levantá el servidor y el sitio en Forge con el mismo commit, las mismas
   variables y la **misma `BACKUP_ARCHIVE_PASSWORD`**.
2. Copiá el `.zip` del backup a `storage/app/private/backups/`. Si el servidor
   se perdió, tiene que venir de una copia externa (ver Riesgos).
3. Abrilo:
   `unzip -P "$BACKUP_ARCHIVE_PASSWORD" ascento-backup-XXXX.zip -d /home/forge/restore`.
   O usá `backup:restore` hacia una base nueva si el registro del backup
   existe en la tabla `backups`.
4. **Base:** `mysql ascento < /home/forge/restore/database.sql`, sobre una base
   vacía. **Esto reemplaza datos: hacelo solo con una decisión explícita y la
   app en mantenimiento** (`php artisan down`).
5. **Archivos:**
   - `rsync -a /home/forge/restore/files/private/ storage/app/private/`;
   - `rsync -a /home/forge/restore/files/public/ storage/app/public/`;
   - `php artisan storage:link`.
6. `php artisan migrate --force`, por si el backup es de una versión anterior.
7. `php artisan up` y `php artisan ascento:check-production`.
8. Compará las filas con `manifest.json`.

### C. Una sola empresa

El backup es de toda la plataforma: **no hay restauración por empresa**. Para
devolverle a una empresa sus datos usá la **exportación de datos de la empresa**
(Excel con fotos y documentos, desde su panel). Restaurar una sola empresa
dentro de la base compartida requiere copiar sus filas a mano desde una
restauración aislada (caso A), con cuidado de no pisar IDs.

## Riesgos

- **Mismo servidor.** Los backups protegen de errores humanos, de deploys
  fallidos y de borrados, pero **no de perder el servidor o el disco**.
  Mientras no haya copia externa, descargá un backup al menos una vez por
  semana desde el panel y guardalo fuera.
  - **Copia externa automática (opcional, `BACKUP_OFFSITE_DISK`)**: ver abajo.
- **Espacio en disco.** Cada backup ocupa lo mismo que las fotos, videos y
  documentos. `ascento:check-production` avisa por debajo de 2 GB libres.
- **Contraseña.** Si se cambia `BACKUP_ARCHIVE_PASSWORD`, los backups
  anteriores siguen necesitando la clave anterior. Guardá las dos.

## Copia externa automática

**Opcional.** Sin ella, todos los backups están en el mismo servidor: si se
pierde el servidor o el disco, se pierden también. Alternativas, de menor a
mayor esfuerzo:

- **Gratis, manual:** Panel → Backups → **Descargar** uno por semana y guardarlo
  en tu computadora o en tu Google Drive/Dropbox personal (ya está cifrado).
- **Gratis o casi, automático:** proveedores compatibles con S3 con un tramo
  gratuito (por ejemplo Backblaze B2 o Cloudflare R2 ofrecen algunos GB sin
  costo; revisá las condiciones vigentes, algunos piden tarjeta). Con 7
  diarios + 4 semanales, el espacio es unas 11 veces el tamaño de un backup.
- **DigitalOcean:** los backups semanales del droplet (pago) copian todo el
  servidor, incluidos estos archivos.

Apagada por defecto. Cuando se configura, cada backup **cifrado** se sube al
terminar a un almacenamiento externo y se comprueba que llegó entero (mismo
tamaño). Un backup sin cifrar nunca sale del servidor.

1. Crear un bucket **privado** en un proveedor compatible con S3 (Backblaze B2,
   Cloudflare R2, DigitalOcean Spaces o AWS S3). Tiene costo por GB: revisá el
   precio del proveedor antes. Conviene una clave con permiso solo sobre ese
   bucket (leer, escribir y borrar).
2. En el `.env` de producción (no se muestran nunca en el panel):

   ```env
   BACKUP_OFFSITE_DISK=s3
   BACKUP_OFFSITE_PATH=ascento-backups
   AWS_ACCESS_KEY_ID=...
   AWS_SECRET_ACCESS_KEY=...
   AWS_DEFAULT_REGION=...        # p. ej. us-east-005 (B2) o auto (R2)
   AWS_BUCKET=...
   AWS_ENDPOINT=https://...      # vacío solo para AWS S3
   AWS_USE_PATH_STYLE_ENDPOINT=false
   ```

3. `php artisan optimize`, después `php artisan backup:run` y revisar en el
   panel de Backups que diga «Copia externa: lista». `ascento:check-production`
   también lo controla.

- **Retención:** la misma que los locales (al borrar uno viejo se borra
  también su copia). Si querés guardar más tiempo afuera, configuralo en el
  bucket (reglas de ciclo de vida), no en Ascento.
- **Si falla** (credenciales, red): el backup local queda igual, se avisa una
  vez a los SuperAdmin (y a `BACKUP_NOTIFY_EMAIL`) y se reintenta solo cada 30
  minutos con `backup:process`.
- **Restaurar desde la copia externa:** descargar el `.zip` del bucket, ponerlo
  en `storage/app/backups/` y seguir los pasos de restauración de arriba (pide
  `BACKUP_ARCHIVE_PASSWORD`).
