# Catálogo de servicios y contrataciones

## Idea general

- **Catálogo** (`service_catalog_items`, Comercial → «Catálogo de servicios»): lo que la empresa ofrece, con un precio de referencia. No genera cobros ni visitas por sí solo.
- **Contratación** (`maintenance_services`, la tabla de siempre): un servicio contratado por un cliente, con su alcance, su precio y su vigencia. Es lo que factura (`ServiceBillingService`), cubre visitas (`MaintenanceService::covering`) y cuenta en la agenda y los indicadores.
- No hay tabla nueva de asignaciones: se reutiliza `maintenance_services` y se le agregan columnas. Así los cobros, visitas, remitos, la exportación y el borrado de empresas siguen funcionando igual.

## Catálogo

Campos:
- nombre (único por empresa), tipo, descripción, qué incluye, observaciones internas;
- modalidad de precio y precio de referencia, equipos que cuenta, frecuencia de cobro sugerida;
- «Disponible para contratar».

Tipos (`ServiceCatalogItem::CATEGORIES`): mantenimiento, inspección, guardia técnica, prueba de paracaídas, limpieza técnica, servicio extraordinario y otro. **Solo mantenimiento e inspección cubren visitas periódicas.**

Modalidades (`PRICING_MODES`):

| Modalidad | Importe |
|---|---|
| Importe fijo | el importe, por contratación (por ejemplo, por edificio) |
| Por equipo | precio × equipos activos alcanzados (todos, solo ascensores o solo montacargas) |
| Por parada | precio × paradas cargadas en la ficha de esos equipos; si falta alguna, no se adivina: se pide completarla |
| Precio acordado | el importe que se acuerde con el cliente |

- Desactivar un ítem lo saca de las opciones al contratar; las contrataciones existentes siguen igual.
- Se puede eliminar solo si nunca se contrató.

## Contratar

Desde la ficha del cliente (o del edificio) → «Servicios contratados» → «Agregar servicio», o desde Comercial → Servicios → Nuevo:

1. **Servicio:** del catálogo (precarga nombre, tipo, modalidad, precio y frecuencia de cobro, todo editable) o sin catálogo.
2. **Alcance:**
   - todo el cliente (sus edificios activos);
   - uno o varios edificios: se crea **una contratación por edificio**, cada una con sus equipos e importe, todo o nada;
   - algunos equipos de un edificio.
3. **Precio:** modalidad, equipos que cuenta y precio. Un resumen en vivo muestra cómo se calcula («4 equipos × $52.000 = $208.000») por edificio y el total.
4. **Cobro y vigencia:** frecuencia de cobro (incluye «Pago único»), día de vencimiento, estado, inicio y fin.

El importe **siempre se recalcula en el servidor** al guardar (`ServicePricing`), nunca se toma del navegador. Queda guardado:
- `unit_price`, `quantity`, `amount`;
- `pricing_detail`: explicación, líneas por edificio, equipos que cuenta, precio del catálogo en ese momento y fecha del cálculo.

**Validaciones:**
- el edificio tiene que ser del cliente y de la empresa;
- con «algunos equipos» hay que elegirlos;
- el importe tiene que ser mayor que cero;
- no se aceptan ítems de catálogo de otra empresa.

**Repetidos:** no se permite el mismo servicio (mismo ítem de catálogo o, sin catálogo, el mismo nombre) activo para el mismo cliente y alcance. Contratarlo para equipos distintos del mismo edificio sí se permite.

## Editar, pausar y finalizar

- **Editar:** recalcula con el alcance y los equipos actuales. Los cobros ya generados no cambian.
- **Pausar / Reactivar:** cambia el estado.
- **Finalizar:** estado «Finalizado» y fecha de fin hoy (o la anterior, si ya tenía una). Deja de generar cobros y conserva el historial, las visitas y los cobros.
- No se borra nada.

## Cambios del catálogo

El precio, el nombre y la modalidad se **copian** a la contratación al contratar. Cambiar o desactivar el ítem del catálogo no modifica contrataciones ni cobros existentes, ni los cobros que se generen después: esos siguen el importe de la contratación.

## Integraciones

- **Cobros:** sin cambios para lo existente. «Pago único» genera un solo cobro, el del período de inicio. No hay facturación automática nueva.
- **Visitas:** `covering()` solo vincula visitas a contrataciones de mantenimiento o inspección (o anteriores al catálogo, sin tipo). Una guardia o una limpieza no «cubren» visitas.
- **Frecuencias:** la frecuencia de cobro es independiente de la frecuencia de visitas (Edificios → «Frecuencia de visitas»). Contratar no crea visitas ni planes de visita.
- **Agenda:** un edificio sin técnico aparece como «falta asignar» solo si tiene un contrato de mantenimiento (o sin tipo).
- **Indicadores:** la cartera mensualizada excluye los servicios de pago único.
- **Presupuestos:** «Agregar un servicio del catálogo» suma un ítem con nombre, descripción y precio de referencia. La cantidad sale de los equipos del edificio si es por equipo o por parada. No crea contrataciones.
- **Exportación:** la hoja «Contratos» suma las columnas nuevas, y se agrega la hoja «Catálogo de servicios».
- **Borrado de empresa:** incluye `service_catalog_items`.

## Migración

`2027_01_03_100000_create_service_catalog`:
- crea `service_catalog_items`;
- agrega a `maintenance_services` las columnas `catalog_item_id`, `category`, `pricing_mode`, `unit_price`, `quantity` y `pricing_detail`, todas opcionales.

Solo agrega tablas y columnas: no modifica datos existentes. Las contrataciones anteriores quedan sin tipo (se tratan como mantenimiento) y sin modalidad (se muestran como «Precio acordado» con su importe de siempre).

## Permisos y aislamiento

- Catálogo y contrataciones usan el área **Cobranzas**:
  - «ver» permite consultar;
  - «gestionar» permite crear y cambiar.
- Los técnicos no acceden.
- Todo se filtra por empresa (`BelongsToCompany`), incluidos los equipos que cuenta el cálculo y las opciones de los formularios.

## Pendiente / límites conocidos

- No existe un tipo de equipo «camillero». Hoy se resuelve con un ítem aplicado a equipos específicos.
- La pestaña del edificio muestra las contrataciones de ese edificio. Las de «todo el cliente» se ven en la ficha del cliente.
- Un servicio de pago único queda activo hasta que alguien lo finaliza. No genera más cobros.
- No se detecta como repetida una contratación de mantenimiento del catálogo sobre un edificio que ya tiene un contrato anterior sin catálogo. Ambas son válidas, y la visita se vincula a una sola.

Pruebas: `tests/Feature/Billing/ServiceCatalogTest.php`.
