# Etapa 10: orientar las consultas por WhatsApp (propuesta)

**Estado:** enfoque A + B **aprobado** el 13/10/2026: asistente guiado en la web y app gratuita WhatsApp Business. La API oficial y cualquier servicio pago quedan para una etapa posterior, con una evaluación aparte. La fase 1 está implementada (sección 9). No hay integración con WhatsApp.

**Uso principal:** captar empresas, responder consultas comerciales y convertir interesados en demostraciones o pruebas gratuitas.

**Condiciones de la aprobación:**
- Datos reales de planes, precios, límites y funciones, sin prometer lo que el plan no incluye.
- A WhatsApp solo pasan el tema y lo que la persona escriba voluntariamente. Nunca contraseñas ni códigos.
- Las demostraciones las coordina el dueño a mano por WhatsApp; no hay agenda online.
- Las urgencias se orientan a los servicios de emergencia locales y a la empresa mantenedora, sin el 911 para todos los países y sin diagnosticar.
- A consorcios y vecinos se los orienta a la administración del edificio o a la empresa mantenedora.
- **Confirmado por el dueño el 13/10/2026:**
  - el número oficial de WhatsApp Business es **5491173728421** (+54 9 11 7372-8421) y ya está configurado en su app WhatsApp Business;
  - la atención es **todos los días, las 24 horas, incluidos fines de semana y feriados**.
  - Los dos son valores por defecto en `config/app.php` y se pueden reemplazar con `ASCENTO_WHATSAPP_NUMBER` y `ASCENTO_WHATSAPP_HOURS`.
- La web solo abre WhatsApp con el mensaje armado. No hay API ni mensajes automáticos dentro de WhatsApp Business.
- La pantalla del SuperAdmin es chica: respuestas rápidas para copiar y un aviso de cambios en los planes. No es un constructor de chatbots.

**Objetivo:** que quien consulta por WhatsApp sobre Ascento reciba respuestas correctas a lo frecuente y llegue a una persona solo cuando hace falta, con la consulta ya ordenada.

**Restricciones:**
- Sin servicios pagos.
- Sin suponer que un número se puede automatizar sin las autorizaciones de Meta.
- Respuestas basadas solo en funciones que existen hoy.

---

## 1. Punto de partida (lo que ya existe)

| Pieza | Qué hace hoy | Dónde |
|---|---|---|
| «Hablemos por WhatsApp» en la web | Formulario corto (nombre, empresa, motivo, mensaje) que abre WhatsApp con el texto armado. Lo envía la persona; no se guarda nada ni se usa ninguna API. | `resources/views/partials/whatsapp-contact.blade.php` |
| Número comercial | `ASCENTO_WHATSAPP_NUMBER` (por defecto `5491173728421`). | `config/app.php` |
| Preguntas frecuentes de la web | Respuestas que ya salen de la configuración real (plan de la prueba, desde qué plan está el portal). | `welcome.blade.php`, sección `#faq` |
| Conexión de Meta por empresa | Código de la Cloud API para los botones de órdenes a técnicos (OAuth, webhook con firma). **Está desactivado**: el formulario está comentado y las órdenes ya no mandan WhatsApp. | `WhatsAppController`, `WhatsAppWebhookController`, `WhatsAppService` |

La conexión por empresa es otra cosa: usa la cuenta de Meta de **cada cliente** para hablar con **sus** técnicos. No debe usarse para el número comercial de Ascento. Mezclarlas pondría credenciales de clientes al servicio de Ascento.

---

## 2. Opciones evaluadas

| | A. Asistente en la web antes de WhatsApp | B. App gratuita WhatsApp Business en el número comercial | C. Bot con la API de WhatsApp (Cloud API) |
|---|---|---|---|
| Costo | Ninguno | Ninguno (la app es gratuita) | Requiere verificar el negocio en Meta; las conversaciones pueden tener costo según la tarifa vigente de Meta (hay que consultarla, cambia) |
| Autorizaciones | Ninguna | Ninguna nueva: es el uso normal de la app | Cuenta de WhatsApp Business Platform, número registrado en la API, plantillas aprobadas, política de Meta |
| Automatiza el número | No | No: mensajes de bienvenida y ausencia, respuestas rápidas y etiquetas, que una persona usa | Sí |
| Riesgo | Bajo | Bajo | Medio: bloqueo del número por políticas, mantenimiento del webhook, datos personales en un servidor |
| Dónde ocurre | Antes de escribir | En el chat, con una persona | En el chat, automático |

