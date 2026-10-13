# Etapa 8: ficha del ascensor, perfil del técnico y reparaciones

Se entrega en tres PRs: **8.1 y 8.2**, **8.3** y **8.4**. Las decisiones tomadas antes de empezar:

- **Contacto en el portal:** solo el de la empresa (nunca datos personales del técnico).
- **Reparaciones:** un área nueva de permisos, «Reparaciones».
- **QR:** un bloque opcional de reparaciones, apagado por defecto.

## 1. Marca y modelo del control (8.1)

- **Qué había:** «Controlador / maniobra», un texto libre, vacío en todos los equipos de producción. No existían la marca ni el modelo del control.
- **Qué se agregó:** `elevators.controller_brand` y `elevators.controller_model`, ambos opcionales.
  - La migración `2026_12_20_100000_add_controller_brand_and_model_to_elevators` solo agrega y es reversible.
  - Los equipos existentes quedan **vacíos**: no se inventa ningún valor.
- **Dónde se ven:**
  - en el formulario y en la ficha del ascensor;
  - en el registro de actividad (los cambios);
  - en la exportación completa (hoja «Ascensores»);
  - en el portal del cliente, junto al equipo, solo si hay dato.
- **El texto libre:** se conserva como «Maniobra (tipo o descripción)».
- **Otros datos técnicos:** fabricante, modelo, serie, año, capacidad, velocidad, paradas, máquina, motor, puertas y operador ya existían. No se agregaron más campos.

## 2. Foto de perfil del técnico (8.2)

- **Dónde se guarda:** reusa la columna `users.avatar`, que existía sin uso (no había ninguna foto cargada). No hay migración.
- **Quién la cambia:**
  - el propio técnico, desde «Mi perfil»;
  - el dueño de la empresa, desde la edición del técnico en el panel;
  - el SuperAdmin, dentro de la empresa elegida.
  - Un administrativo con «Técnicos» la ve pero no la cambia, igual que el resto de los datos del técnico.
  - Lo verifica `TechnicianPhotoService` en el servidor.
- **Formato y tamaño:** JPG, PNG o WebP de hasta 2 MB.
  - Se controlan la extensión, el tipo real del contenido y el tamaño en píxeles.
  - La imagen se decodifica y se vuelve a codificar como **JPEG cuadrado de 400 px, sin metadatos**: nunca se guarda el archivo original.
  - Un archivo que no es una imagen se rechaza sin dejar nada en el disco.
- **Almacenamiento:** disco privado, en `avatars/{empresa}/{aleatorio}.jpg`.
  - Al reemplazarla o quitarla, se borra el archivo anterior.
  - El borrado definitivo de una empresa incluye esas fotos.
- **Acceso:** solo por la ruta `/files/technician-photos/{usuario}`, que exige sesión.
  - La ven el propio técnico y quien opera el panel de su empresa; cualquier otro recibe 404.
  - La ruta solo sirve archivos de la carpeta de fotos, así que no expone otros archivos aunque la columna tuviera otra ruta.
  - Se sirve con `Cache-Control: private`.
- **Sin foto:** se muestran las iniciales (componente `x-user-photo`).
- **Dónde se ve:**
  - la lista de técnicos del panel;
  - «Mi perfil» del técnico;
  - el portal del cliente (8.3).
- **Registro de actividad:** los cambios hechos desde el panel quedan como `user.photo_updated` y `user.photo_removed`.
- **Exportación:** las fotos de perfil no se incluyen en la exportación completa, porque son datos personales de cada técnico.
