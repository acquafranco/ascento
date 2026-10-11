# Validación comercial de Ascento

Guía para hablar con empresas de mantenimiento de ascensores, mostrar el producto **tal como está hoy** y registrar lo que dicen de forma comparable.

Este documento no contiene testimonios, métricas de uso ni resultados de clientes, porque todavía no existen. Cualquier número que se use en una conversación tiene que salir de una prueba real y quedar anotado de dónde salió.

---

## 1. Propuesta de valor

> **Ascento ordena el trabajo diario de una empresa de mantenimiento de ascensores.** Muestra qué edificio toca este mes, quién lo tiene, qué se hizo y qué quedó pendiente. Los remitos se firman en el celular y el legajo de cada equipo queda en un solo lugar. El cliente ve lo que la empresa decide compartir.

### Problemas que resuelve (cada uno es una función que existe)

| Problema habitual (hipótesis a validar) | Qué hace Ascento hoy |
|---|---|
| "No sé qué edificios faltan visitar este mes." | **Agenda mensual** por edificio y técnico. Muestra hecho, no realizado, pendiente y vencido. Nada se marca hecho por abrir una pantalla: solo cuenta el remito firmado. |
| "El técnico fue pero no pudo hacer el trabajo y nadie se enteró." | El remito puede firmarse como **"no realizado"**. No cuenta como hecho y aparece en el **Centro de atención** para generar una orden. |
| "Los remitos en papel se pierden o llegan tarde." | **Remito digital firmado** en el celular, con firma del técnico y opcionalmente del cliente. Queda numerado y asociado a la visita o a la orden. |
| "Los reclamos se pierden entre WhatsApp y llamadas." | **Órdenes de trabajo** asignadas a técnicos, con estado, materiales usados y remito de cierre. |
| "No sé qué ascensores se rompen seguido." | **Legajo e historial de cada ascensor**: reportes, órdenes, materiales y documentos. El análisis de fallas por componente está en el plan Profesional y superiores. |
| "El consorcio pide comprobantes y los mando uno por uno." | **Portal del cliente**: cada administración entra con su usuario y ve solo los edificios autorizados y lo que la empresa marcó como compartido (remitos, reportes con fotos, presupuestos, documentos). |
| "No sé cuánto me deben ni de qué." | **Servicios y cobranzas**: abonos con frecuencia mensual, bimestral, trimestral, semestral o anual, cuotas generadas solas y pagos registrados. |
| "No sé qué repuestos tengo." | **Stock** con entradas, salidas, materiales descontados al cerrar una orden y aviso de stock bajo. |
| "Si dejo de usar el sistema, ¿me quedo sin mis datos?" | **Exportación de datos** en todos los planes: un ZIP con un Excel por tema, fotos y documentos, con historial de quién lo descargó. |

---

## 2. Perfil objetivo (hipótesis, no dato)

- Empresa de mantenimiento de ascensores, preferentemente de **2 a 25 técnicos** y **10 a 300 edificios** (el rango que cubren los límites de los planes).
- Hoy trabaja con papel, planillas o WhatsApp, o con un sistema genérico que no entiende de ascensores.
- Quien decide suele ser el dueño o el encargado de operaciones. El técnico es el usuario diario y tiene que poder usarlo desde el celular.
- Atiende consorcios o administraciones que piden comprobantes.

**A validar en las entrevistas:** si el dolor principal es la operación (agenda y remitos), la relación con el cliente (comprobantes y portal) o la plata (cobranzas). El orden de la demo depende de eso.

---

## 3. Planes tal como están implementados

Fuente: `database/seeders/SubscriptionPlanSeeder.php` y `app/Enums/PlanFeature.php`. Los precios son los cargados hoy en el sistema; antes de citarlos, confirmarlos en "Mi suscripción". Este documento no los cambia.

