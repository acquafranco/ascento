# Reclamos de clientes desde el portal

## Flujo
1. **Portal → «Reclamos» → «Nuevo reclamo»:**
   - edificio autorizado y ascensor, opcional y del mismo edificio;
   - categoría y descripción;
   - fecha y hora, opcional;
   - teléfono, solo si la persona no tiene uno en su perfil;
   - hasta 3 fotos JPG, PNG o WebP de hasta 5 MB.
   Arriba, un aviso rojo: ante un atrapamiento o un peligro, usar la alarma del ascensor y llamar al 911.
2. **Se guarda primero** el reclamo, con su número correlativo por empresa (R-00001) y el historial «Reclamo registrado». Después se avisa al dueño y a los administrativos con «Órdenes de trabajo» (campanita, push y correo). Si el aviso falla, el reclamo queda guardado y el error se registra sin datos.
3. **El portal dice que quedó «registrado»**, nunca que alguien lo leyó.
4. **Panel → Operaciones → «Reclamos de clientes»:**
   - cambiar el estado (Nuevo, En revisión, En gestión, Resuelto, Cerrado);
   - responder al cliente (es público y le llega un aviso);
   - nota interna (nunca se ve en el portal);
   - asignar un responsable (dueño o administrativo de la misma empresa);
   - «Crear orden de trabajo»: crea una orden de tipo «Reclamo», sin técnicos, vinculada al reclamo. Es una acción explícita y crea una sola orden.
5. **El cliente ve en su portal** el estado y las novedades públicas: alta, cambios de estado y respuestas.

## Tablas
- `client_claims`: el reclamo.
- `client_claim_events`: el historial; `public` separa lo que ve el cliente.
- `client_claim_photos`: las fotos, en disco privado `client-claims/{empresa}/{reclamo}/`.

## Aislamiento
- **Empresa, cliente y edificio** salen del acceso vigente al portal (`PortalAccess`). El edificio tiene que ser del cliente de esa persona y estar autorizado para ella. Los `company_id` y `client_id` del pedido se ignoran.
- **El cliente ve solo los reclamos que presentó él**, de la empresa actual; cualquier otro devuelve 404. Las fotos se sirven por controlador con el mismo control.
- **Panel:**
  - solo los reclamos de la empresa actual;
  - «ver» consulta y «gestionar» cambia el estado, responde, anota, asigna y crea la orden;
  - el servicio vuelve a verificar la empresa y el permiso en cada acción.
- **Técnicos:** no acceden a los reclamos. Si se crea la orden, la reciben como siempre.
- **Requisito de plan:** el portal en el plan (`client_portal`). Lo ya creado sigue visible.
- **Exportación:** hojas «Reclamos» e «Historial de reclamos».
- **Borrado de empresa:** incluye las tablas y las fotos.
