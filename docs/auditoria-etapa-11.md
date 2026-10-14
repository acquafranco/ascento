# Etapa 11: auditoría integral de seguridad

**Rama:** `etapa-11/auditoria-seguridad`, desde main `411238e` (13/10/2026).

**Alcance:** el código de la aplicación y sus pruebas. **No** se revisó ni se tocó producción (Forge, servidor, base, variables de entorno).

**Método:** lectura del código por área, pruebas que reproducen cada hallazgo antes de corregirlo y una matriz automática de aislamiento entre empresas. Todo con bases de prueba aisladas (SQLite y MySQL local) y datos de la demo. Nunca con datos de producción.

> Que las pruebas pasen no demuestra que Ascento sea completamente seguro. Este informe dice qué se revisó, qué se encontró y qué queda abierto.

---

## 1. Resultado

- **Ninguna vulnerabilidad crítica ni alta.**
- **Ningún acceso cruzado entre empresas.** Una empresa no pudo leer, crear, modificar, borrar ni descargar recursos de otra:
  - por URL con ids manipulados;
  - por pedidos de Livewire;
  - por acciones de tabla;
  - por descargas de archivos;
  - desde el portal.
- **6 hallazgos de gravedad media o baja**, todos corregidos con su prueba (sección 3). Ninguno permite ver datos de otra empresa.
- **9 riesgos abiertos** con su motivo (sección 4).

---

## 2. Mapa de aislamiento entre empresas

**Cómo se aísla cada empresa:**
- 32 modelos usan `BelongsToCompany`.
  - Al crear, la empresa sale del usuario autenticado, nunca del pedido.
  - La empresa de un registro no se puede cambiar.
  - Hay un ámbito global por `company_id`.
- El ámbito solo actúa con un usuario autenticado, así que los accesos sin sesión filtran por su cuenta:
  - enlaces públicos de remitos y presupuestos (empresa + token UUID, y firma con vencimiento en los presupuestos);
  - QR público (token de 48 caracteres);
  - webhooks.
- **Modelos sin el ámbito:** `User`, `Subscription`, `SubscriptionPayment`, `ActivityLog`, `QuoteEvent`, `PortalMembership`, `BuildingUser`, `Backup`, `WebhookEvent`, `PlanFeatureChange`.
  - Se revisaron sus consultas en el panel, los servicios y los controladores: todas filtran por empresa o son del SuperAdmin.

**Matriz automática** (`tests/Feature/Stage11/CrossTenantMatrixTest.php`). Usa las dos empresas de la demo: Norte, con datos de todos los módulos, como empresa atacada; Sur como atacante.

| Superficie | Qué se prueba | Resultado |
|---|---|---|
| Rutas web, de archivos y del portal con un id (35 rutas, 114 ataques por rol) | Dueño, técnico y usuario del portal de Sur, con ids de Norte, en GET/POST/PATCH/DELETE y con ids de Norte también en el cuerpo del pedido | Nunca 2xx ni 500 |
| Páginas del panel con registro (79 combinaciones de recurso, página y registro) | El dueño de Sur abre ver/editar con registros de Norte | 403 o 404 |
| Acciones de tabla y masivas de todos los recursos | El dueño de Sur ejecuta cada acción con claves de Norte | Nada cambia |
| Base completa | Huella de todas las tablas antes y después de los ataques | Sin cambios |

**Controles de la matriz:**
- Con sus propios ids, el dueño de Norte recibe 200 en 36 rutas: la matriz sí detecta accesos.
- Un cambio en una fila altera la huella.
- Las rutas y los recursos nuevos quedan cubiertos solos.

**Otras áreas revisadas, que ya tenían pruebas** (`tests/Feature/Tenancy`, `Security`, `Access`, `Portal`):
- **Portal:** empresa, cliente, edificio habilitado y «compartido» en cada documento.
- **Canales de tiempo real:** solo el propio usuario.
- **Notificaciones:** solo las propias; el redireccionamiento solo va a rutas internas.
- **Exportación:** solo la empresa propia; sin contraseñas, tokens ni firmas.
- **Mercado Pago:** firma, referencia de la empresa e importe verificados contra la API.
- **Webhooks de WhatsApp y Telegram:** firma y secreto verificados.

---

## 3. Hallazgos confirmados y corregidos

Cada uno tiene una prueba en `tests/Feature/Stage11/SecurityFindingsTest.php` que falla sin la corrección y pasa con ella.

