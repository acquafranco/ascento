# Etapa 11: diseño técnico del segundo factor (2FA) para el SuperAdmin

**Estado:** implementado en la rama `etapa-11/2fa-superadmin` (ver `docs/etapa-11-2fa.md`, que describe lo que quedó). Diferencias con este diseño: el control es un middleware en cada pedido (también en la lista propia del panel de Filament); los avisos van por correo al SuperAdmin; la auditoría usa el log de la aplicación (el registro de actividad exige una empresa); y existe un interruptor de emergencia `ASCENTO_SUPERADMIN_2FA`. Complementa la propuesta de `docs/etapa-11-ingreso-2fa.md`.

**Alcance:** solo cuentas `is_super_admin`, que es quien puede entrar a cualquier empresa y descargar backups de toda la plataforma. Opcional al principio, y obligatorio para el SuperAdmin cuando el dueño lo decida. Dueños, técnicos, administrativos y portal no cambian.

**Sin costo ni dependencias nuevas:** TOTP (RFC 6238, códigos de 6 dígitos de cualquier app autenticadora) con `pragmarx/google2fa` y QR con `bacon/bacon-qr-code`. Los dos ya están instalados como dependencias de Filament 5. Sin SMS ni servicios externos.

---

## 1. Datos

**Migración que solo agrega columnas nullable a `users`.** Nadie queda afectado al aplicarla.

| Columna | Tipo | Contenido |
|---|---|---|
| `two_factor_secret` | `text`, cast `encrypted` | Secreto TOTP, cifrado con `APP_KEY`. Nunca en logs ni en arrays/JSON (`$hidden`) |
| `two_factor_recovery_codes` | `text`, cast `encrypted:array` | 8 códigos de recuperación, cada uno con `Hash::make` (como `Filament\Auth\MultiFactor\App\AppAuthentication`). Se comparan con `Hash::check` y el usado se borra |
| `two_factor_confirmed_at` | `timestamp` | Recién con esta fecha el 2FA está activo |
| `two_factor_last_timestep` | `bigint` | Último paso de 30 s aceptado: impide reutilizar un código |

**Requisito:** `APP_KEY` no puede cambiar sin un procedimiento. Si cambia, los secretos ya no se descifran y hay que reactivar el 2FA con el procedimiento de la sección 6.

---

## 2. Activación

Pantalla «Seguridad de la cuenta» en el panel, visible solo para el SuperAdmin.

1. Pide la **contraseña actual**.
2. Genera un secreto, lo guarda cifrado **sin** `two_factor_confirmed_at` y muestra el QR y la clave en texto.
3. Pide un código de la app. Si es válido, guarda `two_factor_confirmed_at` y genera los 8 códigos de recuperación, que se muestran **una sola vez**, con la opción de descargarlos.
4. Cierra las demás sesiones de esa cuenta: rota `remember_token` y marca las sesiones abiertas como pendientes del segundo factor (sección 4).
5. Un secreto sin confirmar en 15 minutos se descarta.

**Desactivar o regenerar códigos:** pide la contraseña y un código válido (o uno de recuperación). Queda en el registro de actividad.

---

## 3. Desafío en el ingreso

1. `LoginRequest::authenticate()` valida la contraseña con `Auth::validate()`, sin iniciar sesión, cuando la cuenta tiene 2FA confirmado.
2. Guarda en la sesión: `2fa.pending_user_id`, `2fa.remember` y `2fa.expires_at` (5 minutos). Regenera el id de sesión y redirige a `/login/codigo`.
3. En `/login/codigo`, el código TOTP válido (ventana de ±1 paso y sin repetir el paso) o uno de recuperación hace `Auth::login($user, $remember)`, regenera la sesión y guarda `2fa.passed_at`.
4. Vencido o sin pendiente: vuelve al ingreso.

---

## 4. Cumplimiento en cada pedido (contra rutas alternativas)

No alcanza con el formulario de ingreso. Hoy hay otras formas de tener una sesión:
- la cookie de «Recordarme», que vuelve a iniciar sesión sola;
- `Auth::login` en el registro;
- cualquier inicio de sesión futuro.

Por eso el control principal es un **middleware `EnsureSecondFactor`** en el grupo `web` (después de `auth`), aplicado al panel, a la app y a Livewire:
- Si el usuario autenticado tiene 2FA confirmado y la sesión **no** tiene `2fa.passed_at`:
  - cierra la sesión;
  - deja el desafío pendiente (si vino por «Recordarme», el pendiente se arma a partir de la cookie);
  - redirige a `/login/codigo`. En pedidos JSON o de Livewire responde 401.
- Excepciones: solo el desafío, el cierre de sesión y los recursos estáticos.

**«Recordarme» con 2FA:** la cookie vuelve a pedir el código en cada sesión nueva. Más adelante se puede agregar «confiar en este dispositivo por 30 días» con una cookie firmada propia; no está en la primera versión.

---

## 5. Contra intentos repetidos

