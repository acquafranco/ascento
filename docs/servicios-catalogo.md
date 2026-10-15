# Servicios: ofrecidos (catálogo) y contratados

## Dónde está

Una sola entrada en el menú: **Comercial → Servicios** (`App\Filament\Clusters\ServicesCluster`), con dos vistas en pestañas (en celular, un selector):

| Vista | Qué es | Dirección |
|---|---|---|
| **Servicios contratados** | Lo que tiene cada cliente: genera cobros y cubre visitas. Botón «Contratar servicio». | `/admin/servicios/contratados` |
| **Servicios ofrecidos** | El catálogo: lo que la empresa ofrece y su precio de referencia. Botón «Nuevo servicio ofrecido». | `/admin/servicios/ofrecidos` |

- Entrar a «Servicios» abre la primera vista a la que el usuario tiene acceso.
- Son registros distintos: una contratación nunca aparece en «Servicios ofrecidos». La columna «Contratos activos» de un servicio ofrecido lleva a «Servicios contratados» filtrado por ese servicio.
- Las direcciones anteriores (`/admin/maintenance-services/…` y `/admin/catalogo-servicios/…`) redirigen a las nuevas, con la misma subruta y filtros.
- En la ficha del cliente y del edificio sigue la pestaña «Servicios contratados» (acceso contextual), con las mismas acciones y el mismo formulario.

## Idea general

- **Catálogo** (`service_catalog_items`, Servicios → «Servicios ofrecidos»): lo que la empresa ofrece, con un precio de referencia. No genera cobros ni visitas por sí solo.
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

Desde la ficha del cliente (o del edificio) → «Servicios contratados» → «Agregar servicio», o desde Servicios → «Servicios contratados» → «Contratar servicio»:

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

**Superposición (aviso, no bloquea):** si el alcance ya tiene otra contratación activa que cubre visitas del mismo tipo (o una anterior al catálogo, sin tipo), el formulario la muestra antes de confirmar y permite continuar. No se avisa por equipos distintos del mismo edificio ni por «solo ascensores» frente a «solo montacargas». Las visitas no se duplican: cada remito se vincula a una sola contratación (`covering`) y la agenda pide una visita por edificio.

## Editar, pausar y finalizar

- **Editar:** recalcula con el alcance y los equipos actuales. Si el importe cambia (precio, modalidad, alcance, equipos activos o paradas cargadas), el formulario muestra el importe anterior, el nuevo y la causa, y exige marcar «Confirmo el nuevo importe»; el servidor también lo exige. Editar solo la descripción u otros datos no cambia el importe si los equipos no cambiaron. Los cobros ya generados no cambian.
- **Vencimiento de los cobros al editar (regla aprobada):** si cambia el día de vencimiento o el inicio, se ajustan **solo los cobros de período pendientes y sin pagos** de esa contratación (`ServiceBillingService::syncPendingCharges`):
  - con el nuevo día, cada uno vence ese día del mes de su período (misma regla que al emitirlo); si con esa fecha sigue vencido, sigue vencido;
  - si el inicio se corre a un mes posterior, los de períodos anteriores al nuevo inicio se anulan con motivo («La contratación ahora empieza el …»); no se borran.
  - Nunca cambian los importes, ni los cobros pagados, parcialmente pagados o anulados, ni el cobro de «Volver a cobrar». Editar otros datos no mueve vencimientos.
  - Antes de anular o reprogramar, cada cobro se vuelve a verificar con bloqueo: si entretanto recibió un pago o alguien lo anuló, no se toca (ni se pisa el motivo de esa anulación).
  - **Corregir un cobro antiguo que nació vencido** (emitido antes de esta regla): editar la contratación y cambiar el día de vencimiento; si se quiere conservar el mismo día, cambiarlo y volver a él en una segunda edición. Así se recalcula con la regla actual, solo sobre los cobros pendientes sin pagos de esa contratación, sin duplicar nada. Guardar sin cambiar el día no mueve vencimientos.