| # | Gravedad | Componente | Escenario | Impacto | Corrección |
|---|---|---|---|---|---|
| H1 | Media-baja | Mapa (`BuildingsMap::placeBuilding`) | Un administrativo con solo «ver» Edificios llama al método de Livewire desde la consola del navegador. Al no ser una acción de Filament, no pasa por `ActionGuard`. | Cambia la ubicación de cualquier edificio de **su** empresa | Exige «gestionar» Edificios en el servidor y oculta «Marcar en el mapa» sin ese permiso |
| H2 | Media | Ingreso (`LoginRequest`) | Desde una IP se prueban contraseñas contra muchas cuentas: el límite era de 5 fallos por correo + IP | Ataque de contraseñas comunes sobre muchas cuentas a la vez | Además, 30 fallos por IP cada 10 minutos. Los ingresos correctos no cuentan: no bloquea a una oficina |
| H3 | Media | Sesiones | Al cambiar la contraseña (u «Olvidé mi contraseña»), las sesiones abiertas en otros dispositivos seguían activas en la app del técnico y el portal; solo el panel las cerraba | Una sesión robada sobrevive al cambio de contraseña | `AuthenticateSession` en todo el grupo web. La sesión de quien la cambia sigue abierta |
| H4 | Baja | Reportes y remitos del técnico | Sin tope de pedidos. Con reportes sin límite mensual, una cuenta comprometida podía mandar fotos y video sin parar | Saturación del servidor (CPU y disco) | `throttle:20,1` en los dos envíos |
| H5 | Media-baja | Límites de pedidos (45 rutas) | En Laravel, un `throttle:N,M` sin prefijo usa **un contador por usuario (o por IP)** compartido entre todas las rutas | Usuarios legítimos bloqueados en rutas de tope bajo (por ejemplo, después de consultar el contador de avisos); un QR escaneado muchas veces bloqueaba «Olvidé mi contraseña» desde esa IP | Cada ruta con su propio contador (prefijo con el nombre de la ruta) |
| B1 | Baja | Conexión de WhatsApp por empresa (en desuso, rutas activas) | El token de acceso de Meta aparecía al serializar la empresa, y el log guardaba la lista de negocios, cuentas y teléfonos de Meta del cliente, más el cuerpo de las respuestas | Exposición de credenciales y de datos de terceros | Token en `$hidden`; los logs guardan solo ids y cantidades |

**Archivos:**
- `app/Filament/Pages/BuildingsMap.php`, `resources/views/filament/pages/buildings-map.blade.php` (H1)
- `app/Http/Requests/Auth/LoginRequest.php` (H2)
- `bootstrap/app.php` (H3)
- `tests/TestCase.php` (H3): `actingAs` imita un ingreso real y guarda la huella de la contraseña en la sesión, como hace `SessionGuard::login`
- `routes/web.php`, `routes/auth.php`, `routes/api.php` (H4, H5)
- `app/Models/Company.php`, `app/Http/Controllers/WhatsAppController.php`, `app/Services/WhatsAppService.php` (B1)

**Efectos revisados:**
- **H3:** las sesiones ya abiertas en producción no se cortan al desplegar; la primera vez solo se anota la huella.
- **H5:** los topes no cambian, solo dejan de compartirse.
- **H2:** una persona que se equivoca algunas veces no se ve afectada.

---

## 4. Riesgos abiertos

| # | Riesgo | Gravedad | Por qué queda abierto | Recomendación |
|---|---|---|---|---|
| R1 | 7 avisos de `npm audit` (5 altos, 2 moderados) en `tailwindcss` 3 y sus dependencias (`braces`, `micromatch`, `postcss-selector-parser`) | Baja | Son **herramientas de compilación**, no código que llega al navegador (`npm audit --omit=dev`: 0). La solución es pasar a Tailwind 4, una migración amplia | Planificar la migración a Tailwind 4 aparte |
| R2 | Sin Content-Security-Policy | Baja (defensa en profundidad) | Filament, Livewire y Alpine necesitan permisos especiales de scripts; una CSP mal puesta rompe el panel | Probar primero una CSP en modo solo reporte |
| R3 | Token de WhatsApp por empresa guardado sin cifrar en la base | Baja | **Mitigado (rama `etapa-11/retirar-whatsapp-empresa`):** la integración se retiró y ningún código lee el token. Era el token de usuario de corta duración del intercambio OAuth (no se canjeaba por uno largo), así que los guardados ya están vencidos. Queda oculto en arrays y JSON | Opcional: vaciar las columnas `whatsapp_*` con una migración controlada, sin urgencia |
| R4 | Rutas de la conexión de WhatsApp por empresa activas, aunque la función estaba en desuso | Baja | **Corregido (rama `etapa-11/retirar-whatsapp-empresa`):** se retiraron rutas, controladores, servicio, configuración y la sección del formulario del SuperAdmin | En Meta, quitar la URL del webhook de la app y borrar `FACEBOOK_*` y `WHATSAPP_*` del `.env` de producción |
| R5 | Sin segundo factor (2FA) para el SuperAdmin ni los dueños | Media | Es una función nueva | 2FA, al menos para el SuperAdmin, que puede descargar backups de toda la plataforma |
| R6 | Ataque a **una** cuenta desde muchas IP (botnet) | Media-baja | El límite es por correo + IP y por IP; no hay bloqueo por cuenta | Avisar por correo de fallos repetidos o bloquear la cuenta temporalmente |
| R7 | `webhook_events` guarda una fila antes de validar la firma | Baja | Tiene un límite de 120 por minuto por IP y se depura cada día | Opcional: registrar solo después de validar |
| R8 | El cambio de correo desde el perfil del técnico no pide la contraseña | Baja | El correo nuevo queda sin confirmar y se manda el enlace | Opcional: pedir la contraseña actual |
| R9 | **Configuración de producción no verificada** | — | Fuera del alcance: no se revisa producción sin autorización | Ver la sección 6 |

