# Limpieza definitiva de «Profesionales y servicios»

La función se retiró del código en el PR #122 (`19eb80d`). Esta etapa borra lo que quedó en la base: la migración `2027_01_21_100000_drop_service_profiles_tables`.

## 1. Inventario y por qué cada cosa es exclusiva de la función retirada

| Qué se borra | Creado por | Por qué es exclusivo |
|---|---|---|
| `service_categories` | 2027_01_20 | Especialidades de los perfiles profesionales. Ningún código la usa desde #122. |
| `service_profiles` | 2027_01_20 | Perfiles profesionales. Ninguna otra tabla la referencia. |
| `service_profile_category` | 2027_01_20 | Relación perfil ↔ especialidad. |
| `service_profile_terms` | 2027_01_20 | Marcas y zonas normalizadas de los perfiles. |
| `service_contact_requests` | 2027_01_20 | Solicitudes de contacto a profesionales (referencias `P-…`). No son pedidos de repuestos (`marketplace_requests`, `R-…`). |
| `service_contact_messages` | 2027_01_20 | Mensajes de esas solicitudes. |
| `service_profile_reports` | 2027_01_20 | Reportes de perfiles. No son los reportes de productos (`marketplace_reports`). |
| `suppliers.sells_products` | 2027_01_20 | Solo distinguía cuentas «solo servicios». El registro vuelve a exigir CUIT y rubros siempre. |
| Avisos (`notifications`) con clave `mkt:team:svc-*`, `mkt:s{id}:svc:*`, `mkt:c{id}:svc:*` o `mkt:sr*` | ServiceProfiles / ServiceContacts | Esas claves solo las generaba la función retirada. Los avisos de repuestos usan `mkt:s{id}:<otra>`, `mkt:r{id}:*` y `mkt:team:<otra>`. |
| Auditoría (`platform_audit_logs`) con acción `marketplace.service*` | ídem | Acciones `service_profile.*`, `service_request.viewed`, `service_report.*` y `service_category.saved`. Ninguna acción de repuestos empieza así. |

**Lo que NO se toca:**
- `service_catalog_items`: es el catálogo de servicios de mantenimiento de cada empresa (2027_01_03), una función vigente. Por eso nada filtra por «service_%»: la migración y las consultas usan los 7 nombres exactos.
- Proveedores, productos, fotos, pedidos y sus ítems y eventos, favoritos, reportes de productos, configuración, destacados, cobros, suscripciones, empresas y usuarios.

**Dependencias:**
- Las claves foráneas solo salen de las tablas `service_*` hacia `suppliers`, `companies` y `users`. Desaparecen con sus tablas y no cambian esas tablas.
- Ninguna otra tabla referencia a las `service_*`; lo confirma la consulta E del inventario.
- El borrado de empresas y proveedores (`CompanyEraser`, `SupplierEraser`) ya no toca esas tablas.

**Cuentas «solo servicios»** (`sells_products = 0`):
- Si hay alguna, la migración **se detiene sin borrar nada**: sin la columna, esa cuenta pasaría a vender repuestos sin haber cargado su CUIT.
- Hay que decidir caso por caso: pedirle el CUIT y que lo valide el SuperAdmin, o cerrar la cuenta.

## 2. Procedimiento en producción (nada se ejecuta sin aprobación)

Se usa el despliegue habitual de Forge, cuyo script ya incluye `php artisan migrate --force`; ver `docs/operacion-produccion.md` §7. No se borra nada con SQL manual.

**Antes del despliegue**
1. **Punto de vuelta atrás:** anotá el commit desplegado hoy (Forge → Deployments).
2. **Comprobaciones previas** (solo lectura):
   ```
   mysql <conexión> <base> < docs/sql/limpieza-profesionales-inventario.sql > inventario-antes.txt
   ```
   - **A**, ¿se aplicó 2027_01_20?: si no devuelve filas, nunca se desplegó. Las consultas C, D y F fallan porque esas tablas no existen, y la migración solo borra avisos y auditoría, si los hay.
   - **C**, cuentas «solo servicios»: **debe dar 0 filas**. Si devuelve alguna, se frena acá y se decide qué hacer con cada cuenta. La migración también se detendría sola.
   - **E**, otras tablas que dependan de las de servicios: debe dar 0 filas.
   - **D, G y H:** cuánto se va a borrar.
   - **I:** totales y `CHECKSUM TABLE` comerciales, para comparar al final.
3. **Copias de seguridad:**
   - **Completa:** la de Forge o `php artisan backup:run`, verificada con `php artisan backup:verify <id>` (ver `docs/backups.md`).
   - **De lo que se borra:**
     ```
     bash docs/sql/limpieza-profesionales-backup.sh <base> ~/backup-limpieza-AAAAMMDD <opciones de conexión>
     ```
     `conteos.txt` debe coincidir con D, G y H del inventario.
   - **Restaurabilidad:** importá `2-suppliers.sql` y `1-tablas-servicios.sql` en una base aparte, contá las filas y corré `shasum -a 256 -c SHA256SUMS`.
   - Guardá todo fuera del servidor.

**Despliegue**
4. Merge del PR de `mejora/marketplace-cierre` y deploy normal de Forge. `migrate --force` aplica 2027_01_21.

**Validación posterior**
5. Revisar el registro del deploy:
   - si figura `2027_01_21 … DONE`, la migración se aplicó;
   - si figura «Hay proveedores registrados “solo servicios”», no se borró nada, y se decide caso por caso antes de volver a desplegar.
