# Etapa 10: asistente automático dentro de WhatsApp (alternativa futura)

**Estado:** **alternativa futura, no aprobada ni implementada.** El 13/10/2026 el dueño decidió cerrar la Etapa 10 sin gastos adicionales: queda el asistente web que abre WhatsApp con el mensaje armado (`docs/etapa-10-whatsapp.md`). Esta propuesta se conserva para evaluarla más adelante. **Tiene costos recurrentes:** Meta cobra cada respuesta automática desde el 1/10/2026, más la IA si se usa. Las tarifas y requisitos son los investigados el 13/10/2026 y hay que volver a verificarlos antes de retomarla.

**Objetivo:** que alguien escriba al WhatsApp Business de Ascento (+54 9 11 7372-8421) y un asistente automático, dentro del mismo chat:
1. responda en lenguaje natural con datos reales de la app (funciones, planes, precios, límites, prueba gratis);
2. averigüe si quien escribe es una empresa de mantenimiento de ascensores;
3. junte solo nombre, empresa, edificios y técnicos aproximados, necesidad principal e interés en una demostración;
4. reconozca cuándo la persona quiere hablar con el dueño;
5. derive con un resumen, sin que la persona repita todo;
6. diga «no lo sé» y ofrezca una persona cuando no tenga la respuesta;
7. no se mezcle con las comunicaciones de las empresas clientes.

El asistente web actual (abre WhatsApp con un mensaje armado) sigue sirviendo como puerta de entrada, pero no cumple este objetivo.

---

## 1. Las tres formas posibles

| | A. Agente de Meta en la app («Meta Business Agent») | B. Plataforma oficial con coexistencia (app + API, mismo número) | C. Plataforma oficial solamente (el número deja la app) |
|---|---|---|---|
| **Qué es** | El asistente de IA que Meta lanzó en junio de 2026 dentro de la app WhatsApp Business. | El número sigue en tu app y además se conecta a la Cloud API. Un asistente propio, alojado en Ascento, responde por la API. | El número pasa solo a la Cloud API. El asistente es propio y la atención humana se hace desde una bandeja en Ascento. |
| **Programación** | Ninguna. | Webhook, asistente, resumen y derivación en Laravel. | Lo mismo que B, más una bandeja de chat completa en el panel. |
| **Conservás el número** | Sí. | Sí. | Sí, pero ya no lo usás en la app. |
| **Seguís atendiendo desde tu app** | Sí. | Sí: tus respuestas desde el teléfono llegan a Ascento y el asistente se calla. | No. |
| **Datos reales de la app** | No: le cargás la información a mano y, si cambian los planes, la actualizás a mano. | Sí: salen de `SalesAssistant`, igual que la web. | Sí. |
| **Control de qué responde y qué guarda** | Bajo: lo define Meta. | Total. | Total. |
| **Costo** | Meta cobra sus respuestas **por tokens** (categoría propia) y anunció suscripciones pagas. | **Cada respuesta automática se cobra** (desde el 1/10/2026) a la tarifa de utilidad de Argentina. Tus respuestas desde la app siguen gratis. La IA tiene costo aparte (sección 4). | Igual que B, pero también se pagan tus respuestas, porque ya no usás la app. |
| **Aprobaciones de Meta** | Ninguna nueva. | Verificar el negocio. Para la coexistencia hace falta ser Tech Provider (con revisión de la app de Meta) o pasar por un proveedor (BSP). | Verificar el negocio y configurar la Cloud API. |
| **Riesgo principal** | Precio futuro desconocido; poco control; hoy no hay forma de saber si responde bien sobre Ascento sin probarlo. | Trámite con Meta (verificación y revisión), que puede llevar semanas. | Perdés la app en ese número. |

**Recomendación: B.** Es la única opción que cumple los 7 puntos, conserva el número y te deja atender desde la app. Se aloja en el servidor actual.

