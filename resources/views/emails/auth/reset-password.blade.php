<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña - Ascento</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family:Arial, Helvetica, sans-serif; color:#333333;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f5; padding:40px 15px;">
    <tr>
        <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:600px; background:#ffffff; border-radius:16px; overflow:hidden;">

            <!-- Encabezado -->
            <tr>
                <td align="center" style="padding:35px 30px 20px;">

                    <img src="{{ asset('images/logo.png') }}"
                        alt="Ascento"
                        style="display:block; margin:0 auto; max-width:180px; height:auto;">

                    <div style="margin-top:8px; font-size:14px; color:#777777;">
                        Gestión y mantenimiento de ascensores
                    </div>

                </td>
            </tr>

            <!-- Contenido -->
            <tr>
                <td style="padding:20px 40px 40px;">

                    <h1 style="margin:0 0 20px; font-size:26px; color:#222222;">
                        Restablecé tu contraseña
                    </h1>

                    <p style="font-size:16px; line-height:1.6; margin:0 0 15px;">
                        Hola,
                    </p>

                    <p style="font-size:16px; line-height:1.6; margin:0 0 15px;">
                        Recibimos una solicitud para restablecer la contraseña
                        de tu cuenta en <strong>Ascento</strong>.
                    </p>

                    <p style="font-size:16px; line-height:1.6; margin:0 0 30px;">
                        Para crear una nueva contraseña, hacé clic en el siguiente botón:
                    </p>

                    <!-- Botón -->
                    <table cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                            <td align="center">

                                <a href="{{ $url }}"
                                   style="display:inline-block;
                                          background:#FF6A1A;
                                          color:#ffffff;
                                          text-decoration:none;
                                          font-size:16px;
                                          font-weight:bold;
                                          padding:14px 28px;
                                          border-radius:10px;">
                                    Restablecer contraseña
                                </a>

                            </td>
                        </tr>
                    </table>

                    <p style="font-size:14px; line-height:1.6; color:#777777; margin:30px 0 10px;">
                        Este enlace será válido durante 60 minutos.
                    </p>

                    <p style="font-size:14px; line-height:1.6; color:#777777; margin:0;">
                        Si no solicitaste restablecer tu contraseña, podés ignorar
                        este correo. Tu contraseña actual seguirá siendo la misma.
                    </p>

                    <!-- Separador -->
                    <div style="border-top:1px solid #eeeeee; margin:30px 0 20px;"></div>

                    <p style="font-size:13px; line-height:1.5; color:#999999; margin:0;">
                        Si el botón no funciona, copiá y pegá el siguiente enlace
                        en tu navegador:
                    </p>

                    <p style="font-size:12px; line-height:1.5; word-break:break-all; color:#FF6A1A; margin-top:10px;">
                        {{ $url }}
                    </p>

                </td>
            </tr>

            <!-- Footer -->
            <tr>
                <td align="center"
                    style="background:#fafafa; padding:25px 30px;">

                    <p style="font-size:13px; color:#999999; margin:0;">
                        © {{ date('Y') }} Ascento
                    </p>

                    <p style="font-size:12px; color:#aaaaaa; margin:8px 0 0;">
                        Este correo fue enviado automáticamente. Por favor, no respondas a este mensaje.
                    </p>

                </td>
            </tr>

        </table>

    </td>
</tr>
</table>
</body>
</html>