---

## 5. Herramientas

| Herramienta | Resultado |
|---|---|
| `composer audit` | Sin avisos de seguridad |
| `npm audit --omit=dev` (lo que llega al navegador) | 0 vulnerabilidades |
| `npm audit` (incluye compilación) | 7 (R1) |
| Pint | Sin errores en los archivos tocados |
| `npm run build` | Correcto |
| Pruebas | Ver el informe del PR |

---

## 6. No revisado o no verificable desde el código

- **Servidor y Forge:**
  - `APP_DEBUG`, `APP_ENV`, `SESSION_SECURE_COOKIE` y el resto de variables reales;
  - permisos de archivos y del disco `storage`;
  - usuarios y accesos de MySQL;
  - firewall, SSH, versión de PHP y nginx;
  - copias de seguridad fuera del servidor.
- **Servicios externos:** configuración real de Mercado Pago (secreto del webhook), de la app de Meta y del bot de Telegram; SPF/DKIM del correo.
- **Pruebas de carga reales** y comportamiento ante tráfico malicioso sostenido.
- **El código interno de Filament, Livewire y Laravel**, más allá de cómo los usa Ascento.

**Recomendaciones operativas** (las hace el dueño, sin costo):
1. Confirmar en Forge `APP_DEBUG=false`, `APP_ENV=production` y que `MERCADOPAGO_WEBHOOK_SECRET` y `TELEGRAM_WEBHOOK_SECRET` estén definidos. Revisar solo esas líneas, sin abrir ni compartir el `.env` completo.
2. Contraseña larga y única para el SuperAdmin; no compartir la cuenta.
3. Revisar de vez en cuando el registro de actividad del panel y los fallos de ingreso en el log.
4. Mantener `composer audit` y `npm audit --omit=dev` en cada actualización de dependencias.

---

## 7. Seguimiento de los riesgos abiertos (rama `etapa-11/riesgos-menores`)

| # | Estado | Detalle |
|---|---|---|
| R1 | **Aceptado, documentado** | `braces` (sin ninguna versión 3.x corregida) y `postcss-selector-parser` (corregido recién en la 7.1.6; Tailwind 3 usa la 6) vienen de `tailwindcss` 3.4.19, la última 3.x. `npm audit fix` no propone nada sin un cambio mayor, y forzar versiones con `overrides` puede romper la compilación. Solo afectan a la compilación, con el CSS y la configuración propios como entrada: no las puede disparar un atacante. Se resuelven con la migración a Tailwind 4, cuando se planifique. `@tailwindcss/vite` 4 está instalado pero no se usa (la compilación va por PostCSS con Tailwind 3) |
| R2 | **Plan + modo reporte** | Política candidata en `SecurityHeaders::policy()`, apagada por defecto (`ASCENTO_CSP_REPORT_ONLY`). 29 páginas de los cuatro perfiles con 0 violaciones en la demo local. Plan en `docs/csp.md` |
| R5 | **Propuesta** | 2FA para el SuperAdmin con lo que ya está instalado (Google2FA y QR, vía Filament 5): `docs/etapa-11-ingreso-2fa.md` |
| R6 | **Propuesta** | Protección gradual por cuenta, sin bloqueos (desafío humano, aviso al dueño de la cuenta, aviso al SuperAdmin): `docs/etapa-11-ingreso-2fa.md` |
| R7 | **Corregido** | Los pedidos con firma inválida ya no guardan filas (antes quedaban 90 días); como mucho un aviso por minuto en el log. No había exposición de datos: la tabla no se muestra en ningún lado |
| R8 | **Corregido** | Cambiar el correo desde el perfil del técnico pide la contraseña actual. Antes, con una sesión robada se cambiaba el correo y «Olvidé mi contraseña» mandaba el enlace al correo nuevo, aunque no estuviera confirmado |