**A** vale como **prueba rápida sin programar** mientras se tramita B (sus respuestas se cobran por tokens), si aparece en tu app (Herramientas para la empresa). No conviene tener A y B activos a la vez sobre el mismo número: no encontré documentación de Meta sobre cómo conviven.

**C** no se recomienda, porque usás la app.

---

## 2. Qué permite cada cosa

### 2.1 La app WhatsApp Business que ya tenés
- **Mensajes automáticos:** bienvenida, ausencia y respuestas rápidas. Siempre con el mismo texto: no entienden lo que escribe la persona.
- **Organización:** etiquetas, catálogo y perfil.
- **Meta Business Agent** (anunciado como disponible en todo el mundo desde el 3/6/2026):
  - responde preguntas, califica interesados, agenda y deriva a una persona cuando vos decidís;
  - sus respuestas se cobran **por tokens** (categoría propia en la tarifa de Meta) y Meta anunció suscripciones pagas;
  - **no lee datos de Ascento**: hay que cargarle la información.
  - **A verificar en tu app:** que esté disponible para tu cuenta en Argentina.
- **No tiene API:** ningún sistema externo puede leer ni responder mensajes de la app. Automatizarla con herramientas no oficiales viola las condiciones de WhatsApp y arriesga el número. **Descartado.**

### 2.2 La plataforma oficial (WhatsApp Cloud API)
- **Funcionamiento:** Meta envía cada mensaje entrante a un **webhook** (una URL de Ascento) y Ascento responde llamando a la API.
- **Ventana de 24 horas:** se abre cada vez que la persona escribe. Dentro de esa ventana se responde libremente. Fuera de ella solo se pueden enviar **plantillas** aprobadas por Meta.
- **Precio:** **desde el 1/10/2026 Meta cobra cada mensaje de servicio** (respuesta dentro de la ventana), a la misma tarifa que los de utilidad del país del destinatario y sin descuentos por volumen. Antes eran gratis. Siguen gratis las respuestas dentro de las 72 h cuando la persona llega desde un anuncio de Meta («Click to WhatsApp») o un botón de una página de Facebook.
- **Requisitos:**
  - un portfolio de negocio en Meta (Business Manager) verificado;
  - una cuenta de WhatsApp Business Platform (WABA);
  - una app de Meta con el producto WhatsApp;
  - un token de acceso permanente (usuario del sistema);
  - un medio de pago en la cuenta (solo se usa si se envían plantillas).
- **Política de IA:** desde el 15/1/2026, Meta **prohíbe en la plataforma los asistentes de IA de propósito general** (tipo ChatGPT). **Sí permite asistentes de un negocio** que atienden consultas sobre sus propios productos, que es este caso. Por eso el asistente tiene que negarse a temas ajenos a Ascento.

### 2.3 ¿Puedo conservar mi número?
**Sí.**
- **Con coexistencia (B)**, el mismo número queda en tu app y en la API.
  - Requisitos: app WhatsApp Business **2.24.17 o superior**, el número no puede estar ya en otra configuración de API y el alta se hace con el flujo oficial de Meta (*Embedded Signup*).
  - Se sincronizan los contactos y los últimos **6 meses** de chats individuales, en un plazo de 24 horas.
  - **Se pierden:** los grupos no se sincronizan; las listas de difusión quedan de solo lectura; se desactivan los mensajes temporales, «ver una vez» y la ubicación en tiempo real; **las llamadas no funcionan por la API**.
  - Los dispositivos vinculados se desvinculan y se vuelven a vincular. WhatsApp para Windows y para WearOS no funcionan con coexistencia.
  - Cada mensaje que mandás desde la app le llega a Ascento como aviso (`smb_message_echoes`). Así el asistente sabe que tomaste la conversación.
- **Países:** las fuentes no coinciden en qué países quedan afuera; ninguna menciona a Argentina como excluida. **Se confirma al iniciar el alta.**

