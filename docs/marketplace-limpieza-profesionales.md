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

## 2. Procedimiento en producción (cada paso con aprobación)

1. **Inventario (solo lectura):**
   ```
   mysql <conexión> <base> < docs/sql/limpieza-profesionales-inventario.sql > inventario-antes.txt
   ```
   - La consulta C debe dar 0 filas.
   - La E también debe dar 0 filas.
   - Guardá el archivo: el bloque I (totales y `CHECKSUM TABLE`) se compara al final.
   - Si la consulta A no devuelve filas, 2027_01_20 nunca corrió: las consultas C, D y F fallan porque esas tablas no existen. Es normal.
2. **Copias de seguridad:**
   - **Completa:** la de Forge o un `mysqldump` completo, como indica `docs/backups.md`.
   - **De lo que se borra:**
     ```
     bash docs/sql/limpieza-profesionales-backup.sh <base> ~/backup-limpieza-AAAAMMDD <opciones de conexión>
     ```
     Comprobá que `conteos.txt` coincide con las consultas D, G y H del inventario. Guardá también `SHA256SUMS`.
   - **Verificar la copia:** restaurala en una base aparte y contá las filas:
     ```
     (echo "SET FOREIGN_KEY_CHECKS=0;"; cat 2-suppliers.sql 1-tablas-servicios.sql) | mysql <conexión> <base_de_prueba>
     ```
3. **Despliegue** del PR de esta etapa. El paso `php artisan migrate --force` de Forge aplica 2027_01_21.
4. **Verificación:**
   - Repetí el inventario; las consultas que fallan porque las tablas ya no existen son esperables.
   - El bloque I debe ser idéntico al de `inventario-antes.txt`.
   - La consulta 12 de `docs/sql/auditoria-marketplace.sql` debe dar `0 | 0`.

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

## 5. Cuestiones legales pendientes (requieren abogado o decisión comercial; `LEGAL_REVIEWED=false`)

1. **Titular:** razón social, CUIT y domicilio (`LEGAL_OWNER_*`). Hoy se muestran como «pendiente de completar».
2. **Términos 17:** rol de intermediario sin comisión y exclusión de responsabilidad por la compraventa, el pago, el envío, la garantía y la compatibilidad declarada por el proveedor. Además, el alcance de la «mediación» ante incidencias.
3. **Términos 18:**
   - **Premium:** renovación automática, cancelación sin reintegro del período iniciado («salvo que corresponda por ley») y aviso previo de cambios de tarifa: ¿qué plazo es «anticipación razonable»?
   - **Destacados:** el período corre aunque el proveedor pause la publicación, y se cancela por moderación, por quedar fuera del límite o por suspensión de la cuenta. Hay que definir si en esos casos corresponde devolver los días no usados. Hoy la devolución es a pedido y se revisa caso por caso.
   - **Botón de arrepentimiento y derecho de revocación** en contrataciones a distancia, si a algún proveedor se lo considera consumidor.
   - **Facturación** de Premium y destacados: comprobantes e impuestos, que no están implementados en Ascento.
4. **Términos 20 y Privacidad 01/05:** ley aplicable, jurisdicción y transferencias internacionales para usuarios de otros países.
5. **Conservación de datos:** plazo de conservación de los cobros a proveedores (plazo fiscal) y del historial de la otra parte después de un borrado.
6. **Datos de «Profesionales y servicios» que se borran con 2027_01_21:** confirmar que no hay obligación de conservarlos, y que borrar sus registros de auditoría es aceptable.
7. **Fecha de versión** (`config/legal.php`, hoy 2026-10-22): cambiarla solo cuando se publique esta versión, a la fecha real de publicación.