- **Cambios simultáneos:** se guarda el importe que el usuario vio. Si otro usuario cambia equipos o paradas mientras se completa el formulario, el servidor recalcula y no guarda: en el alta, muestra el importe nuevo y pide volver a guardar; al editar, borra la confirmación y pide confirmar el importe nuevo.
- **Contratos anteriores al catálogo:** al editarlos se muestran como «Mantenimiento» y «Precio acordado» con su importe; guardarlos no cambia el importe.
- **Pausar / Reactivar:** cambia el estado. Al reactivar no se cobran los meses en que estuvo pausado o finalizado. Regla de reactivación (decisión comercial: alternativa A, sin prorrateo ni cobros retroactivos):
  - El ciclo de cobro sigue contado desde la **fecha de inicio original**.
  - Se cobra el primer período que **empieza** en el mes de la reactivación o después. Si se reactiva a mitad de un período trimestral o semestral, ese período en curso no se cobra; el siguiente cobro es el del próximo ciclo. Ejemplo: trimestral desde enero, pausado en febrero y reactivado en mayo → próximo cobro en julio.
  - Un período ya cobrado nunca se repite.
  - **Primer cobro de una contratación (regla aprobada):** si al emitirse el día pactado de su mes ya pasó (por ejemplo, alta el 15 con día 10), vence ese día del mes siguiente; el período cobrado no cambia. No nace vencido.
  - Vencimiento: el día pactado del mes en que empieza el período. Si se reactiva en ese mes después del día pactado, el cobro nace con el vencimiento ya pasado y figura como **Vencida** (en Cobranzas y en los indicadores de deuda).
- **Finalizar:** estado «Finalizado» y fecha de fin hoy (o la anterior, si ya tenía una). Deja de generar cobros y conserva el historial, las visitas y los cobros.
- **Mismas acciones en todos lados:** Pausar, Reactivar (solo desde pausado), Finalizar y Volver a cobrar son las mismas en «Servicios contratados», en la ficha de la contratación y en la del cliente o edificio (`MaintenanceServiceResource::lifecycleActions`). Cada una exige poder gestionar la contratación. Una contratación finalizada se reactiva desde «Editar» (estado y fecha de fin).
- **Cobros de la contratación:** la ficha de la contratación tiene la pestaña «Cobros» (el mismo componente que la cuenta corriente del cliente), con sus cobros y el registro de pagos. Ahí no se cargan cobros manuales.
- **Volver a cobrar un pago único anulado:** si el cobro de un pago único se anuló y no tiene otro vigente, la acción «Volver a cobrar» crea un cobro nuevo vinculado a la contratación, por su importe acordado, con vencimiento el día pactado de este mes (o del siguiente, si ya pasó). El cobro anulado queda intacto. No aparece si el cobro está pendiente, vencido, parcialmente pagado o pagado, si la contratación está pausada, ni para servicios periódicos. El servidor lo verifica dentro de una transacción con bloqueo de la contratación (`ServiceBillingService::rebillOneTime`): un doble clic o dos solicitudes simultáneas no crean dos cobros, y la facturación automática no agrega otro.
- No se borra nada.

## Cambios del catálogo

El precio, el nombre y la modalidad se **copian** a la contratación al contratar. Cambiar o desactivar el ítem del catálogo no modifica contrataciones ni cobros existentes, ni los cobros que se generen después: esos siguen el importe de la contratación.

## Integraciones

- **Cobros:** sin cambios para lo existente. «Pago único» genera un solo cobro en toda la vida de la contratación (aunque se pause, se reactive o cambie su inicio). Finalizar la contratación no modifica su cobro. En la ficha y en los listados de contrataciones de pago único se muestra el estado de su cobro (Pendiente, Vencida, Parcialmente pagada, Pagada o Anulada, el mismo de Cobranzas) con enlace al cobro; es solo informativo: no cambia el estado de la contratación ni genera cobros. Si el cobro todavía no se generó, no se muestra ningún estado. No hay facturación automática nueva.
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
- Los técnicos no acceden. Sin permiso de Cobranzas, la sección «Servicios» no aparece ni se abre.
- Todo se filtra por empresa (`BelongsToCompany`), incluidos los equipos que cuenta el cálculo y las opciones de los formularios.

## Pendiente / límites conocidos

- No existe un tipo de equipo «camillero». Hoy se resuelve con un ítem aplicado a equipos específicos.
- La pestaña del edificio muestra las contrataciones de ese edificio. Las de «todo el cliente» se ven en la ficha del cliente.
- Un servicio de pago único queda activo hasta que alguien lo finaliza. No genera más cobros.
- Dos contrataciones de mantenimiento en el mismo edificio se avisan pero se permiten; cada visita se vincula a una sola (la del edificio y, entre varias, la de inicio más reciente).

- Los cobros manuales de Cobranzas siguen siendo libres: si alguien carga a mano un cobro por un pago único (en lugar de usar «Volver a cobrar»), el sistema no lo vincula ni lo controla.

Pruebas: `tests/Feature/Billing/ServiceCatalogTest.php`, `ServiceContractReviewTest.php`, `ServiceAmountConcurrencyTest.php`, `ServiceChargeStatusTest.php` y `ServicesSectionTest.php`.