| | Inicial | Profesional | Empresa |
|---|---|---|---|
| Precio mensual cargado (ARS) | 69.000 | 119.000 | 169.000 |
| Edificios / clientes / técnicos | 20 / 50 / 3 | 70 / 150 / 10 | 300 / 420 / 25 |
| Reportes por mes | 15 | Sin límite | Sin límite |
| Clientes, edificios, técnicos, mantenimientos, inspecciones, órdenes, remitos firmados, reportes, mapa | ✓ | ✓ | ✓ |
| Stock, servicios y cobranzas | ✓ | ✓ | ✓ |
| Agenda, centro de atención básico, legajo del ascensor, indicadores básicos | ✓ | ✓ | ✓ |
| Exportación de datos | ✓ | ✓ | ✓ |
| Presupuestos numerados con envío por correo (PDF adjunto) y enlace seguro con vencimiento; remitos digitales (PDF y link para WhatsApp o email) | — | ✓ | ✓ |
| Portal para clientes (con invitación por correo y avisos al cliente) | — | ✓ | ✓ |
| Video en reportes (uno por reporte, validado en el servidor) | — | ✓ | ✓ |
| Avisos en tiempo real dentro de Ascento y push en el celular | ✓ | ✓ | ✓ |
| Centro de atención avanzado, historial avanzado, análisis de fallas, indicadores de empresa | — | ✓ | ✓ |
| Indicadores avanzados (año contra año, cartera), alertas avanzadas | — | — | ✓ |

- **Prueba gratis:** 30 días con las funciones del plan **Profesional**.
- El portal para clientes es de Profesional y Empresa. En una demo con una empresa que evalúa el plan Inicial, aclararlo antes de mostrarlo.

---

## 4. Cómo presentarlo sin prometer lo que no existe

**Se puede mostrar y afirmar:** todo lo de la tabla anterior, en el plan que corresponda.

**No existe hoy. No prometerlo ni insinuarlo:**

- Chat con el cliente o entre técnicos.
- Pagos online del cliente ni cobro desde el portal. Mercado Pago se usa solo para la suscripción de la empresa a Ascento.
- Facturación electrónica (AFIP/ARCA) e integración con sistemas contables.
- Modo sin conexión: la app instalable no guarda páginas. Si se corta la señal, el formulario avisa y no se pierde lo escrito, pero hay que enviarlo con conexión.
- Seguimiento GPS de técnicos.
- Avisos automáticos al cliente cuando termina una intervención. El cliente recibe un aviso (en el portal y por correo) solo cuando la empresa le comparte algo. No hay avisos por WhatsApp.
- Restaurar la cuenta desde la exportación. La exportación es una copia de los datos de negocio, no un backup del servidor.

**Frases seguras:**
- "Esto lo hace hoy." Mostrarlo en vivo.
- "Eso no lo hace. ¿Qué tan importante es para ustedes?" Anotarlo.
- Nunca decir "lo estamos por sacar" ni dar fechas.

---

## 5. Entrevista

### Las 5 preguntas centrales (hacerlas siempre, en este orden)

1. **¿Cómo saben hoy qué edificios faltan visitar este mes?** Pedir que lo muestren: planilla, cuaderno, WhatsApp.
2. **La última vez que un técnico no pudo hacer un mantenimiento, ¿cómo se enteró la oficina y qué pasó después?**
3. **¿Qué les pide el consorcio o la administración como comprobante del trabajo, y cómo se lo mandan?**
4. **¿Cuánto tiempo por semana les lleva armar remitos, presupuestos y el control de cobranzas?** Que den un número aproximado y quién lo hace.
5. **Si mañana tuvieran que dejar de usar lo que usan hoy, ¿qué perderían?**

### Preguntas de descubrimiento (según cómo venga la charla)

- ¿Cuántos técnicos, edificios y equipos tienen? ¿Cuántos hidráulicos y montacargas?
- ¿Los técnicos tienen celular de la empresa? ¿Tienen datos móviles en las salas de máquinas?
- ¿Quién asigna los edificios a cada técnico? ¿Cada cuánto cambia?
- ¿Las inspecciones las hace otra persona? ¿Llevan un registro aparte?
- ¿Cómo registran los repuestos que usa cada técnico?
- ¿Cobran el abono por mes, por trimestre, por año? ¿Cómo controlan quién debe?
- ¿Usan algún sistema hoy? ¿Qué es lo que más les molesta de él?
- ¿Quién decidiría contratar algo así? ¿Quién más tendría que estar de acuerdo?
- ¿Cuánto pagan hoy por herramientas de gestión, si pagan algo?
- ¿Qué tendría que hacer un sistema para que lo usen todos los días?