### 2.4 Configuración y aprobación de Meta (para B)
1. **Verificación del negocio** de Ascento en Meta Business Manager. Pide los datos y documentos de la empresa o persona titular; Meta define cuáles.
2. **Dos caminos para la coexistencia:**
   - **Ascento como Tech Provider:**
     - sin costo según la documentación;
     - requiere la app de Meta con ícono, política de privacidad y categoría;
     - hay que mandar videos que demuestren el envío de mensajes y la gestión de plantillas;
     - Meta revisa la app para los permisos avanzados `whatsapp_business_messaging` y `whatsapp_business_management`.
     - **Es el camino sin servicios adicionales.** No encontré documentación de Meta que confirme que un Tech Provider pueda dar de alta su propio número con coexistencia; hay que confirmarlo durante el trámite.
   - **Un proveedor (BSP) que ya ofrezca coexistencia:** más rápido, con costo mensual o por mensaje según el proveedor. **Agrega un servicio pago**, así que requiere tu aprobación aparte.
3. **Configuración técnica:**
   - el webhook de Ascento, con su token de verificación y la firma con el *App Secret*;
   - suscripción a los mensajes entrantes y a los ecos de la app;
   - un **perfil y nombre visible** aprobados («Ascento»).
4. **Plantillas:** solo si en el futuro se quiere escribir primero, por ejemplo un recordatorio de una demo acordada. **Para responder no hacen falta.**

Ascento ya tiene una app de Meta y código de la Cloud API para la integración por empresa de los técnicos, hoy desactivada. **No se reutiliza**: el canal comercial usa **una app de Meta, un webhook y credenciales propios** (sección 6).

---

## 3. Cómo funcionaría (opción B)

```
Persona escribe al +54 9 11 7372-8421
  → Meta → POST https://ascento.online/api/ventas/whatsapp (firma verificada)
      → Ascento responde 200 al instante y procesa después de la respuesta
      → ¿La conversación está en manos del dueño?  sí → no responde
      → Asistente: contexto = hechos reales (SalesAssistant) + lo charlado
          ├─ responde (solo con esos hechos)
          ├─ actualiza la ficha del interesado (datos que la persona dio)
          └─ ¿hay que derivar? → mensaje al cliente + aviso al dueño con resumen
  → Respuesta por la Cloud API (con costo por mensaje desde el 1/10/2026)
El dueño contesta desde su app → eco a Ascento → el asistente se calla en ese chat
```

### 3.1 Atención automática
- **Fuente de verdad:** el asistente solo conoce lo que arma `SalesAssistant`, igual que la web y la comparativa (planes, precios, límites, funciones por plan y prueba gratis), más los textos fijos de urgencias y vecinos. Si cambiás un plan, el asistente lo sabe en el mensaje siguiente.
- **Lenguaje natural:** requiere un modelo de lenguaje (IA). Se usa con instrucciones cerradas:
  - responder solo con los hechos entregados;
  - si algo no está, decir «no tengo ese dato» y ofrecer una persona;
  - no hablar de temas ajenos a Ascento (política de Meta);
  - no prometer funciones, descuentos ni fechas.
- **Sin IA (sin costo de IA; Meta cobra igual cada respuesta):** menús con botones y listas de WhatsApp, más palabras clave. Funciona, pero no es lenguaje natural. Propongo construirlo primero (fase 1) y sumar la IA arriba (fase 2). Así, si la IA falla o se desactiva, el menú sigue andando.

### 3.2 Filtrado de interesados
El asistente completa una ficha a medida que la persona cuenta; no es un formulario.

| Dato | Para qué |
|---|---|
| ¿Es una empresa de mantenimiento de ascensores? | Si es un vecino, una administración o una urgencia, recibe la respuesta fija (Etapa 10, fase 1) y no pasa a ventas. |
| Nombre y empresa | Para que el dueño sepa con quién habla. |
| Edificios y técnicos (aproximados) | Ubica el plan que le corresponde, con datos reales. |
| Necesidad principal | Agenda y remitos, portal, cobranzas, presupuestos u otro. |
| ¿Quiere una demostración? | Dispara la derivación. |

