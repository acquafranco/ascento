# Stock, Servicios y Cobranzas — auditoría y diseño

Fecha: 2026-10-08. Base: `main` @ d917523.

## 1. Lo que hay hoy

- **Multiempresa**: cada modelo de negocio usa el trait `BelongsToCompany` (scope global por empresa, `company_id` asignado desde el usuario y no modificable). El panel `/admin` es solo para admins y SuperAdmin; los técnicos usan la app web.
- **Órdenes de trabajo** (`work_orders`): estados `pending → in_progress → completed | failed`, soft delete. Se completan por **tres caminos**:
  1. el técnico firma el remito (`DeliveryNoteController::store`, el más común);
  2. `WorkOrderService::finish()`;
  3. el admin cambia el estado en el panel (`EditWorkOrder`).
  No tienen ninguna relación con materiales.
- **Presupuestos** (`quotes`): `amount`, `status` = `pending | sent | approved | rejected`, cliente y edificio. El estado lo cambia el admin. No tienen relación con cobros. Están en los planes Profesional y Empresa.
- **Clientes / Edificios**: un edificio pertenece a un cliente. No existe ningún concepto de contrato, servicio ni cuenta corriente.
- **Mantenimientos** (`building_visits` + remitos): registran el trabajo mensual, no su cobro.
- No existe nada de stock, materiales, servicios ni cobranzas: **no hay nada que duplicar**.

## 2. Qué se reutiliza

`companies`, `clients`, `buildings`, `work_orders`, `quotes`, `users`, `BelongsToCompany`, `CompanyContext`, el panel Filament (recursos, relation managers, widgets) y el patrón de "servicio de dominio con transacción y lock" que ya usa `WorkOrderService`.

## 3. Tablas nuevas

| Tabla | Para qué |
|---|---|
| `stock_items` | Catálogo de materiales/repuestos de cada empresa (unidad, costo, stock actual, mínimo, activo). |
| `stock_movements` | Historial **inmutable**: entrada / salida / ajuste, con delta, saldo resultante, usuario, motivo y la orden de origen. |
| `work_order_materials` | Materiales usados en una orden (material, cantidad, costo al momento) y el movimiento que generó. |
| `maintenance_services` | Servicio/contrato de mantenimiento: cliente, edificio, importe, frecuencia, inicio/fin, estado, día de vencimiento. |
| `receivables` | Obligaciones de cobro (cuenta corriente): de un servicio (por período), de un presupuesto o manual. |
| `receivable_payments` | Pagos (totales o parciales) de cada obligación. |

## 4. Stock → Órdenes

- En la orden: sección **Materiales utilizados** (material + cantidad).
- **Descuento automático al completar**: lo dispara el evento del modelo `WorkOrder` cuando su estado pasa a `completed`, así funciona por cualquiera de los tres caminos.
- **Idempotente**: cada renglón guarda el movimiento que generó (`work_order_materials.stock_movement_id`, y `stock_movements.work_order_material_id` es UNIQUE). Se procesa con `lockForUpdate`. Si la orden se vuelve a guardar o a completar, los renglones ya descontados se saltean.
- Si se agrega un material a una orden **ya completada**, se descuenta en ese momento. Un renglón ya descontado no se edita ni se borra; las correcciones se hacen con un **ajuste**, que queda en el historial.
- **Stock insuficiente → se permite stock negativo, con advertencia.** El repuesto ya se colocó en el edificio; bloquear el cierre de la orden (que hace el técnico al firmar el remito en la obra) cortaría el trabajo real por un problema de datos de inventario. El material queda en negativo, marcado en rojo y con alerta de stock bajo, para que el admin cargue la entrada o un ajuste.

## 5. Servicios → Cobranzas

- Servicio activo + frecuencia (mensual, bimestral, trimestral, semestral, anual) → una obligación por período.
- **Sin duplicados**: índice UNIQUE (`maintenance_service_id`, `period_start`) + `firstOrCreate` dentro de una transacción.
- Se generan los períodos **hasta el actual** (nunca a futuro) y desde el más tardío entre el inicio del servicio y el mes en que se cargó. Así, cargar un servicio con fecha de inicio vieja no inventa años de deuda.
- Vencimiento: el "día de vencimiento de pago" del servicio, dentro del mes de cada período (de 1 a 28).
- Disparadores: al crear o activar un servicio, el botón "Generar cobros de servicios" y un comando programado diario.

## 6. Presupuestos → Cobranzas

- Acción **"Generar cobro"** en presupuestos **aprobados**: concepto, importe y vencimiento elegidos por el admin. **Nunca** se genera sola al ver o editar el presupuesto.
- Relación explícita `receivables.quote_id`. Solo se permite **una obligación no anulada por presupuesto**: se valida con lock sobre el presupuesto. Si se anula, se puede volver a generar.

## 7. Estados de una obligación

Guardados: `pending`, `partial`, `paid`, `void`. **Vencida** se calcula: `pending`/`partial` con vencimiento anterior a hoy (scope `overdue()`), así nunca queda desactualizada. Pagos: importe > 0 y ≤ saldo. Una obligación con pagos no se anula.

## 8. Fuera de alcance (deliberado)

Facturación electrónica, ARCA/AFIP, IVA, contabilidad, retenciones/percepciones, integración y conciliación bancaria. Tampoco se agregó: carga de materiales desde la app del técnico (por ahora la carga el admin en la orden), reversión automática de stock al reabrir una orden (se hace con un ajuste) ni límites por plan para estos módulos.