- **5 códigos fallidos** en un desafío lo anulan: hay que volver a poner la contraseña.
- **`RateLimiter` por cuenta**: 10 fallos cada 15 minutos. No bloquea la cuenta: solo hace esperar al que está en el desafío, que ya pasó la contraseña.
- **Límite de la ruta:** `throttle:10,1,login.second-factor`.
- **Reutilización:** un código ya aceptado no sirve de nuevo (`two_factor_last_timestep`), y cada código de recuperación se consume.
- **Aviso:** con 5 o más fallos de código, aviso por correo al SuperAdmin («Alguien conoce tu contraseña: cambiala»), como mucho uno por hora.

---

## 6. Recuperación si se pierde el segundo factor

| Situación | Cómo se recupera |
|---|---|
| Perdió el teléfono pero tiene los códigos | Usa un código de recuperación, entra y vuelve a activar el 2FA con el teléfono nuevo |
| Perdió el teléfono y los códigos, y hay **otro** SuperAdmin | Ese SuperAdmin restablece el 2FA desde el panel: pide su contraseña y su código, y queda en el registro de actividad. La cuenta afectada vuelve a activarlo al entrar |
| Perdió todo y es el **único** SuperAdmin | Comando en el servidor con acceso SSH: `php artisan security:reset-2fa email@...`. Pide confirmación, borra el secreto y los códigos, rota `remember_token`, cierra todas las sesiones de esa cuenta y deja registro. No existe recuperación por correo, porque el correo pasaría a ser el punto débil |

**Requisitos de recuperación que hay que cumplir en operación:**
1. Guardar los códigos de recuperación fuera del teléfono (gestor de contraseñas o impresos en un lugar seguro).
2. Mantener el acceso SSH a Forge, que es el último recurso.
3. Recomendado: un segundo SuperAdmin con su propio 2FA.

---

## 7. Interacción con lo que ya existe

- **Cambio de contraseña:** con `AuthenticateSession` (H3) se cierran las demás sesiones; las sesiones nuevas vuelven a pedir el código.
- **Correo pendiente de confirmación (etapa 11):** el 2FA no cambia ese flujo. Con 2FA, cambiar el correo exige además la contraseña actual, como hoy.
- **Recuperación de contraseña:** no inicia sesión. Después de restablecerla, el ingreso pide el código.
- **«Entrar» a una empresa (SuperAdmin):** sigue igual, porque ya pasó el 2FA en esa sesión.

---

## 8. Pruebas

| Escenario | Esperado |
|---|---|
| Contraseña correcta sin código | Sin sesión: ninguna pantalla del panel, de la app ni de Livewire responde |
| Código correcto | Entra; la sesión queda marcada |
| Código repetido dentro de su ventana | Rechazado |
| Código de recuperación | Entra una vez; el mismo código no sirve otra vez |
| 5 fallos | El desafío se anula; hay que volver a poner la contraseña |
| Desafío vencido (más de 5 minutos) | Vuelve al ingreso |
| **Cookie de «Recordarme»** de antes de activar el 2FA | No entra sin el código (`remember_token` rotado) |
| **Cookie de «Recordarme»** posterior | Pide el código en la sesión nueva |
| **Sesión abierta** al activar el 2FA en otro dispositivo | El siguiente pedido pide el código |
| Pedidos directos a `/admin/*`, `/livewire/update`, descargas de backups | Rechazados sin la marca de 2FA |
| Restablecer la contraseña por correo | No inicia sesión; después pide el código |
| Usuarios sin 2FA (dueño, técnico, portal) | Ningún cambio en su ingreso |
| Secreto y códigos en la base | Cifrados; nunca en `toArray()`, logs ni respuestas |
| Comando de recuperación | Borra el 2FA, cierra sesiones y deja registro |
| Desactivar sin contraseña o sin código | Rechazado |

---

## 9. Riesgos

| Riesgo | Mitigación |
|---|---|
| El SuperAdmin queda afuera | Activar exige confirmar un código; hay códigos de recuperación; hay recuperación por consola; recomendación de un segundo SuperAdmin |
| Cambio de `APP_KEY` | Documentado; se resuelve con el comando de recuperación |
| Reloj del servidor desfasado | Ventana de ±1 paso (30 s); NTP en el servidor |
| Saltear el 2FA por otra ruta | Middleware en cada pedido, no solo en el formulario; pruebas de la sección 8 |
| Phishing en tiempo real del código | El TOTP no lo evita del todo; alcanza para el alcance actual. Una llave física (WebAuthn) queda para más adelante |

**Esfuerzo estimado:** mediano.
- Una migración aditiva.
- Código:
  - el desafío y su vista;
  - el middleware;
  - la pantalla de seguridad;
  - el comando de recuperación.
- Unas 15 pruebas.
- Sin servicios ni dependencias nuevas.

**Decisiones pendientes:**
1. ¿Obligatorio para el SuperAdmin desde el principio, u opcional durante un tiempo?
2. ¿Agregar «confiar en este dispositivo» más adelante?
3. ¿Crear un segundo SuperAdmin de respaldo?
