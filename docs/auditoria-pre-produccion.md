# Auditoría pre-producción

Fecha: 2026-10-08. Base: `main` @ 3084a8a (incluye Stock, Servicios y Cobranzas).

## Errores encontrados y corregidos

| # | Severidad | Problema | Corrección |
|---|---|---|---|
| 1 | Importante | Un técnico podía firmar el **mantenimiento/inspección mensual de cualquier edificio** de su empresa (el formulario y el POST no exigían que el edificio estuviera asignado a él). | `DeliveryNoteController`: el técnico solo abre y guarda el remito mensual de un edificio asignado a él **para ese tipo de trabajo**. Admins sin cambios. |
| 2 | Importante | El remito de una **orden de trabajo** tomaba el `building_id` del formulario: manipulándolo, el remito quedaba en otro edificio que la orden. | El edificio sale siempre de la orden. |
| 3 | Importante | Un técnico podía **completar una orden sin remito** (`POST /work-orders/{id}/finish`, sin botón en la app): quedaba completada, con stock descontado, sin trabajo firmado. | Sin remito, el técnico es enviado a completarlo y firmarlo. El admin sigue cerrando desde el panel. |
| 4 | Importante | Las fotos de reportes **dependían de Imagick**: en un servidor sin Imagick, todo reporte nuevo fallaba. Además se guardaban a resolución completa (varios MB). | Imagick si está, si no GD. Se achican a 2000 px máx. (JPEG 85), siempre derechas. |
| 5 | Menor | "Insp. restantes" del técnico descontaba inspecciones **de otros técnicos y edificios**. | Se cuentan solo los edificios donde él es el inspector. |
| 6 | Menor | Lista de remitos del técnico sin paginar (con el historial de un año se trababa el celular). | Paginado de a 20. |
| 7 | Pedido | Técnicos: campo "WhatsApp" sin validación (aceptaba "abc" → `549`). Al crear una orden se intentaba mandar WhatsApp y se dejaba el teléfono en el log. | "Número de teléfono" con validación de celular argentino (acepta 0/15/+54 9), formato `+54 9 11 2345-6789`, compatible con lo guardado. Sin WhatsApp ni teléfono en el log. |
| 8 | Tests | La suite completa se caía por memoria (128 MB). 3 tests de fotos se salteaban. Un test de paginación dependía de estado global de Livewire. | `memory_limit` 512M en `phpunit.xml`; los 3 tests ahora corren con GD; aserción independiente del estado. |

## Pasos de despliegue

1. `php artisan migrate --force` (si no se corrió con Stock/Servicios/Cobranzas).
2. `php artisan reports:move-photos-private`: las fotos de reportes anteriores siguen en el disco **público** (accesibles por URL directa) hasta correrlo.
3. Verificar en el servidor `php -m | grep -E "imagick|gd"` (con GD alcanza; HEIC de iPhone necesita Imagick).
4. Scheduler activo (`schedule:run` cada minuto).

## Pendiente (decisiones de producto, no errores)

- No existe el rol "usuario/empleado": solo administrador y técnico (+ SuperAdmin).
- Reportes: una sola foto, obligatoria; no hay PDF de reportes. El "PDF" del remito es la impresión del navegador.
- Presupuestos: un solo importe, sin ítems, vigencia ni condiciones.
- El técnico ve "Todos los edificios" de su empresa con contacto y teléfono (útil para urgencias; decidir si se quiere).
- Materiales de una orden: los carga el admin; el técnico no puede declararlos desde el remito.

---

# Segunda etapa (rama `auditoria-pre-produccion-2`)

## Cambios

| Área | Cambio |
|---|---|
| Fotos | Tabla `report_photos`: hasta 6 fotos por reporte, opcionales. Un solo servicio (`ReportPhotoService`) para la app y el panel: re-codifica a JPEG, orienta, achica a 2000 px, disco privado, todo o nada (sin archivos sueltos). Se sirven por `/files/reports/{id}/photos/{foto}` con permisos y suscripción vigente. Borrar una foto borra el archivo; el borrado definitivo del reporte borra todo. El disco privado ya no expone `/storage/{path}` (`serve => false`). En el celular las fotos se achican antes de subir. |
| PDF | PDF real de reportes (dompdf ya instalado): empresa, cliente, edificio, equipo, técnico, fecha, prioridad, estado, descripción, observaciones y fotos incrustadas (sin URLs ni paths). Mismos permisos que el reporte. Nuevo campo `observations` (lo carga el admin). |
| Bug | El panel no dejaba guardar reportes hechos por la app ("El ascensor seleccionado no es válido"): mismos valores de equipo en los dos lados y validación backend del equipo. |
| Bug | Links públicos de presupuesto y remito daban 404 (o salían incompletos) si en el navegador había otra cuenta logueada. |
| Materiales | El técnico declara los materiales al firmar el remito de la orden (material activo de su empresa, cantidad > 0, máx. 2 decimales, máx. 20). Se descuentan al completarse, una vez. Costo, empresa y stock nunca salen del request. `declared_by` registra quién los declaró. El remito muestra los materiales. |
| Servicios | `building_visits.maintenance_service_id`: cada mantenimiento/inspección se vincula al contrato activo que cubre ese edificio y fecha (las visitas existentes, por migración). Guardia en el modelo: nunca una visita de otra empresa o de otro edificio. Página "Ver servicio": último mantenimiento, última inspección, cantidades, mes pendiente/próximo y la lista de visitas. Equipos del contrato (`units`). |
| Presupuestos | Ítems (concepto, detalle, cantidad, precio, subtotal); total calculado en el servidor; fecha, validez, condiciones, observaciones; estados borrador/enviado/aprobado/rechazado/anulado y "vencido" calculado. Con un cobro activo el presupuesto no se edita ni se borra. |
| Suscripción | Fotos y PDF también se cortan con la prueba/suscripción vencida (antes quedaban fuera). |
| Logs | Sin teléfonos ni texto de mensajes de WhatsApp. |
| Traducciones | Faltaban mensajes de validación en español (se veía "validation.mimetypes"). |

## Migraciones (todas no destructivas, probadas up/down/up en MySQL)

- `2026_10_16_100000_create_report_photos_table`: copia cada `reports.photo` como primera foto; la columna vieja queda. Rollback: la primera foto vuelve a `reports.photo`; ningún archivo se borra.
- `2026_10_16_100100_add_observations_to_reports_table`.
- `2026_10_16_100200_add_declared_by_to_work_order_materials_table` (nullable).
- `2026_10_16_100300_link_visits_to_maintenance_services`: columna nullable + vinculación de visitas existentes; `maintenance_services.units`.
- `2026_10_16_100400_add_items_and_terms_to_quotes`: un ítem por presupuesto existente (título + importe, mismo total), `pending` → `draft`, cliente completado desde el edificio si faltaba. `amount` se conserva. Rollback: `draft` → `pending`, `void` → `rejected`.

## Decisiones de producto pendientes

- Frecuencia de visitas por contrato (hoy las visitas son mensuales; la frecuencia del servicio es la de cobro).
- "Tipo de reporte": los reportes no tienen tipo (el PDF dice "Reporte de problema" + prioridad).
- PDF del reporte disponible en todos los planes (los reportes son de todos los planes).
- Stock insuficiente al cerrar una orden: se permite y queda en negativo con alerta (decisión documentada en `stock-servicios-cobranzas.md`).