No se piden contraseñas, códigos, DNI, CUIT ni datos de pago. El número de teléfono lo informa WhatsApp; no se pregunta.

### 3.3 Derivación al dueño
- **Cuándo deriva:**
  - la persona pide hablar con alguien;
  - quiere una demostración;
  - pregunta por precios especiales o facturación;
  - supera los límites del plan más grande;
  - quiere migrar datos o presenta una queja;
  - el asistente no supo responder dos veces.
- **Qué pasa:**
  1. Al cliente: «Te paso con Franco, que te responde por este mismo chat. Ya le dejé tu consulta».
  2. Al dueño: un aviso por los canales que ya existen para el SuperAdmin (Telegram, push o campanita) con el **resumen** («Ana, Ascensores del Sur, unos 40 edificios y 5 técnicos. Le interesa agenda y remitos, quiere demo. Preguntó por el portal y se le respondió que está desde Profesional») y el enlace al chat.
  3. **Desde ese momento el asistente no vuelve a responder en ese chat** hasta que vos lo liberes o pasen N días sin actividad (configurable). Lo mismo si respondés desde la app sin que haya derivación: el eco lo detecta.
- **Pantalla «Interesados» del SuperAdmin** (reemplaza la fase 2 anterior): lista con ficha, resumen, estado (nuevo, derivado, demo acordada, descartado) y botón «Devolver al asistente». **No es un chat**: los mensajes se leen y se responden en tu app.

### 3.4 Lo que no sabe
- Respuesta fija: «No tengo ese dato confirmado. ¿Querés que te pase con Franco?».
- La pregunta queda registrada como «Sin respuesta» en la pantalla, para decidir si se suma a los hechos.

---

## 4. Costos

**Cambio importante de Meta:** desde el **1/10/2026**, Meta cobra cada respuesta enviada por la API dentro de la ventana de 24 h. Hasta septiembre eran gratis. La tarifa es la de los mensajes de utilidad del país del destinatario (Argentina tiene tarifa en ARS desde abril de 2026), sin descuentos por volumen. Fuente: la documentación de Meta sobre mensajes que no son plantillas.

| Concepto | Costo | Detalle |
|---|---|---|
| **Meta: respuestas del asistente (API)** | **Por mensaje**, a la tarifa de utilidad de Argentina | La tarifa exacta está en la tabla oficial en ARS de Meta. Las fuentes de terceros dan para Argentina entre **USD 0,012 y 0,026 por mensaje**, sin confirmar. Ejemplo: 300 conversaciones por mes × 6 respuestas del asistente = 1.800 mensajes, **unos USD 22 a 47 por mes**. |
| **Meta: tus respuestas desde la app** | Gratis | Con coexistencia, los mensajes enviados desde la app no se cobran. |
| **Meta: conversaciones desde anuncios** | Gratis por 72 h | Solo si la persona llega desde un anuncio «Click to WhatsApp» o un botón de Facebook. Hoy no hay anuncios. |
| **Meta: escribir primero** | Por mensaje (plantillas) | No está en el plan. |
| **Meta: verificación y Tech Provider** | Sin costo según la documentación | Trámite propio. |
| **Proveedor BSP** (solo si no se va como Tech Provider) | Variable: mensual o por mensaje | Servicio adicional. Requiere tu aprobación. |
| **Modelo de IA** (solo la fase 2) | Por uso | Estimación orientativa, a confirmar con la tarifa del proveedor: **entre USD 0,01 y 0,05 por conversación**, unos **USD 3 a 15 por mes** con 300 conversaciones. Se le pone un tope mensual. |
| **Servidor** | Sin costo nuevo | El servidor actual de Forge. |
| **Meta Business Agent** (opción A) | Por tokens, más suscripciones anunciadas | Una fuente de terceros habla de USD 2 por millón de tokens desde el 1/8/2026; no lo verifiqué en Meta. |