**Recomendación: A + B ahora y C solo más adelante, con aprobación aparte.**
- A resuelve la mayoría de las dudas antes del chat y hace que las consultas lleguen clasificadas.
- B le da a la persona que atiende respuestas listas y siempre correctas.
- Ninguna de las dos automatiza el número ni cuesta dinero.

Antes de B hay que confirmar si el número comercial ya usa la app WhatsApp Business. Si es una cuenta personal, se migra a Business desde la misma app (gratis). Es una decisión tuya.

---

## 3. Flujo de conversación

```
Persona en ascento.online
  └─ «Hablemos» / «Consultas»
       └─ 1. ¿Sobre qué es tu consulta?  (árbol, sección 4)
            ├─ Respuesta corta + enlace a la sección de la web
            │    └─ ¿Te sirvió?
            │         ├─ Sí → «Probar gratis 30 días» / «Ver planes» / cerrar
            │         └─ No / quiero hablar con alguien → paso 2
            ├─ Urgencia con un ascensor → aviso de emergencia (sección 5.1), sin pasar a ventas
            └─ Soy cliente de una empresa que usa Ascento → derivar a su empresa (5.2)
       └─ 2. Datos mínimos: nombre, empresa y, opcional, cantidad de edificios y técnicos
            └─ Se abre WhatsApp con el mensaje armado:
                 «Hola, equipo de Ascento. Tema: Planes · Nombre: … · Empresa: …
                  Edificios: 30–70 · Técnicos: 4–10 · Ya leí: Qué incluye cada plan»
Persona en WhatsApp (número comercial, app Business)
  └─ Mensaje de bienvenida automático de la app (primer contacto)
       └─ Quien atiende identifica el tema (viene en el mensaje) y responde
          con la respuesta rápida correspondiente (/planes, /demo, /portal…)
          o deriva según los criterios de la sección 6.
```

Si alguien escribe directo al WhatsApp sin pasar por la web, el mensaje de bienvenida le ofrece el mismo menú en texto y el enlace al asistente. Quien atiende responde con las mismas respuestas rápidas.

**Mensaje de bienvenida propuesto (app Business):**

> ¡Hola! Gracias por escribir a Ascento, el sistema para empresas de mantenimiento de ascensores. Contanos en qué te ayudamos: planes, demostración, portal de clientes, mantenimientos, remitos o QR. Mientras tanto, las respuestas a las dudas más comunes están en ascento.online/#faq.
> Ascento no es un servicio de emergencias ni de mantenimiento. Si hay peligro inmediato con un ascensor, comunicate con los servicios de emergencia de tu zona y con la empresa responsable del mantenimiento.
> Ascento nunca te va a pedir contraseñas ni códigos por WhatsApp.

**Mensaje de ausencia:** no corresponde, porque la atención es continua. Ningún mensaje dice que estamos fuera de horario ni promete un plazo de respuesta.

---

## 4. Árbol de opciones y respuestas

Los textos definitivos de la fase 1 están en `app/Support/Sales/SalesAssistant.php`. Si difieren de esta sección, valen esos.

Cada respuesta indica **de dónde sale el dato**. Los valores entre `{llaves}` se completan desde la configuración real al mostrar la respuesta (sección 8); nunca se escriben a mano. Los valores entre paréntesis son los de hoy, solo como ejemplo.

### 4.1 Qué es Ascento
> Ascento es un sistema para empresas de mantenimiento de ascensores. Reúne clientes, edificios, ascensores, técnicos, mantenimientos, órdenes de trabajo y remitos en un solo lugar. Funciona en la computadora y en el celular, sin instalar nada.

Fuente: funciones esenciales (`PlanFeature::structural()`).

