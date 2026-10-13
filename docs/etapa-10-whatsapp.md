# Etapa 10: consultas comerciales y WhatsApp

**Estado:** cerrada. Enfoque aprobado el 13/10/2026: un asistente guiado en la web que abre WhatsApp con el mensaje armado, y la app gratuita WhatsApp Business atendida por el dueño.

**Sin costos:** no hay API de WhatsApp, respuestas automáticas dentro de WhatsApp, proveedores pagos ni servicios externos nuevos. La integración automática dentro de WhatsApp queda documentada como alternativa futura, con costos, en `docs/etapa-10-whatsapp-api.md`. **No está aprobada ni implementada.**

**Objetivo:** que quien visita ascento.online resuelva sus dudas comerciales con datos reales y, cuando quiere seguir, consultar algo puntual o pedir una demostración, llegue al WhatsApp de Ascento con la consulta ya ordenada.

---

## 1. Cómo funciona hoy

### 1.1 El asistente («Hablemos»)

- **Dónde aparece:**
  - en la página de inicio: «Hablemos» arriba, el pie, el cierre de la página y un botón flotante «Consultas» que aparece al bajar;
  - en el ingreso y el registro: el enlace «¿Necesitás ayuda?», sin botón flotante. **Está cerrado hasta que se toca**: no interfiere con el ingreso.
  - No aparece en el panel, la app del técnico ni el portal.
- **Temas, en este orden.** Primero lo que lleva a probar o a hablar con nosotros:
  1. Planes y precios
  2. Probar gratis o pedir una demostración
  3. Qué es Ascento
  4. Cómo funciona
  5. Mantenimientos e inspecciones
  6. Remitos digitales
  7. Portal para tus clientes
  8. QR público del edificio
  9. Ya uso Ascento y necesito ayuda
  10. Hablar con una persona
  11. *(Otras consultas)* Soy vecino o de la administración de un edificio
  12. *(Otras consultas)* **Tengo una urgencia con un ascensor**: es la última opción.
- **Respuestas:**
  - cortas, con el detalle en desplegables («Qué suma cada plan», «Qué incluyen todos los planes», «Todos los límites»);
  - botones «Probar gratis 30 días» y «Ver los planes», y el de WhatsApp («Consultar por WhatsApp» o «Pedir una demostración»);
  - en «Planes y precios», un botón por plan («Plan Profesional») que pasa a WhatsApp con ese plan como tema.
- **Paso a WhatsApp:**
  - **se puede consultar todo sin dar ningún dato**;
  - al pasar a WhatsApp, **todos los datos son opcionales**: nombre, empresa, edificios que mantienen (rangos que salen de los topes de los planes) y mensaje;
  - se abre `https://wa.me/<número>?text=<mensaje>` con «Tema: …» y solo lo que la persona completó. El mensaje lo envía ella desde su WhatsApp.
- **Vecinos y urgencias:** respuesta fija, **sin** botón a WhatsApp ni enlaces comerciales.
  - **Vecinos:** Ascento es un sistema de gestión para empresas de mantenimiento; que consulten a la administración del edificio o a la empresa mantenedora.
  - **Urgencias:** Ascento no es un servicio de emergencias ni de mantenimiento; ante peligro inmediato, el servicio de emergencias de la zona y la empresa responsable del mantenimiento. No da un número fijo de un solo país ni diagnostica.

### 1.2 De dónde salen los datos

Todo sale de la configuración real (`app/Support/Sales/SalesAssistant.php`), la misma fuente que la comparativa de la página de inicio:

| Dato | Fuente |
|---|---|
| Planes, precios, moneda | `SubscriptionPlan::offered()` |
| Límites | `PlanLimit::planLabel()`, las mismas frases que «Mi suscripción» |
| Qué incluye y suma cada plan | `SubscriptionPlan::comparisonRows()` |
| Desde qué plan está una función | `cheapestWith()` / `allowsAll()` |
| Prueba gratis | `SubscriptionPlan::TRIAL_PLAN` (Empresa) y `TRIAL_DAYS` (30), que también usa el registro |
| Correo de soporte | `config('app.support_email')` |

- Si el SuperAdmin cambia un plan desde «Planes», el asistente lo muestra en la próxima carga de la página.
- Si ningún plan incluye una función, su tema no aparece.

### 1.3 Número y horario

| Variable | Si no existe | Con valor | Definida vacía |
|---|---|---|---|
| `ASCENTO_WHATSAPP_NUMBER` | `5491173728421` (+54 9 11 7372-8421, confirmado el 13/10/2026 y en uso en la app WhatsApp Business del dueño) | Ese número, solo dígitos | Sin WhatsApp: el asistente ofrece el correo de soporte |
| `ASCENTO_WHATSAPP_HOURS` | «todos los días, las 24 horas, incluidos fines de semana y feriados» | Ese texto | No se muestra horario |

- Un número que no sea internacional válido (10 a 15 dígitos, sin 0 inicial) funciona igual que «definida vacía».
- Los valores con espacios van **entre comillas** en el `.env`. Sin comillas, el archivo es inválido y la aplicación no arranca.
- Después de cambiar el `.env`, hay que volver a desplegar o correr `php artisan config:cache`.
- El asistente muestra «Horario de atención: …» junto al paso a WhatsApp. Ningún texto dice que estamos fuera de horario ni promete un plazo de respuesta.

### 1.4 Privacidad y seguridad

