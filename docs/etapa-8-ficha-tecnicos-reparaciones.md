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
- **Acceso:** solo por la ruta `/files/technician-photos/{usuario}?v={versión}`, que exige sesión y una empresa con acceso vigente.
  - **Quién la ve:** el propio técnico, el dueño, el SuperAdmin dentro de esa empresa y un administrativo vigente con «Técnicos».
  - **Quién no:** otra empresa, otro técnico, un administrativo sin esa área o desactivado, el portal (hasta la 8.3) y quien no tenga sesión.
  - **Siempre 404:** un id de alguien que no es técnico, de un técnico desactivado o inexistente, y una versión distinta de la actual.
  - **Solo su carpeta:** sirve únicamente archivos de `avatars/{empresa del técnico}/`, aunque la columna tuviera otra ruta.
- **Fotos reemplazadas o quitadas:**
  - la URL lleva la versión de la foto, así que la de una foto anterior responde 404;
  - se sirve con `Cache-Control: private, no-cache` y `ETag`: el navegador pregunta cada vez y recibe 304 si no cambió, de modo que nunca muestra una foto vieja desde su caché;
  - el cambio se hace bajo bloqueo de la fila, así que dos reemplazos simultáneos no dejan archivos huérfanos;
  - si falla el guardado, se borra el archivo nuevo.
- **Sin foto:** se muestran las iniciales (componente `x-user-photo`).
- **Dónde se ve:**
  - la lista de técnicos del panel;
  - «Mi perfil» del técnico;
  - el portal del cliente (8.3).
- **Registro de actividad:** los cambios hechos desde el panel quedan como `user.photo_updated` y `user.photo_removed`.
- **Exportación:** las fotos de perfil no se incluyen en la exportación completa, porque son datos personales de cada técnico.

## 3. Responsables en el portal del cliente (8.3)

- **Quiénes aparecen:** los técnicos asignados (mantenimiento o inspección, en `building_user`) a los edificios que esa persona tiene autorizados en el portal, de la empresa activa.
  - Nunca toda la plantilla: un técnico de otro edificio, de otro cliente o sin edificios no aparece.
  - Tampoco un técnico desactivado, ni uno de otra empresa aunque estuviera asignado a un edificio de esta.
  - La regla vive en `PortalResponsibles`, que usa `PortalAccess`, el mismo criterio del resto del portal.
- **Qué se muestra:** foto (o iniciales), nombre, rol («Mantenimiento», «Inspección» o los dos) y los edificios asignados.
  - En el inicio del portal: «Quiénes atienden tus edificios».
  - En la ficha del edificio: «Responsables de este edificio».
- **Contacto:** solo el teléfono y el correo de la empresa, de «Mi empresa». Nunca los datos personales del técnico.
- **Foto:** la ruta propia del portal, `/portal/responsables/{técnico}/foto?v={versión}`, la sirve solo si el técnico es responsable de uno de sus edificios.
  - Usa la misma versión, el mismo `no-cache` y la misma carpeta privada que el panel.
  - Cualquier otro caso responde 404.
  - La ruta del panel sigue cerrada para el portal.
- **Solo lectura:** el portal no tiene ninguna acción sobre técnicos ni asignaciones, y la ruta de la foto solo admite `GET`.
- **Cambios en las asignaciones o en los edificios autorizados:** rigen en el pedido siguiente.

## 4. Componentes enviados a reparación (8.4)

- **Qué había:** nada equivalente.
  - Las órdenes de trabajo registran tareas en el edificio, no el envío de una pieza a un taller.
  - El stock registra materiales, no reparaciones de terceros.
  - Por eso se creó un módulo propio: «Reparaciones», en Operaciones (`/admin/reparaciones`).
- **Qué se registra:**
  - edificio (obligatorio), ascensor (opcional, del mismo edificio) y orden de trabajo (opcional, del mismo edificio);
  - componente: variador, motor, placa electrónica, control u otro, con un detalle libre;
  - marca, modelo, número de serie y descripción de la falla;
  - fecha de envío, proveedor o taller y responsable del seguimiento;
  - presupuesto de reparación, costo final, devolución estimada y devolución real;
  - observaciones, y documentos o fotos (PDF o imágenes de hasta 20 MB, en el disco privado, verificados por su contenido).
- **Propiedad y responsables:** el plan maestro pide registrar la «empresa propietaria», pero no la define, y la documentación anterior tampoco. Por ahora se interpreta así, sin campos nuevos:
  - **Empresa de mantenimiento que gestiona la reparación:** la que la registra en Ascento (`company_id`). Se asigna sola, no se elige ni se puede cambiar, y todo se aísla por ella.
  - **Cliente o consorcio asociado al edificio:** sale del edificio vinculado (`buildings.client_id`); no se copia ni se elige aparte. La ficha lo muestra junto al edificio.
  - **Propietario del equipo:** no se registra. Si en el futuro se determina que es una entidad distinta del cliente del edificio (por ejemplo, el dueño de la pieza o un tercero), habrá que definirlo y agregarlo como un dato propio, con su migración.