**Cómo bajar el costo de Meta sin perder el objetivo:**
- respuestas cortas y completas: una respuesta por mensaje de la persona, no varias;
- botones y listas para que la persona elija con un toque;
- derivar a tu app apenas hace falta, porque lo que respondés vos no se cobra.

**Ningún camino de automatización dentro de WhatsApp es gratis hoy.** El piso es lo que Meta cobra por cada respuesta automática.

---

## 5. Infraestructura actual: alcanza

- **Webhook:** una ruta nueva en Laravel, con validación de la firma de Meta. El patrón ya existe en `WhatsAppWebhookController::hasValidSignature`.
- **Procesamiento:** Meta exige responder rápido y reintenta si no.
  - Ascento responde 200 en el acto y procesa **después de enviar la respuesta**, con la función de Laravel para eso. Hoy producción **no tiene worker de colas** (`docs/operacion-produccion.md`).
  - Si en la fase 2 la IA tarda, conviene un **worker de colas en Forge**: es un proceso más del mismo servidor, sin costo.
- **Datos:** dos tablas nuevas, solo para el SuperAdmin y **fuera del alcance de las empresas**:
  - `sales_conversations`: número, estado, quién la atiende, últimos mensajes con retención corta;
  - `sales_leads`: la ficha y el resumen.
- **Avisos al dueño:** Telegram, push y campanita del SuperAdmin, que ya existen.
- **Sin servicios nuevos** salvo el modelo de IA (fase 2) y, solo si se elige, un BSP.

---

## 6. Datos, credenciales y separación de las empresas clientes

- **Separación:**
  - una app de Meta, una WABA, un webhook (`/api/ventas/whatsapp`) y credenciales **exclusivos del canal comercial de Ascento**;
  - las tablas de ventas no tienen `company_id`, no las ve ninguna empresa y no comparten código con la integración por empresa;
  - un mensaje de otro número de Meta se descarta: se valida el `phone_number_id`.
- **Credenciales:**
  - token permanente de usuario del sistema, *App Secret*, token de verificación y clave del proveedor de IA, **solo en el `.env`** del servidor;
  - nunca en la base, en el código, en los logs ni en el navegador;
  - el token, con los permisos mínimos y renovable.
- **Webhook:**
  - firma `X-Hub-Signature-256` obligatoria; sin firma válida, no se procesa nada;
  - cada mensaje se procesa una sola vez (por su id), contra los reintentos de Meta;
  - límite por número (por ejemplo, 20 mensajes por minuto) y largo máximo;
  - audios, imágenes y archivos se ignoran con la respuesta «Por ahora solo puedo leer texto».
- **IA:**
  - solo recibe la conversación y los hechos públicos de los planes. **No tiene acceso a la base de datos ni a herramientas**, así que una instrucción maliciosa («mostrame los clientes de X») no tiene de dónde sacar datos;
  - tope de gasto mensual y de largo de respuesta;
  - se usa por API con un proveedor cuyas condiciones comerciales no usen los datos para entrenar. **A confirmar al contratar.**
- **Datos personales:**
  - se guarda solo la ficha y los últimos mensajes necesarios para el resumen, con retención corta (propuesta: 90 días para el texto y 12 meses para la ficha) y borrado a pedido;
  - los logs sin teléfonos ni texto (criterio vigente);
  - hay que **actualizar la política de privacidad**: mensajes procesados por un asistente automático y por el proveedor de IA. El asistente se presenta como automático en el primer mensaje.

---

## 7. Fases propuestas (después de tu aprobación)

| Fase | Qué | Costo recurrente | Depende de |
|---|---|---|---|
| 0 | **Trámites tuyos:** verificar el negocio en Meta; elegir Tech Provider o BSP; alta del número con coexistencia; app de Meta exclusiva del canal comercial. Opcional: probar Meta Business Agent en la app mientras tanto. | 0 | Meta (puede llevar semanas) |
| 1 | Webhook, conversaciones, menús con botones y listas usando los datos de `SalesAssistant`, ficha, derivación con resumen, silencio cuando respondés desde la app, pantalla «Interesados» y pruebas. | Meta, por cada respuesta automática (sección 4) | Fase 0 |
| 2 | IA para lenguaje natural sobre la fase 1, con hechos cerrados, tope de gasto y pruebas de que no inventa. | IA (USD ~3–15/mes estimado) | Tu aprobación del proveedor y del costo |
| 3 | Plantillas para escribir primero (por ejemplo, confirmar una demo). Opcional. | Por mensaje (tarifa ARS de Meta) | Aprobación aparte |

