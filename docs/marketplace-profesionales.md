# Marketplace — Profesionales y servicios

Primera versión de «Profesionales y servicios». El marketplace tiene dos áreas separadas:

1. **Productos y repuestos.** Sin cambios: catálogo para empresas, pedidos de cotización, plan gratuito o Premium y destacados.
2. **Profesionales y servicios.** Perfiles profesionales y solicitudes de contacto.

## Modelo comercial
Todo se administra en «Configuración del marketplace». Es la única fuente de precios y límites; no hay tarifas en el código.

| Concepto | Valor inicial propuesto | Estado |
|---|---|---|
| Plan gratuito de productos | hasta 20 publicados, gratis para siempre | activo |
| Premium | hasta 1.000 publicados, ARS 19.900/mes | **se carga en el panel; la venta sigue apagada** |
| Destacado 7 días | ARS 4.900 | **se carga en el panel; la venta sigue apagada** |
| Destacado 30 días | ARS 9.900 | **se carga en el panel; la venta sigue apagada** |
| Perfil profesional, publicación de servicios y solicitudes de contacto | gratis, sin vencimiento ni límite | activo |

Ninguna migración carga precios: lo hace el SuperAdmin desde el panel, con los interruptores «Vender» apagados, y el cambio queda en la auditoría. Así no se pisa la configuración de producción.

- **Cada compra conserva su precio:**
  - el destacado guarda el importe y la duración, y el pago se valida contra eso;
  - la suscripción guarda su importe, que es el que lleva el débito automático.
- **Términos:** muestran los precios solo mientras la venta está encendida.

## Cuentas: sin un segundo login
- **Proveedor de repuestos:** puede tener un perfil profesional con la misma cuenta (`service_profiles.supplier_id`). Lo maneja en «Perfil profesional» y «Consultas».
- **Solo servicios:** se registra en `/proveedores/registro` eligiendo «Servicios profesionales».
  - El CUIT es opcional y queda `suppliers.sells_products = false`, sin catálogo de productos.
  - Para vender después, carga el CUIT en «Mi cuenta» y se avisa al equipo.
  - La aprobación del SuperAdmin es la misma de siempre.
- **Empresa de mantenimiento:** su dueño publica un perfil desde «Mi perfil profesional» en el panel (`service_profiles.company_id`).
  - No cambian usuarios, roles ni autenticación.
  - Un usuario sigue perteneciendo a una empresa o a un proveedor.
- **Existentes:** las cuentas actuales no cambian (`sells_products` queda en `true`) y a nadie se le crea un perfil.

## Visibilidad y moderación
Un perfil se ve en `/profesionales` (público, sin login) cuando se cumplen todas estas condiciones:
- el dueño lo publicó;
- Ascento lo aprobó la primera vez;
- no está oculto ni suspendido;
- la cuenta dueña está habilitada: proveedor aprobado y sin cerrar, o empresa activa con acceso vigente.

Estados:
| Estado | Quién lo decide | Efecto |
|---|---|---|
| Borrador / publicado / archivado | el dueño | Archivar lo retira del catálogo |
| Oculto | Ascento | Sale del catálogo; el dueño puede corregirlo |
| Suspendido | Ascento | Sale del catálogo, no recibe solicitudes nuevas y el dueño no edita ni responde |

- **Sin borrado:** no hay acción para eliminar un perfil, y las solicitudes y su historial se conservan siempre.
- **Información declarada:** lo profesional lo declara el titular y Ascento no lo verifica. No hay insignias, y el perfil lo dice.
- **Datos privados:** nunca se publican correos, teléfonos, domicilios ni el CUIT.

## Solicitudes de contacto
1. La empresa ingresa al panel (dueño o personal con permiso de «Stock» para gestionar) y abre `/admin/profesionales?perfil=…`.
2. Describe lo que necesita y la envía.
3. El profesional recibe el aviso (campanita, tiempo real, push y correo), lee y responde. Si es proveedor, lo hace en `/proveedores/solicitudes`; si es empresa, en «Mi perfil profesional».
4. Cualquiera de los dos cierra la solicitud.

- **Aislamiento:** una referencia o un id ajeno da 404.
- **Anti-spam:**
  - 10 solicitudes por usuario y por hora;
  - 30 por empresa y por día;
  - una sola abierta por empresa y perfil, decidida con el perfil bloqueado;
  - una clave de envío que evita duplicados;
  - 60 mensajes por usuario y por hora.
- **Avisos:** salen después de guardar, así que un fallo de correo no duplica la solicitud.
- **SuperAdmin:**
  - la lista muestra solo datos administrativos;
  - el contenido se abre con «Abrir contenido» y un motivo, que queda en la auditoría, y se ve por 15 minutos.

## Cierre de cuentas
- **`CompanyEraser`:** las solicitudes que envió la empresa se conservan cerradas y sin sus datos. Su perfil profesional queda archivado y sin datos.
- **`SupplierEraser`:** el perfil queda archivado y sin datos, y las solicitudes se conservan.

## Destacados asignados por el SuperAdmin
- Solo se pueden asignar a productos visibles: publicados, no ocultos, dentro del límite del plan y de un proveedor habilitado.
- **Regla de superposición:** si ya hay un destacado vigente o programado, la asignación empieza cuando termina ese y llega hasta la fecha elegida. Los días pagados no se tocan. Si la fecha no supera el fin vigente, no se crea nada.

## Migración `2027_01_20_100000_create_service_profiles_tables`
- **Qué hace:** agrega tablas nuevas, la columna `suppliers.sells_products` (valor por defecto `true`) y las 11 especialidades iniciales en una tabla nueva.
- **Qué no hace:** no modifica ni borra datos existentes.
- **Reversión:** `down()` borra solo lo que agregó.

## Verificación de producción (solo lectura)
`docs/sql/auditoria-marketplace.sql` arranca con `SET SESSION TRANSACTION READ ONLY` y termina en `ROLLBACK`. Las consultas 4 a 9 deberían volver vacías; si alguna trae filas, revisalas antes de corregir y nunca borres. La 12 se corre después del despliegue.