- **Vínculos que dejan de estar vigentes:** si después del alta se desactiva el edificio, se borra la orden de trabajo vinculada o el responsable pierde el acceso, el componente puede seguir avanzando y corregirse.
  - Se conservan los vínculos que ya tenía; en «Corregir» aparecen marcados «(desactivado)», «(borrada)» o «(sin acceso)».
  - Elegir uno nuevo exige que esté vigente.
  - Conservar un vínculo nunca habilita a otra empresa: el edificio y la orden se buscan siempre en la empresa del componente, y el responsable tiene que ser de esa empresa con rol de dueño, técnico o administrativo. Un dato manipulado se rechaza.
- **Estados y transiciones:** solo se avanza a un estado permitido; `RepairService` lo verifica bajo bloqueo de la fila.

| Desde | Puede pasar a |
|---|---|
| Pendiente de envío | Enviado, Cancelado |
| Enviado | En diagnóstico, Esperando presupuesto, En reparación, Cancelado |
| En diagnóstico | Esperando presupuesto, En reparación, Cancelado |
| Esperando presupuesto | Esperando autorización, Cancelado |
| Esperando autorización | En reparación, Cancelado |
| En reparación | Reparado, Cancelado |
| Reparado | Recibido |
| Recibido / Cancelado | (finales) |

- **Reglas:**
  - un componente enviado **nunca** pasa a «Reparado» sin pasar por «En reparación»;
  - «Enviado» pide la fecha de envío, que no puede ser futura;
  - «Recibido» pide la fecha de devolución, que no puede ser anterior al envío;
  - la devolución estimada no puede ser anterior al envío;
  - los montos no pueden ser negativos;
  - el estado no se edita en «Corregir»: solo con «Cambiar estado».
- **Historial de cambios:** el alta, cada cambio de dato o de estado («de → a») y cada archivo adjunto quedan en el registro de actividad, y la ficha los muestra con nombres en vez de ids.
- **Sin borrado:** un componente se cancela, no se borra.
- **Permisos:** área nueva de administrativos, **«Reparaciones»**.
  - «Ver»: listado, ficha, historial y archivos.
  - «Gestionar»: registrar, corregir, cambiar de estado y adjuntar.
  - Lo verifica `RepairService` en el servidor, además de ocultar los botones.
  - El dueño puede todo; el SuperAdmin, dentro de la empresa elegida.
  - Los técnicos no entran al panel. Otra empresa recibe 404.
  - El área nueva queda **apagada** para los administrativos existentes.
- **Responsable del seguimiento:** el dueño, un técnico o un administrativo vigente con «Reparaciones», de la empresa.
- **QR público:** bloque «Componentes en reparación», **apagado por defecto**.
  - Si la empresa lo publica, muestra por equipo solo el tipo de componente, un **estado público** y desde cuándo está fuera (la fecha de envío). Los componentes sin equipo van en un bloque del edificio.
  - El estado público no revela la negociación comercial ni el detalle del taller (`RepairComponent::PUBLIC_STATUSES`). El panel privado sigue mostrando el estado real.

| Estado real (panel) | En el QR |
|---|---|
| Pendiente de envío | No figura |
| Enviado | Enviado a reparación |
| En diagnóstico | En reparación |
| Esperando presupuesto | En reparación |
| Esperando autorización | En reparación |
| En reparación | En reparación |
| Reparado | Reparado, a la espera de su devolución |
| Recibido | No figura |
| Cancelado | No figura |

  - Nunca muestra proveedor, presupuesto, costo, falla, marca, modelo, serie, detalle, observaciones, archivos ni historial.
  - **La vista previa del panel** («QR públicos») usa exactamente la misma información que la página pública (`PublicBuildingInfo`).
  - Un administrativo con «QR y datos públicos» pero sin «Reparaciones» ve en la vista previa solo lo que vería cualquiera en el QR. No accede al listado, la ficha, los archivos ni los cambios de reparaciones.
- **Datos:**
  - la migración `2026_12_25_100000_create_repair_components` solo agrega y es reversible;
  - tablas nuevas: `repair_components` y `repair_component_files`, y la columna `building_public_pages.show_repairs`;
  - la exportación completa suma la hoja «Reparaciones» y los archivos;
  - el borrado definitivo de una empresa incluye todo.
- **Planes:** en todos los planes, sin restricciones ni límites nuevos.
- **Fuera de alcance:** pagos, facturación y sistemas externos.
