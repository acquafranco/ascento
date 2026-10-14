# Etapa 11 (R6): intentos repetidos contra una cuenta

`App\Services\Auth\AccountAttackGuard`, sumado a los límites anteriores (5 fallos por correo + IP; 30 fallos por IP cada 10 minutos).

- **Qué cuenta:** los fallos por correo, desde cualquier IP, exista o no la cuenta. La respuesta es la misma en los dos casos, así que no sirve para averiguar qué correos están registrados.
- **Desde 10 fallos en 15 minutos contra un correo:** para ese correo se pide además el **desafío humano** que ya usa el registro (el piso del indicador, con alternativa accesible en palabras).
  - **No bloquea la cuenta:** la persona real lo resuelve y entra.
  - Un programa que prueba contraseñas desde muchas IP ya no puede seguir a ciegas.
  - El desafío sigue visible aunque se recargue la página, hasta que vence la ventana o hay un ingreso correcto.
  - **Un desafío por intento.** En el registro, lo resuelto vale 15 minutos; en el ingreso no, porque esa sesión se podría usar desde muchas IP para seguir probando contraseñas sin resolver nada más.
- **Aviso al dueño de la cuenta**, si existe: un correo como mucho cada 6 horas. Dice que hubo intentos fallidos y cómo cambiar la contraseña, sin IP ni cantidades. Las cuentas que no existen nunca generan correos.
- **Pico general** (200 o más fallos en una hora en toda la plataforma): aviso por correo a los SuperAdmin, como mucho uno por hora.
- **Ingreso correcto:** pone el contador de la cuenta en cero.
- **Con 2FA** (SuperAdmin): primero la contraseña (y el desafío, si corresponde) y después el código. Los fallos del código tienen sus propios límites (`docs/etapa-11-2fa.md`).

**Qué reduce:**
- contraseñas probadas a ciegas contra una cuenta desde una botnet;
- probar muchas cuentas desde una IP (límite por IP);
- averiguar qué correos existen.

**Qué no evita:**
- una persona que resuelve el desafío a mano en cada intento, aunque eso es lento y sigue limitado por correo + IP;
- un programa hecho a medida que lea el desafío: la alternativa accesible es una pregunta en texto. Le sube el costo a cada intento, pero no lo impide;
- un atacante que ya tiene la contraseña correcta (para eso está el 2FA, hoy solo para el SuperAdmin);
- que alguien moleste a la víctima obligándola a resolver el desafío durante 15 minutos.
