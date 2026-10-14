# Segundo factor (2FA) del SuperAdmin

**Estado:** implementado en la rama `etapa-11/2fa-superadmin`. **Obligatorio solo para cuentas SuperAdmin**: dueños, administrativos, técnicos y portal no cambian. Diseño original en `docs/etapa-11-2fa-diseno.md`; este documento describe lo implementado.

---

## 1. Cómo funciona

- **Códigos:** TOTP de 6 dígitos (RFC 6238), compatibles con Google Authenticator, Microsoft Authenticator, 1Password y similares.
  - Usa `pragmarx/google2fa` y su QR, que ya venían con Filament 5: sin dependencias nuevas ni servicios externos.
  - El QR se genera dentro de la página, como SVG.
- **Control en cada pedido:** lo hace el middleware `EnsureSuperAdminTwoFactor`, en el grupo `web` y en la lista propia del panel de Filament. No depende del formulario de ingreso, así que cubre:
  - el ingreso normal;
  - «Recordarme»;
  - sesiones que ya estaban abiertas;
  - Livewire;
  - descargas de backups;
  - tiempo real (`/broadcasting/auth`);
  - cualquier forma futura de iniciar sesión.
- **Sin el 2FA configurado:** solo se puede configurar o salir.
- **Configurado, pero sin verificar en esta sesión:** solo se puede poner el código o salir.
- **Marca de la sesión:** está atada a esta configuración del 2FA. Si se desactiva o se vuelve a configurar, las sesiones viejas dejan de valer aunque el servidor no pueda borrarlas.

### Datos (migración `2027_01_02_100000_add_two_factor_to_users`, solo agrega columnas)

| Columna | Contenido |
|---|---|
| `two_factor_secret` | Secreto, cifrado con `APP_KEY` (cast `encrypted`). Nunca en arrays, JSON ni logs |
| `two_factor_recovery_codes` | 8 códigos con hash (`Hash::make`) dentro de un campo cifrado |
| `two_factor_confirmed_at` | Activo desde (sin esta fecha, el secreto es una configuración sin terminar) |
| `two_factor_last_timestep` | Último paso de 30 s aceptado: un código no sirve dos veces |

### Límites

| Qué | Límite |
|---|---|
| Ventana del código | ±1 paso (30 s): tolera un desfase chico del reloj del teléfono |
| Fallos en un mismo desafío | 5: se cierra la sesión y hay que volver a poner la contraseña |
| Fallos por cuenta | 10 cada 15 minutos: hay que esperar. **Nunca hay un bloqueo permanente** |
| Ruta del código | 10 por minuto. Activar: 10 por minuto. Gestión: 5 por minuto |
| Contraseña en acciones sensibles | 5 intentos cada 10 minutos |
| Aviso por fallos | Un correo como mucho por hora |

### Avisos por correo al SuperAdmin

Nunca llevan secretos ni códigos. Se mandan cuando:
- se activa el 2FA;
- se usa un código de recuperación (indica cuántos quedan);
- se generan códigos nuevos;
- se restablece el 2FA;
- hay códigos incorrectos repetidos.

**Auditoría:** el log de la aplicación registra `Seguridad: 2fa.*` con el id de la cuenta, la IP y, al restablecer, quién lo hizo y por qué vía. Nunca registra secretos ni códigos.

---

## 2. Uso

- **Primera vez:** al entrar con la contraseña, el sistema pide configurarlo.
  1. Contraseña actual.
  2. Escanear el QR (o cargar la clave a mano).
  3. Escribir el código.
  4. Se muestran 8 códigos de recuperación **una sola vez**: guardarlos fuera del teléfono.
  - Al activarlo se cierran las demás sesiones de la cuenta y se invalida «Recordarme».
- **En cada sesión nueva:** contraseña y después el código. «Recordarme» evita la contraseña, pero **no** el código.
- **Menú del panel → «Segundo factor»:** todas estas acciones piden la contraseña y un código vigente.
  - generar códigos nuevos (los viejos dejan de servir);
  - configurarlo con otro teléfono;
  - restablecer el de otro SuperAdmin.