### 4.2 Cómo funciona
> 1) Creás tu cuenta y cargás tu empresa. 2) Sumás edificios, ascensores, clientes y técnicos. 3) Cada técnico ve en el celular lo suyo: visitas del mes y órdenes. Cierra cada trabajo con el remito firmado. 4) Desde el panel ves qué falta, qué se hizo y qué necesita atención.

Fuente: «Cómo funciona» de la web y la guía «Poner en marcha tu empresa» (`HelpGuides`).

### 4.3 Qué incluye cada plan
> Hay {cantidad} planes: {Inicial} (${precio}), {Profesional} (${precio}) y {Empresa} (${precio}) por mes.
> - **Todos incluyen:** {lista de funciones presentes en todos los planes}, exportación de datos y avisos en el celular.
> - **Desde {plan}:** {funciones cuyo plan más barato es ese}.
> - **Límites:** {edificios / clientes / técnicos / informes por plan}.
> - **Prueba gratis:** {30} días con todo el plan {Empresa}, sin tarjeta. La comparativa completa está en ascento.online/#planes.

Fuente: `SubscriptionPlan::offered()`, `comparisonRows()`, `cheapestWith()`, `TRIAL_PLAN`. Hoy: Inicial $69.000, Profesional $119.000, Empresa $169.000. Los precios **no** se escriben en la respuesta guardada: se leen al armarla.

### 4.4 Cómo solicitar una demostración
> Podés probarlo solo, gratis por {30} días, desde ascento.online («Probar gratis»). Si preferís que te lo mostremos, decinos cuántos edificios y técnicos manejás y qué te interesa ver primero (agenda y remitos, portal para clientes o cobranzas). Coordinamos una demostración con una persona del equipo.

Hoy no hay agenda online de demostraciones: siempre la coordina una persona, así que **pasa a una persona** (sección 6) con los datos ya pedidos.

### 4.5 Portal de clientes
> {Desde el plan Profesional}, cada consorcio o administración entra al portal con su usuario. Ve solo los edificios que le habilitás y lo que marcás como compartido: remitos, reportes, presupuestos y documentos. Lo invitás por correo desde la ficha del cliente («Invitar al portal»).

Fuente: `cheapestWith(ClientPortal)`, la guía `portal` y la pregunta frecuente del portal.

### 4.6 Mantenimientos
> Cada edificio tiene su frecuencia de visitas y su técnico asignado. La agenda del mes muestra qué está hecho, qué no se pudo hacer, qué está pendiente y qué venció. Una visita cuenta como hecha solo con el remito firmado.

Fuente: guías `visits`, `agenda`, `assignments`.

### 4.7 Remitos
> El técnico cierra el trabajo desde el celular («Hacer remito y finalizar» en una orden, o el remito de la visita), con su firma y, si querés, la del cliente. El remito queda numerado y lo compartís en PDF o con un enlace por WhatsApp o correo. Está en todos los planes.

Fuente: guía `delivery_notes`; `DigitalDeliveryNotes` es esencial desde el #60.

### 4.8 QR público
> Cada edificio puede tener un QR. Quien lo escanea ve, sin cuenta, solo lo que la empresa eligió publicar: estado informado de cada ascensor, último mantenimiento e inspección registrados, pruebas de paracaídas y componentes en reparación. No muestra datos del cliente ni montos. Es información administrativa, no una certificación de seguridad.

Fuente: pregunta frecuente del QR y Etapa 7. Disponible en {planes con `PublicQr`}.

### 4.9 Hablar con una persona
Pasa directo a una persona (sección 6) con los datos mínimos.

### 4.10 Temas agregados (no estaban en la lista original, pero son necesarios)
| Tema | Respuesta | Motivo |
|---|---|---|
| **Urgencia con un ascensor** | Sección 5.1 | Alguien atrapado o un equipo peligroso no puede esperar una respuesta comercial. |
| **Soy consorcio, administración o vecino** | Sección 5.2 | Quien escanea un QR puede escribirle a Ascento creyendo que es su empresa de mantenimiento. |
| **Ya uso Ascento y tengo un problema** | Remitir a «Ayuda» dentro de la app (Etapa 9.3) y a {support_email}. Si no se resuelve, pasa a una persona. | Soporte, no ventas. |
| **Mis datos / dar de baja** | Remitir a ascento.online/legal/eliminacion-de-datos y a «Exportar datos». | Pedido legal: lo atiende una persona. |

