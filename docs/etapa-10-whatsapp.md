# Etapa 10: orientar las consultas por WhatsApp (propuesta)

**Estado:** propuesta para aprobar. No se implementó nada y no hay integración con WhatsApp.

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
> Si tenés una urgencia con un ascensor, comunicate con tu empresa de mantenimiento o con emergencias (911): Ascento no atiende ascensores.
> Ascento nunca te va a pedir contraseñas ni códigos por WhatsApp.

**Mensaje de ausencia (fuera de horario):** igual, más «Te respondemos el próximo día hábil». El horario no está definido hoy: es una decisión tuya.

---

## 4. Árbol de opciones y respuestas

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
> Ascento es un sistema de gestión y no atiende ascensores. Si hay una persona atrapada o en peligro, llamá al 911 o a bomberos, y avisá a la empresa de mantenimiento del edificio.

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

## 9. Plan de implementación (solo si se aprueba)

| Fase | Qué | Costo | Requiere |
|---|---|---|---|
| 1 | `WhatsAppAnswers` y pruebas; el formulario «Hablemos» pasa a ser un asistente con el árbol de la sección 4 y arma el mensaje con el tema y los rangos | Ninguno | Tu aprobación de los textos |
| 2 | Pantalla del SuperAdmin con las respuestas rápidas y el aviso de cambios de planes | Ninguno | Fase 1 |
| 3 | Configuración manual de la app Business: bienvenida, ausencia, respuestas rápidas y etiquetas «Ventas», «Soporte», «Persona», «Sin respuesta» | Ninguno | Acceso al teléfono del número comercial: lo hace una persona, no el sistema |
| 4 (opcional) | Bot con Cloud API | A estudiar | Aprobación aparte, verificación del negocio en Meta, tarifas vigentes, número dedicado y revisión de seguridad |

Sin migraciones en las fases 1 a 3. La fase 2 podría registrar la fecha de «Copiadas» en `settings` existentes; se confirma al implementar.

---

## 10. Decisiones que necesito de vos

1. **Aprobar el enfoque A + B** y descartar por ahora el bot (C).
2. **El número comercial:** confirmar que `5491173728421` sigue siendo el número y si ya usa la app WhatsApp Business.
3. **Horario de atención** para el mensaje de ausencia.
4. **Textos:** aprobar los de la sección 4 y los de urgencia y cliente final (5.1 y 5.2).
5. **Demostraciones:** quién las coordina y si conviene que la web pida también ciudad o provincia.
6. **Documento comercial desactualizado:** `docs/validacion-comercial.md` todavía dice que la prueba gratis es con Profesional (hoy es Empresa) y que los remitos digitales son desde Profesional (hoy están en todos los planes). Propongo corregirlo en un cambio aparte, solo de documentación.