La fase 2 anterior (pantalla de respuestas rápidas) **queda reemplazada** por la pantalla «Interesados» de la fase 1.

---

## 8. Limitaciones

- **No hay forma oficial de automatizar la app sola.** La automatización requiere la Cloud API (o el agente de Meta dentro de la app).
- **Con coexistencia:**
  - las llamadas no pasan por la API;
  - grupos y listas de difusión quedan limitados;
  - WhatsApp para Windows deja de funcionar en ese número.
- **Plazos de Meta:** la verificación del negocio y la revisión de la app dependen de Meta.
- **Ventana de 24 h:** si la persona no responde en un día, el asistente no puede escribirle de nuevo sin una plantilla paga. El seguimiento lo hacés vos desde la app.
- **La IA puede equivocarse aunque tenga hechos cerrados.** Se mitiga con respuestas cortas, «no lo sé» explícito, derivación fácil y pruebas con preguntas reales; no se elimina del todo.
- **Cada respuesta automática tiene costo** (desde el 1/10/2026), y las tarifas de Meta cambian seguido. Antes de activar hay que revisar la tarifa oficial en ARS y poner un tope de mensajes automáticos por día.

---

## 9. Decisiones que necesito

1. **Arquitectura:** B (recomendada), A como prueba sin programar mientras tanto, o C.
2. **Camino con Meta:** Ascento como Tech Provider (sin costo, trámite más largo) o un BSP (más rápido, pago).
3. **Costo de Meta:** aceptar que cada respuesta automática se cobra (estimado USD 22–47/mes con 300 conversaciones; a confirmar con la tarifa en ARS) y fijar un tope.
4. **IA:** aprobar la fase 2 y un tope de gasto mensual, o quedarse con menús (fase 1) por ahora.
5. **Retención de datos:** 90 días para el texto y 12 meses para la ficha, o los plazos que prefieras.
6. **Nombre en los mensajes de derivación:** «Franco» u otro.

---

## Fuentes (consultadas el 13/10/2026)

- Meta, precios de la plataforma: https://developers.facebook.com/docs/whatsapp/pricing
- Meta, alta de usuarios de la app (coexistencia): https://developers.facebook.com/docs/whatsapp/embedded-signup/custom-flows/onboarding-business-app-users
- Meta, Tech Providers: https://developers.facebook.com/docs/whatsapp/solution-providers/get-started-for-tech-providers
- Meta, Meta Business Agent: https://whatsappbusiness.com/blog/introducing-meta-business-agent-ai/
- TechCrunch, Meta Business Agent disponible globalmente (3/6/2026): https://techcrunch.com/2026/06/03/metas-ai-agent-for-whatsapp-business-is-now-available-globally/
- TechCrunch, WhatsApp prohíbe los chatbots de propósito general: https://techcrunch.com/2025/10/18/whatssapp-changes-its-terms-to-bar-general-purpose-chatbots-from-its-platform
- Terceros sobre coexistencia y países (no oficiales, se contradicen): https://wanotifier.com/whatsapp-coexistence-guide/ , https://whautomate.com/whatsapp-coexistence
- Meta, mensajes que no son plantillas (cobro desde el 1/10/2026): https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/non-template-messages
- Terceros sobre ese cambio y las tarifas por país (no oficiales): https://www.wati.io/en/blog/whatsapp-service-message-pricing/ , https://ominiflow.com/whatsapp-api-pricing/argentina , https://sleekflow.io/blog/whatsapp-business-price