---

## 5. Consultas que el sistema no puede resolver

### 5.1 Urgencias
Si el mensaje habla de una persona atrapada, un ascensor que no frena o un accidente, la respuesta es **siempre** la misma y no se intenta vender nada:
> Ascento no es un servicio de emergencias ni de mantenimiento, y no puede ayudarte con una falla desde acá. Si hay peligro inmediato, por ejemplo una persona atrapada o herida, comunicate con los servicios de emergencia de tu zona. Avisá también a la empresa responsable del mantenimiento del ascensor o a la administración del edificio.

No se da un número de emergencias fijo, porque depende del país.

Quien atiende no promete asistencia técnica ni da indicaciones sobre el equipo.

### 5.2 Consultas de un cliente final
> Ascento no tiene la información de tu edificio para compartirla por acá. La maneja tu empresa de mantenimiento. Comunicate con ella: si te habilitó el portal, también la ves en ascento.online/portal.

Nunca se confirma ni se niega por WhatsApp si un edificio, una empresa o una persona usa Ascento. Eso es información de los clientes.

### 5.3 Preguntas fuera del árbol
1. Si la respuesta existe en las preguntas frecuentes, en la ayuda o en la comparativa, se responde con ese texto.
2. Si no existe: «No tengo esa respuesta confirmada; te paso con una persona del equipo». **No se improvisa** ni se promete una función que no existe o que «está por salir».
3. La pregunta se etiqueta «Sin respuesta» (etiquetas de la app Business). Una vez por mes se revisan esas preguntas: o se agrega una respuesta basada en algo real, o se registra como pedido de producto.

---

## 6. Criterios para pasar a una persona

Pasa a una persona (en la app Business se etiqueta «Persona») cuando:

1. **La persona lo pide**, en cualquier punto.
2. **Pide una demostración guiada** (4.4).
3. **Hay una negociación comercial:** descuentos, precio especial, facturación, medios de pago o condiciones fuera de lo publicado.
4. **Supera los límites del plan más grande:** más de {300} edificios, {420} clientes o {25} técnicos (`PlanLimit` de Empresa).
5. **Quiere migrar datos** de otro sistema o de planillas.
6. **Es cliente y tiene un problema** que la ayuda de la app no resolvió, o un cobro que reclamar.
7. **Pedidos legales o de datos personales:** baja, eliminación o acceso.
8. **Hay una queja.**
9. **Una pregunta quedó sin respuesta** (5.3) o la persona dijo dos veces que la respuesta no le sirvió.

**No pasa a ventas:** urgencias (5.1) y consultas de clientes finales (5.2), que tienen respuesta fija.

**Datos mínimos al pasar a una persona** (ya vienen en el mensaje armado de la web): nombre, empresa, tema, rango de edificios y técnicos, y lo que ya leyó. No se piden DNI, contraseñas, datos de tarjeta ni datos de terceros.

---

## 7. Protección frente a mensajes abusivos

**En la web (opción A):**
- **El asistente no tiene servidor:** las respuestas viajan con la página y el mensaje lo envía la persona desde su WhatsApp. No hay un endpoint que se pueda saturar ni texto que se guarde. Es el mismo criterio del formulario actual.
- **Topes que ya existen y se mantienen:** nombre 80 caracteres, empresa 100, mensaje 600, y el texto solo se usa codificado en el enlace (`encodeURIComponent`).
- **Si en el futuro se guarda algo** (por ejemplo, contar consultas por tema), requiere aprobación. Debe ser anónimo, con `throttle` por IP y sin texto libre.

**En WhatsApp (opción B, con una persona):**
- **Spam o insultos:** una sola respuesta estándar («Podemos ayudarte con consultas sobre Ascento»). Si sigue, se usan **Bloquear** y **Denunciar** de la app.
- **Anti-estafa:**
  - Ascento nunca pide contraseñas, códigos de verificación ni pagos por WhatsApp; el mensaje de bienvenida lo avisa.
  - Solo se envían enlaces de `ascento.online`.
  - Nunca se abren archivos o enlaces que mande un desconocido.