---

## 3. Si se pierde el acceso

| Caso | Qué hacer | ¿Hace falta SSH? |
|---|---|---|
| Perdió el teléfono, tiene los códigos | Entra con un código de recuperación y, en «Segundo factor» → «Configurar con otro teléfono», lo vuelve a configurar | No |
| Perdió el teléfono y los códigos, hay **otro SuperAdmin** | El otro SuperAdmin, en «Segundo factor» → «Restablecer el de otro SuperAdmin» (con su contraseña y su código). La cuenta afectada entra con su contraseña y lo configura de nuevo | No |
| Perdió todo y es el **único** SuperAdmin | `php artisan security:reset-2fa correo@exacto` en el servidor. Pide confirmación (`--force` para scripts), cierra todas sus sesiones y deja registro. Repetirlo no rompe nada | **Sí** |
| El reloj del teléfono atrasa o adelanta unos segundos | Se acepta ±30 s. Si es más, activar la hora automática del teléfono | No |
| Excepción o corte durante la activación | No queda nada activo hasta confirmar un código: vuelve a «Configurar» (la configuración sin terminar vence a los 15 minutos) | No |
| Se cortó la conexión después de poner el código | Si llegó a activarse, entra con el código siguiente o con un código de recuperación. Si los códigos no se vieron, usar el caso de «otro SuperAdmin» o el comando | Según el caso |
| Se activó en una sesión ya abierta | Esa sesión sigue y las demás se cierran: es lo esperado | No |
| Se cambió o perdió `APP_KEY` | El secreto ya no se puede leer: los códigos fallan, pero sin errores del servidor. Restablecer con el comando y volver a configurar. **No regenerar `APP_KEY`**: también invalida sesiones, cookies y otros datos cifrados | **Sí** |
| Se restablece el 2FA de otra cuenta mientras la está usando | Esa sesión deja de valer en el siguiente pedido y vuelve a configurarlo; si el servidor guarda sesiones en la base, se borran en el acto | No |
| Emergencia: el 2FA impide operar | `ASCENTO_SUPERADMIN_2FA=false` en el `.env` y `php artisan config:cache`. Apaga el control sin desplegar código. Volver a `true` apenas se resuelva | Sí (Forge) |

**Requisitos de operación:**
1. Guardar los códigos de recuperación fuera del teléfono.
2. Mantener el acceso SSH a Forge.
3. Recomendado: un segundo SuperAdmin con su propio 2FA.

---

## 4. Despliegue y compatibilidad

- **La migración solo agrega columnas opcionales.** Forge la aplica en el deploy (`migrate --force`). Ningún usuario pierde acceso por aplicarla.
- **Al desplegar, el SuperAdmin** con una sesión abierta va a tener que configurar el 2FA en su próximo pedido, y desde ese momento lo va a necesitar en cada sesión. **Hay que tener a mano el teléfono con la app autenticadora.**
- **Volver atrás el código** con la migración aplicada no rompe nada: el código anterior ignora las columnas, y el SuperAdmin entra solo con la contraseña. No hace falta `migrate:rollback`.
- **El deploy de Forge no es atómico:** hace `git pull` y recién después `migrate` (`docs/operacion-produccion.md`, sección 7). Durante esos segundos el código nuevo corre con el esquema viejo.
  - **Usuarios normales:** no les afecta.
  - **SuperAdmin:** si justo en ese momento intenta configurar el 2FA, la pantalla falla (error 500) hasta que termine la migración. Se reintenta un minuto después.
- **Si la migración fallara:** el SuperAdmin no podría configurar el 2FA y quedaría sin acceso al panel. Los demás usuarios no se ven afectados. Solución inmediata: `ASCENTO_SUPERADMIN_2FA=false` y `php artisan config:cache`, y después revisar la migración. **Por eso conviene desplegar este PR en un momento en que el SuperAdmin pueda reaccionar.**