6. `php artisan migrate:status`: 2027_01_21 debe figurar como `Ran`.
7. Repetir el inventario. Las consultas sobre tablas ya borradas fallan, y es lo esperado. El bloque I debe ser **idéntico** al de `inventario-antes.txt`.
8. La consulta 12 de `docs/sql/auditoria-marketplace.sql` debe dar `0 | 0`.
9. Pruebas manuales:
   - una empresa abre «Repuestos», busca, ve un producto y envía un pedido;
   - el proveedor lo ve en «Pedidos»;
   - `/profesionales` da 404.
10. `php artisan ascento:check-production`: sin ✗.

## 3. Recuperación

1. Revertir la migración: `php artisan migrate:rollback --step=1`. `down()` recrea vacías las 7 tablas, con las 11 especialidades iniciales, y la columna `sells_products` con valor 1.
2. Restaurar los datos de la copia de seguridad:
   ```
   (echo "SET FOREIGN_KEY_CHECKS=0;"; cat 1-tablas-servicios.sql 3-avisos.sql 4-auditoria.sql; echo "SET FOREIGN_KEY_CHECKS=1;") | mysql <conexión> <base>
   ```
3. `sells_products`: la migración solo corre si todas las cuentas tenían 1, así que no hay valores que recuperar. `2-suppliers.sql` queda como respaldo y **no** se restaura sobre la tabla viva, porque la reemplazaría entera. Si alguna vez hiciera falta, se carga en una base aparte y se copia solo esa columna.

## 4. Pruebas realizadas

- **Automatizadas** (`ServicesCleanupMigrationTest`), con proveedores, productos, pedidos, Premium, cobros (uno devuelto), destacados, avisos y auditoría:
  - borra solo lo indicado;
  - las 14 tablas comerciales quedan idénticas fila por fila;
  - se detiene ante una cuenta «solo servicios» sin borrar nada;
  - funciona de ida y vuelta.
- **MySQL 8 local** (`ascento_migcheck`, con 4 proveedores, 16 productos, 3 pedidos, 3 Premium, 6 cobros, destacados y los restos de servicios):
  1. inventario;
  2. copia de seguridad, verificada restaurándola en `ascento_restorecheck` y con `SHA256SUMS`;
  3. migración;
  4. totales y `CHECKSUM TABLE` de las 11 tablas comerciales idénticos, y `suppliers` idéntica fila por fila sin la columna;
  5. rollback, recuperación desde la copia, totales idénticos y migración de nuevo;
  6. bloqueo ante una cuenta «solo servicios».

## 5. Textos legales: qué está corregido y qué falta (`LEGAL_REVIEWED=false`)

Los datos del titular no se inventan: se muestran como «pendiente de completar» hasta cargar `LEGAL_OWNER_*`. La fecha de versión (`config/legal.php`, 2026-10-22) se cambia recién al publicar, a la fecha real de publicación.

**Ya corregido en los textos**
- No queda ninguna referencia a «Profesionales y servicios» en términos, privacidad, eliminación de datos ni cookies.
  - Términos: se quitó la sección 19 y se renumeró; calculadora pasó a ser la 19 y ley aplicable, la 20.
  - Privacidad: se quitó la fila de perfiles y solicitudes.
- Términos 17: Ascento conecta compradores y proveedores, no vende, no cobra comisiones ni procesa pagos de repuestos. El pago, la factura, el envío, la entrega, la garantía y las devoluciones se acuerdan directamente entre las partes. Coincide con el código: no hay carrito ni pagos de repuestos, y ningún pedido se marca como pagado.
- Términos 18: plan gratuito permanente, Premium, qué pasa al vencer o cancelar, destacados (activación, vencimiento, cancelación por moderación o fuera del límite, pausa, destacados de Ascento sin pisar días pagados), devoluciones a pedido y suspensión.
  - Los precios se muestran solo mientras se venden.
  - Los países con cobro salen de la configuración (antes había un texto fijo).
- Privacidad y eliminación de datos: describen qué se guarda de proveedores, pedidos y cobros, qué se conserva al cerrar una cuenta y que el medio de pago se carga en Mercado Pago.

**Decisiones comerciales pendientes**
1. Precios de Premium y de los destacados (propuesta: ARS 19.900, 4.900 y 9.900), y cuándo encender las ventas.
2. Si se devuelven los días no usados de un destacado cancelado por moderación, por quedar fuera del límite o por suspensión. Hoy la devolución es a pedido y se revisa caso por caso.
3. Qué plazo concreto es «anticipación razonable» para avisar un cambio de tarifa.
4. La regla al volver al plan gratuito sin elegir: «más recientes» o «más antiguas».
5. Cobrar en otros países, lo que requiere una pasarela y precios en su moneda.
6. Facturación de Premium y destacados (comprobantes), que no está implementada.

**Requieren revisión profesional (abogado o contador)**
1. Datos del titular (razón social, CUIT y domicilio) y su publicación.
2. Términos 17: alcance de la exclusión de responsabilidad por la compraventa entre partes, la compatibilidad declarada por el proveedor y la «mediación» ante incidencias.
3. Términos 18: renovación automática, cancelación sin reintegro del período iniciado, botón de arrepentimiento y derecho de revocación si algún proveedor cuenta como consumidor, y validez del aviso de cambios de tarifa.
4. Términos 20 y Privacidad 01/05: ley aplicable, jurisdicción y transferencias internacionales para usuarios de otros países.
5. Plazos de conservación de los cobros (obligación fiscal) y del historial de la otra parte después de un borrado.
6. Que borrar los datos y la auditoría de «Profesionales y servicios» (2027_01_21) no choque con ninguna obligación de conservación.
7. Obligaciones fiscales de facturar Premium y destacados.
