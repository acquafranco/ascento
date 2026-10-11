# Autenticación: contraseñas, correo y registro

## Contraseñas

Una sola política (`App\Support\Auth\PasswordPolicy`) en todos los lugares
donde se crea o cambia una contraseña:

- registro de empresas;
- alta y edición de usuarios en el panel (técnicos);
- activación del portal de clientes;
- recuperación ("¿Olvidaste tu contraseña?");
- cambio desde el perfil.

Reglas:
- al menos 10 caracteres, con letras y números, hasta 72 (límite de bcrypt);
- no puede ser común: lista local en `resources/data/common-passwords.txt`,
  también con números al final ("boca2024") o reemplazos ("p4ssw0rd");
- no puede ser una secuencia de teclado o abecedario, ni repetir uno o dos
  caracteres;
- no puede contener el nombre ni el correo de la persona.

**Las contraseñas actuales siguen funcionando**: la política se aplica solo
al crear o cambiar una. El ingreso no la valida. No se usa ningún servicio
externo (no se consulta "Have I Been Pwned").

Cada campo tiene un botón para mostrar u ocultar la contraseña: se usa con
Tab y Enter o Espacio, y no rompe los gestores de contraseñas ni el pegado.
Los formularios de alta y cambio piden repetirla (también el de técnicos en
el panel).

Para sumar contraseñas a la lista, agregalas en minúsculas, una por línea.

## Confirmación del correo

| Quién | Cómo se confirma | ¿Bloquea? |
|---|---|---|
| Admin de empresa | Correo al registrarse | No (aviso en el panel) |
| Técnico | Correo al crearlo desde el panel | No (aviso en la app) |
| Cliente del portal | Al activar la invitación (el enlace llegó a su correo) | No |
| Cualquiera | Al recuperar la contraseña con el enlace del correo | — |

- **Prueba válida:** solo el enlace firmado del correo, el de la invitación
  o el de recuperación confirman. Abrir el enlace de otra persona, uno
  manipulado o uno de un correo anterior no confirma nada.
- **Vencimiento:** el enlace vence a los 60 minutos. Si vence, la persona lo
  pide de nuevo desde el aviso.
- **Reenvío:** máximo 3 enlaces cada 10 minutos por cuenta, además del
  límite de 6 por minuto de la ruta.
- **Cambio de correo:** desde cualquier pantalla (perfil, panel, SuperAdmin),
  el correo nuevo queda sin confirmar y se manda el enlace a esa dirección.
- **Si el correo no sale** (SMTP caído), el técnico se crea igual y queda un
  aviso en el log.
- **Restricción puntual (ya existía):** las cuentas nuevas sin confirmar no
  pueden mandar correos a terceros en nombre de la empresa (`VerifiedSender`).
  Todo lo demás se puede usar sin confirmar, para que nadie quede trabado en
  la calle por no poder abrir el correo en el celular.

## Registro de empresas

`App\Support\Auth\RegistrationChallenge`, todo en el servidor:

1. **Campo trampa:** un campo que una persona no ve ni completa. Si viene con
   algo, se rechaza.
2. **Tiempo mínimo:** 4 segundos desde que se abrió el formulario, medidos en
   la sesión del servidor. Un envío directo, sin haber abierto el
   formulario, se rechaza.
3. **Desafío del ascensor:** el indicador de piso (dígitos de 7 segmentos,
   dibujados con ruido) y seis botones de piso mezclados. Hay que tocar el
   que coincide.
   - Cada botón manda un token al azar, no el número.
   - En la sesión solo quedan los HMAC de las respuestas.
   - Vence a los 15 minutos, admite 5 intentos y es de un solo uso.
   - Una vez resuelto, vale 15 minutos para corregir otros campos sin
     resolverlo de nuevo.
4. **Alternativa accesible:** una pregunta en palabras ("está en el piso
   tres y sube dos pisos") que se responde con números o en letras. Está
   siempre visible, sin recargar, y sirve para lectores de pantalla y
   dificultades visuales o motrices.
5. **Límite de la ruta:** 6 intentos de registro por minuto.

**Límites, sin vueltas:** esto frena los registros automáticos genéricos. Un
ataque dirigido, con lectura de imágenes o un script que entienda la
pregunta, lo puede resolver. Por eso es una capa más, junto con el límite de
intentos y la confirmación del correo. Si aparecieran registros falsos
igual, el paso siguiente gratuito sería un servicio como Cloudflare
Turnstile, que hoy no se usa porque es una dependencia externa.