Reglas: preguntar por hechos pasados ("la última vez…"), no por intenciones ("¿usarían…?"). No mostrar el producto hasta terminar las 5 preguntas.

---

## 6. Cómo registrar y comparar respuestas

Una fila por entrevista en una planilla compartida, el mismo día. Columnas:

| Campo | Ejemplo de formato |
|---|---|
| Fecha, entrevistador | 2026-10-20, F. A. |
| Empresa (alias, sin datos personales si no autorizan) | "Empresa 03 – zona norte" |
| Técnicos / edificios / equipos | 6 / 45 / 70 |
| Cómo controlan la agenda hoy (P1) | Texto corto, con sus palabras |
| Incidente de "no realizado" (P2) | Texto corto |
| Comprobante que pide el cliente (P3) | Remito papel / foto por WhatsApp / PDF / nada |
| Horas por semana en papeles (P4) | Número que dieron, y quién |
| Qué perderían (P5) | Texto corto |
| Dolor principal | Operación / cliente / cobranzas / stock / otro |
| Funciones que pidieron y no existen | Lista literal |
| Señal de compromiso | Ninguna / pidió demo / pidió prueba / cargó datos / pagó |
| Próximo paso acordado y fecha | — |

**Para comparar** (con 5 o más entrevistas, no antes):
- Contar cuántas empresas mencionan cada dolor sin que se les sugiera.
- Agrupar las "funciones que no existen" y contar repeticiones.
- Ordenar por señal de compromiso. Una prueba con datos reales vale más que un "me encanta".
- Separar lo que dijeron de lo que interpretó el entrevistador.

---

## 7. Demo de 10 minutos

**Preparación (en una base local o de prueba, nunca en producción):**

```bash
php artisan migrate
php artisan db:seed --class=SubscriptionPlanSeeder
php artisan db:seed --class=DemoValidationSeeder   # no corre en producción
```

Las cuentas usan la contraseña `demo-ascento` y el dominio `.test`. La lista aparece al correr el seeder. Abrir tres ventanas: el admin (`admin@demo-norte.test`) en la computadora, el técnico (`carla@demo-norte.test`) en el celular o en vista móvil, y el portal (`admin@adm-rivadavia.test`).

| Min. | Qué mostrar | Qué decir (sin prometer de más) |
|---|---|---|
| 0–1 | Contexto | "Les muestro cómo quedaría un mes de trabajo de una empresa como la suya. Los datos son de ejemplo." |
| 1–3 | **Agenda** (admin) | Torre Libertador hecha, Edificio Juncal "no realizado" con su motivo, Plaza Belgrano y Galería Cabildo pendientes, no vencidos. "Nada se marca hecho por mirar: solo con el remito firmado." |
| 3–5 | **Técnico en el celular** (Carla) | Su lista con solo sus edificios. Firma el remito de Plaza Belgrano y en la agenda del admin pasa a "Hecho". |
| 5–6 | **Centro de atención** | La visita no realizada y el stock bajo (pulsador de cabina). |
| 6–7 | **Legajo del ascensor** | Torre Libertador, Ascensor 1: habilitación con vencimiento, plano interno y reportes. |
| 7–9 | **Portal del cliente** | La administración ve sus dos edificios y solo lo compartido. Compartir el reporte interno desde el panel y mostrar que aparece. "El cliente no ve nada que ustedes no compartan." |
| 9–10 | **Exportar datos** | Generar la exportación y mostrar el historial. "Sus datos son suyos: los descargan cuando quieran." Cerrar con la pregunta: "¿Qué de esto resolvería algo que hoy les cuesta?" |

Si el entrevistado mostró en la P4 que su dolor son las cobranzas, cambiar el bloque 6–7 por **Servicios y cobranzas**: contratos mensual, trimestral, semestral y anual, cuotas y el pago registrado.

---

## 8. Qué no hacer

- No inventar ni sugerir clientes, cantidades de usuarios ni resultados.
- No mostrar datos reales de otra empresa en una demo.
- No crear cuentas de prueba en producción para una demo. Usar el seeder en un entorno local o de prueba.
- No ofrecer descuentos ni cambios de plan que no estén en el sistema.
