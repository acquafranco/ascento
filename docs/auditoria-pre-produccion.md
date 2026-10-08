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
