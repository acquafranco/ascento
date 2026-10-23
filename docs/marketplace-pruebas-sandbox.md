# Marketplace: pruebas de Premium y destacados en el sandbox de Mercado Pago

Todo esto está **sin ejecutar**. Se hace en un entorno que no es producción y solo con credenciales de **usuarios de prueba**. Nunca con credenciales reales.

## 0. Antes de empezar: qué credenciales son

Las credenciales de usuarios de prueba también empiezan con `APP_USR-`, así que el prefijo no alcanza para saber si son de prueba.

- Corré `php artisan mercadopago:check`: consulta la cuenta y dice «USUARIO DE PRUEBA» si lo es. No cobra ni crea nada.
- Si la cuenta **no** es de prueba, no sigas: esas credenciales cobran de verdad.

| Variable | En el sandbox | En producción |
|---|---|---|
| `MERCADOPAGO_ACCESS_TOKEN` | token del usuario de prueba **vendedor** | token productivo de la cuenta de Ascento |
| `MERCADOPAGO_WEBHOOK_SECRET` | clave secreta de los webhooks de la app de prueba | clave secreta de la app productiva |
| `MERCADOPAGO_TEST_PAYER_EMAIL` | correo exacto del usuario de prueba **comprador** | vacía |
| `APP_URL` | URL pública con https (por ejemplo, un túnel) | `https://ascento.online` |
| Webhooks en el panel de Mercado Pago | «Planes y suscripciones» **y** «Pagos», con la URL `<APP_URL>/api/mercadopago/webhook` | igual, en la app productiva |

Sin la clave del webhook o sin el token, «Configuración del marketplace» no deja encender las ventas y muestra qué falta, con el nombre de cada variable y nunca su valor.

## 1. Preparación

1. Cargá las variables de la tabla y corré `php artisan optimize`.
2. En «Configuración del marketplace»:
   - país Argentina;
   - precios de **prueba** que decidas (no son los comerciales);
   - encendé «Vender Premium» y «Vender destacados» **solo en este entorno**.
3. Creá un proveedor de Argentina, aprobado, con un producto publicado.
4. Antes de cada caso, corré `docs/sql/auditoria-marketplace.sql` (consultas 3, 7, 8 y 9) para tener una línea de base.

## 2. Casos

| # | Caso | Cómo | Resultado esperado |
|---|---|---|---|
| P1 | Premium aprobado | «Plan» → contratar → pagar con tarjeta de prueba aprobada | La suscripción queda autorizada. Recién con la primera cuota aprobada pasa a Premium y el límite sube a 1.000. Se registra un cobro aprobado con el precio y la moneda configurados. |
| P2 | Premium rechazado | Contratar y pagar con una tarjeta de prueba que se rechaza | Sigue en el plan gratuito. Se registra el cobro rechazado y se avisa al proveedor. |
| P3 | Cuota rechazada después | Con P1 activo, forzar un rechazo en la siguiente cuota (o simularlo desde el panel de Mercado Pago) | «Cobro rechazado»: 5 días de tolerancia y después vuelve al plan gratuito, sin borrar nada. |
| P4 | Cancelación de Premium | «Plan» → cancelar | Mercado Pago la cancela. Sigue Premium hasta el fin del período pago y no hay cobros nuevos. Un aviso viejo «authorized» no la reactiva. |
| P5 | Devolución de una cuota | Devolver la cuota desde el panel de Mercado Pago | El cobro queda «devuelto» y no vuelve a «aprobado». Se avisa al equipo. |
| D1 | Destacado aprobado (7 y 30 días) | «Destacados» → elegir producto y duración → pagar aprobado | Queda activo con la etiqueta. El período es el comprado y el importe y la moneda, los de la compra. |
| D2 | Destacado rechazado | Pagar con tarjeta rechazada | No se activa; queda pendiente o rechazado. |
| D3 | Abandonar el checkout | Abrir el pago y volver sin pagar | No se activa. Pasadas 24 h la compra vence. |
| D4 | Devolución de un destacado | Devolver el pago desde el panel de Mercado Pago | El destacado se cancela y el cobro queda «devuelto». Un pago posterior de la misma compra no lo reactiva. |
| D5 | Doble pago de una misma compra | Pagar dos veces la misma preferencia, si el sandbox lo permite | Se activa una sola vez. El segundo pago queda marcado para devolver (consulta 7). |
| W1 | Webhook repetido | Reenviar desde el panel de Mercado Pago el mismo aviso de P1 y de D1 | Nada cambia: no se suma otro mes ni se activa dos veces. |
| W2 | Webhook con firma inválida | `curl -X POST <APP_URL>/api/mercadopago/webhook -d '{}'` sin `x-signature` | 401 `invalid_signature`; nada cambia. |
| R1 | Retorno del navegador manipulado | Abrir `/proveedores/suscripcion?mp=return&status=approved` y `/proveedores/destacados?mp=return&collection_status=approved&payment_id=1` sin haber pagado | Nada se activa: la vuelta solo consulta el estado real en Mercado Pago. |
| C1 | Conciliación | Desactivar un momento los webhooks, pagar un destacado, reactivarlos y correr `php artisan marketplace:reconcile-payments` | El destacado se activa por la conciliación. |
| I1 | Proveedor de otro país | Un proveedor de Chile con productos | Publica gratis hasta el límite y no ve opciones de pago; contratar o comprar da error. |

## 3. Después de cada caso

- Consulta 3 de `docs/sql/auditoria-marketplace.sql`: totales por estado.
- Consultas 7, 8 y 9: deben quedar vacías, salvo en D5, donde la 7 debe mostrar el pago duplicado.
- En «Cobros a proveedores» del panel, solo los cobros aprobados cuentan como ingresos.

## 4. Lo que estas pruebas no cubren

- Facturación o comprobantes fiscales de Premium y destacados (no están implementados).
- Contracargos reales: el sandbox no siempre los simula. El código los trata como devoluciones.
