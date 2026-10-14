# Etapa 11: segundo factor y protección del ingreso (propuesta, R5 y R6)

**Estado:** propuesta para aprobar. No se implementó nada, no cambia el ingreso y no se le exige 2FA a nadie.

---

## R5. Segundo factor (2FA) para el SuperAdmin

### Qué ya tiene el proyecto (sin dependencias nuevas ni costo)

| Componente | Estado | Para qué sirve |
|---|---|---|
| `pragmarx/google2fa` y `google2fa-qrcode` | Instalados (dependencias de Filament 5) | Códigos de 6 dígitos (TOTP, RFC 6238) compatibles con Google Authenticator, Microsoft Authenticator, 1Password, etc. Gratis, sin SMS ni servicios externos |
| `bacon/bacon-qr-code` | Instalado | Dibujar el QR para vincular la app |
| `Filament\Auth\MultiFactor\App\AppAuthentication` | Incluido en Filament 5 | Alta con QR, verificación del código y **códigos de recuperación**; guarda el secreto **cifrado** |
| Ingreso | Breeze (`/login`, `LoginRequest`) | El panel no tiene ingreso propio, así que el desafío de 2FA de Filament (que vive en su página de login) **no se activaría solo** |

### Propuesta

1. **Datos (migración que solo agrega columnas, sin tocar datos):** en `users`, `app_authentication_secret` (texto, cast `encrypted`) y `app_authentication_recovery_codes` (texto, cast `encrypted:array`, los códigos guardados con hash). Nullable: nadie queda afectado.
2. **Alta (solo el SuperAdmin, opcional):** una pantalla «Seguridad de la cuenta» en el panel con las acciones de Filament (`AppAuthentication`):
   - muestra el QR;
   - pide un código para confirmar;
   - entrega **8 códigos de recuperación de un solo uso**, una sola vez, para guardar fuera del sistema.
   - Para desactivarlo se pide la contraseña y un código.
3. **Desafío en el ingreso:** en `LoginRequest`, si la contraseña es correcta y la cuenta tiene 2FA:
   - no se inicia la sesión;
   - se guarda en la sesión «pendiente: usuario X» (con vencimiento de 5 minutos) y se pide el código en `/login/codigo`;
   - código correcto → `Auth::login()` y `session()->regenerate()`, igual que hoy.
4. **Contra intentos repetidos:**
   - 5 códigos fallidos por desafío anulan el desafío y hay que volver a poner la contraseña;
   - límite por cuenta e IP (`RateLimiter`, mismo esquema que H2);
   - un código ya usado no sirve dos veces dentro de su ventana de 30 s.
5. **Recuperación de acceso:**
   - con un código de recuperación (se consume);
   - si se pierden el teléfono y los códigos, **no hay recuperación por correo** para el SuperAdmin, porque si no el correo pasa a ser el punto débil. Se resuelve con un comando de consola en el servidor (`php artisan security:reset-2fa email`), que requiere acceso SSH y queda en el registro de actividad.
6. **Compatibilidad:**
   - dueños, técnicos, administrativos y portal no cambian;
   - «Recordarme» sigue igual;
   - después de restablecer la contraseña por correo también se pide el código, para que el correo no saltee el 2FA.
7. **Más adelante (opcional):** ofrecerlo a los dueños de empresa, sin obligar.

**Esfuerzo estimado:** chico o mediano. Una migración solo aditiva, más:
- en `app/Http/Requests/Auth/`, `LoginRequest` y una clase nueva para el desafío;
- una vista de código;
- una página del panel;
- un comando;
- pruebas: alta, desafío, recuperación, límites, sesión y que nadie más vea cambios.

**Riesgo a cuidar:** que el SuperAdmin quede afuera. Por eso el alta exige confirmar un código antes de activarse, se entregan los códigos de recuperación y existe la recuperación por consola.

---

## R6. Ataques a una misma cuenta desde muchas IP

**Hoy:**
- 5 fallos por correo e IP;
- 30 fallos por IP cada 10 minutos (H2).

Una botnet con muchas IP puede probar contra una sola cuenta. Bloquear la cuenta tras N fallos sería peor: **cualquiera podría dejar afuera a un usuario** escribiendo su correo con contraseñas falsas.

**Propuesta gradual (sin bloquear cuentas):**

| Fallos de la cuenta, desde cualquier IP | Qué pasa | Quién se ve afectado |
|---|---|---|
| 1 a 9 en 15 min | Nada nuevo (siguen H2 y el límite por correo e IP) | — |
| 10 o más en 15 min | Para esa cuenta se exige además el **desafío humano** que ya usa el registro (`RegistrationChallenge`: pisos con ruido, de un solo uso). Sin bloqueo: la persona real lo resuelve y entra | Solo quien intenta entrar a esa cuenta |
| 10 o más | **Aviso al dueño de la cuenta** por correo (como mucho uno por hora): «Hubo varios intentos de entrar a tu cuenta. Si no fuiste vos, cambiá la contraseña». Con 2FA activo, el atacante igual no entra | El dueño de la cuenta (solo informa) |
| Pico general (por ejemplo, más de 200 fallos por hora en total) | Aviso al SuperAdmin por los canales que ya existen (campanita, push o Telegram) | El SuperAdmin |
| Ingreso correcto | Pone el contador de la cuenta en cero | — |

- **Datos:** contadores en la caché (`RateLimiter`), sin tablas nuevas.
- **Privacidad:** el aviso no dice desde qué IP ni revela nada más.
- **Pruebas a escribir:** el atacante no bloquea a la persona real; el desafío aparece desde el umbral; el aviso sale una sola vez por hora; un acierto reinicia el contador.

**Recomendación:** implementar R6 junto con R5 o antes: no requiere migración y no puede dejar a nadie afuera.