- **Sin servidor:** las respuestas viajan con la página y el enlace lo arma el navegador. No hay endpoint que se pueda saturar, no se guarda nada y no hay rutas nuevas (una prueba lo verifica).
- **Escapado:** las respuestas las escribe el servidor y Blade las escapa. Lo que escribe la persona solo va codificado dentro del enlace (`encodeURIComponent`).
- **Entradas:** se quitan los caracteres de control, con topes de 80 (nombre), 100 (empresa) y 600 (mensaje) caracteres. El rango de edificios solo se acepta si es una de las opciones.
- **No se piden** contraseñas, códigos, correo, DNI ni datos de pago. El asistente avisa que no se escriban contraseñas ni códigos.
- **No lee datos de ninguna empresa:** solo los planes ofrecidos, que ya publica la página.

---

## 2. La app WhatsApp Business (la configura el dueño en el teléfono)

El sistema no automatiza nada dentro de WhatsApp. Configuración sugerida, sin costo:

- **Mensaje de bienvenida:**
  > ¡Hola! Gracias por escribir a Ascento, el sistema para empresas de mantenimiento de ascensores. Contanos en qué te ayudamos: planes, una demostración o cómo funciona algo en particular. Los planes y precios actualizados están en ascento.online/#planes.
  > Ascento no es un servicio de emergencias ni de mantenimiento. Si hay peligro inmediato con un ascensor, comunicate con el servicio de emergencias de tu zona y con la empresa responsable del mantenimiento.
  > Nunca te vamos a pedir contraseñas ni códigos por WhatsApp.
- **Mensaje de ausencia:** no corresponde, porque la atención es continua.
- **Respuestas rápidas:** sin precios ni límites escritos, para que no queden viejas cuando cambian los planes. Los datos actualizados están siempre en el asistente y en la comparativa de la web.
  - `/planes`: «Los planes, precios y lo que incluye cada uno están siempre actualizados en ascento.online/#planes. ¿Cuántos edificios y técnicos manejan? Así te digo cuál conviene.»
  - `/prueba`: «Podés probar Ascento gratis desde ascento.online, con todas las funciones y sin tarjeta. Si querés, te acompaño a cargar los primeros edificios.»
  - `/demo`: «¡Genial! ¿Qué día y horario te queda cómodo para que te lo muestre? ¿Qué te interesa ver primero: agenda y remitos, portal para clientes o cobranzas?»
  - `/vecino`: «Ascento es un sistema para empresas de mantenimiento de ascensores. Para consultas sobre tu ascensor, comunicate con la administración del edificio o con la empresa que hace el mantenimiento.»
  - `/urgencia`: el párrafo de urgencias del mensaje de bienvenida.
- **Etiquetas:** «Ventas», «Demostración», «Soporte» y «Sin respuesta».
- **Abuso:**
  - spam o insultos: una sola respuesta estándar y, si sigue, **Bloquear** y **Denunciar**;
  - nunca se piden contraseñas, códigos ni pagos por chat;
  - solo se envían enlaces de `ascento.online`;
  - los pedidos de datos de un cliente («pasame el remito del edificio X») no se responden por WhatsApp;
  - el restablecimiento de contraseñas lo hace siempre el sistema («¿Olvidaste tu contraseña?»), nunca una persona por chat.

---

## 3. Mantenimiento

- **Si cambian los planes:** no hay que tocar nada. El asistente, la comparativa y las preguntas frecuentes leen la configuración. Las respuestas rápidas de la app no tienen precios, así que tampoco quedan viejas.
- **Si cambia un texto:** se edita en `SalesAssistant` y se corre `php artisan test tests/Feature/Public`.
- **Pruebas que protegen esto:** `tests/Feature/Public/SalesAssistantTest.php` y `WhatsAppContactTest.php`.
  - Datos reales de los planes; un tema desaparece si ningún plan lo incluye.
  - Orden de los temas, con la urgencia al final; vecinos y urgencias sin paso a WhatsApp.
  - Datos opcionales; número, horario y variables de entorno; sin rutas ni envíos al servidor; el ingreso no se ve afectado.

---

## 4. Lo que se descartó y por qué

| Idea | Decisión |
|---|---|
| Pantalla del SuperAdmin con respuestas rápidas para copiar (antigua «fase 2») | Descartada: las respuestas rápidas de la app ya no llevan precios, así que no hace falta sincronizarlas. Agregaba complejidad sin beneficio. |
| Asistente automático dentro de WhatsApp (Cloud API, coexistencia, IA) | Alternativa futura con costos: Meta cobra cada respuesta automática desde el 1/10/2026. Está en `docs/etapa-10-whatsapp-api.md`. No aprobada. |
| Agenda online de demostraciones | Fuera de alcance: las demostraciones las coordina el dueño por WhatsApp. |
| Conexión de WhatsApp por empresa (botones de órdenes a técnicos) | Código anterior, desactivado. No se usa para el canal comercial. |

---

## 5. Historial

| Cambio | PR |
|---|---|
| Propuesta inicial (asistente web + app Business) | #67 |
| Asistente web, número y horario confirmados | #68, #69 |
| Validación comercial corregida según la configuración real | #70 |
| Portada animada original restaurada a pedido del dueño | #71 |
| Cierre: orden de temas (urgencia al final), respuestas más cortas, consulta por plan, datos opcionales, propuesta de API como alternativa futura | etapa-10/cierre |