- **Pedidos de datos de clientes** («pasame el remito del edificio X»): respuesta fija de 5.2. No se verifica identidad por WhatsApp ni se comparte información.
- **Ingeniería social** («soy el dueño de la empresa X, reseteame la contraseña»): se remite a «¿Olvidaste tu contraseña?» en el login. El restablecimiento lo hace siempre el sistema, nunca una persona por chat.

**Si se llega a la opción C (bot):** límite de mensajes por número y por minuto, silencio ante mensajes repetidos, validación de firma del webhook (ya existe en `WhatsAppWebhookController::hasValidSignature`) y nada de texto de mensajes en los logs (criterio vigente desde la auditoría previa a producción).

---

## 8. Cómo mantener las respuestas al día cuando cambien los planes

El problema es que la app Business guarda **copias de texto** de las respuestas rápidas, y esas copias no se actualizan solas.

**Propuesta: una sola fuente, con aviso cuando quede vieja.**

1. **Fuente única en el código:** una clase `WhatsAppAnswers` (o una ampliación de `FeatureCatalog`) arma cada respuesta de la sección 4.
   - Lee precios, límites y desde qué plan está cada función de `SubscriptionPlan`, `cheapestWith()` y `comparisonRows()`.
   - Lee el plan de la prueba de `TRIAL_PLAN` y el correo de soporte de `config('app.support_email')`.
   - Es lo mismo que ya hacen las preguntas frecuentes y la comparativa de la web.
   - El plazo de la prueba hoy está fijo en `RegisteredUserController` (`addDays(30)`). Al implementar conviene pasarlo a una constante, para que la respuesta y el registro usen el mismo valor (no cambia el plazo).
2. **El asistente web (A) usa esa clase:** siempre muestra lo vigente, sin copias.
3. **Una pantalla para el SuperAdmin** («Respuestas para WhatsApp») muestra cada respuesta rápida lista para copiar en la app Business, con su atajo (`/planes`, `/demo`…).
   - Indica la fecha del último cambio de planes. Esa fecha ya existe en el historial `plan_feature_changes` y en `updated_at` de los planes.
   - Muestra el aviso «Los planes cambiaron después de tu última copia» hasta que se marque «Copiadas».
4. **Pruebas automáticas**, como `LandingContentTest`, que fallan si:
   - una respuesta nombra una función que ningún plan incluye;
   - dice «desde Profesional» cuando el plan más barato es otro;
   - menciona un precio o un límite escrito a mano.
5. **Revisión mensual** de las consultas etiquetadas «Sin respuesta» (5.3).

---

## 9. Fases (aprobadas el 13/10/2026)

Cada fase va en su propia rama y con su propio PR. No hay migraciones, dependencias ni servicios pagos.

### Fase 1: asistente en la web (rama `etapa-10/asistente-web`, **lista**)

**Qué cambia para el visitante**

El botón «Hablemos» (inicio, ingreso y registro) abre un asistente en lugar del formulario directo:
1. **Temas:**
   - arriba de todo, «¿Hay una urgencia con un ascensor?»;
   - después, los temas comerciales;
   - al final, «Otras consultas» (vecinos o administración).
2. **Respuesta** del tema, con datos reales y botones «Probar gratis {días} días», «Ver los planes» o «Escribir por WhatsApp», según el tema.
3. **Datos para WhatsApp:**
   - obligatorios: nombre y empresa;
   - opcionales: rango de edificios (sale de los topes de los planes), correo y mensaje.
   - Se abre WhatsApp con «Tema», lo que escribió la persona y nada más.
4. **Urgencias y vecinos:** respuesta fija, **sin** botón a WhatsApp ni enlaces comerciales.

**Archivos**

| Archivo | Cambio |
|---|---|
| `app/Support/Sales/SalesAssistant.php` (nuevo) | Temas y respuestas. Toman los planes de `comparisonRows()`, los límites de `PlanLimit::planLabel()`, el plan de cada función de `cheapestWith()`/`allowsAll()` y la prueba de `TRIAL_PLAN`/`TRIAL_DAYS`. Un tema cuya función no está en ningún plan no se ofrece. |
| `resources/views/partials/whatsapp-contact.blade.php` | El formulario pasa a ser el asistente: temas, respuestas escritas en el servidor y escapadas por Blade, y los datos. Sin dependencias. |
| `app/Models/SubscriptionPlan.php` | Constante `TRIAL_DAYS = 30` (no cambia el plazo). |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | El registro usa `TRIAL_DAYS` en vez de `30` escrito a mano. |
| `config/app.php`, `docs/operacion-produccion.md`, `.env.example` | Número confirmado y `ASCENTO_WHATSAPP_HOURS`, por defecto «todos los días, las 24 horas, incluidos fines de semana y feriados». Vacío lo oculta. |
| `tests/Feature/Public/SalesAssistantTest.php` (nuevo), `WhatsAppContactTest.php` | Pruebas. |

**Privacidad, autorización y abuso (revisado)**
- **Sin endpoint ni almacenamiento.** Las respuestas viajan con la página y el enlace lo arma el navegador, así que no hay nada que saturar ni datos que se guarden. Una prueba confirma que no se agregan rutas.
- **Sin autorización que revisar:** es contenido público, el mismo para todos, y no lee datos de ninguna empresa (solo los planes ofrecidos, que ya publica la página).
- **Entradas:**
  - topes de 80, 100, 120 y 600 caracteres;
  - se quitan los caracteres de control;
  - el texto va solo dentro del enlace (`encodeURIComponent`);
  - el rango de edificios se acepta solo si es una de las opciones;
  - el tema sale del servidor, no de lo que se escriba.
- **XSS:** las respuestas las escribe el servidor y Blade las escapa. Lo que escribe la persona nunca se inserta como HTML.
- **No se piden** contraseñas, códigos ni pagos. El asistente avisa dos veces que no se escriban.
- **Sin número configurado:** no hay botón a WhatsApp y se ofrece el correo de soporte.
- **Rendimiento:** las respuestas se calculan una vez por request (`once`), con la misma consulta de planes que ya hace la página.

### Fase 2: pantalla del SuperAdmin «Respuestas para WhatsApp»

- **Qué hace:**
  - Lista las respuestas rápidas (atajo y texto plano) que genera `SalesAssistant`, cada una con un botón «Copiar».
  - Muestra el aviso «Los datos de los planes cambiaron desde la última actualización» cuando cambian los precios, los límites o las funciones desde la última vez que se marcó «Ya las actualicé en WhatsApp».
  - Para detectar el cambio guarda una huella de los datos de los planes.
- **Archivos previstos:**
  - nueva página Filament solo para el SuperAdmin;
  - `SalesAssistant::quickReplies()` y `fingerprint()`;
  - guardar la huella en la tabla de configuración existente o en un archivo de `storage` (se confirma al implementar, sin migración si es posible);
  - pruebas: solo la ve el SuperAdmin, el aviso aparece al cambiar un plan y desaparece al marcar.
- **Fuera de alcance:** editar respuestas desde la pantalla o armar árboles. Es un constructor de chatbots y quedó excluido.

### Fase 3: configuración manual de la app Business (la hace el dueño)

El número y la app WhatsApp Business ya están confirmados (13/10/2026). El dueño configura en el teléfono:
- el mensaje de bienvenida de la sección 3 (sin mensaje de ausencia, porque la atención es continua);
- las respuestas rápidas de la fase 2;
- las etiquetas «Ventas», «Demostración», «Soporte», «Persona» y «Sin respuesta».

El sistema no hace ninguna de estas tareas.

---

## 10. Decisiones pendientes

1. ~~Número comercial y WhatsApp Business~~: confirmados el 13/10/2026 (5491173728421, ya en la app Business).
2. ~~Horario de atención~~: confirmado (todos los días, las 24 horas, incluidos fines de semana y feriados).
3. **Nombre y empresa obligatorios** antes de abrir WhatsApp. Se mantienen como en el formulario anterior porque son el mínimo para una consulta de empresa. Si preferís que todo sea opcional, es un cambio chico.
