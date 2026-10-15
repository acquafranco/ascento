# Ubicación de los técnicos

Pestaña **Mapa → Técnicos** en el panel y sección **Mi perfil → Ubicación** en la app del técnico.

## Qué se implementó

- **Voluntaria y revocable.** Solo el técnico la activa («Activar ubicación») o la desactiva. Ningún administrador puede activarla por él: no hay ruta ni acción del panel que escriba en `technician_locations`.
- **Dos condiciones, explicadas por separado en el perfil:**
  1. la activación en Ascento (se guarda en el servidor);
  2. el permiso del navegador del celular (lo pide solo el botón «Permitir ubicación en este celular»).
  Nunca se pide permiso al cargar una página.
- **Solo la última posición** (`technician_locations`, una fila por técnico). Sin historial de recorridos.
- **Se borra la posición:**
  - al desactivar;
  - al cerrar sesión (listener de `Logout`);
  - a las 12 h sin novedades (`location:prune`, cada hora).
- **Estados para el administrador** (`TechnicianLocation::status()`):
  - En línea: posición de los últimos 5 minutos.
  - Ubicación desactualizada: la última que llegó, en gris y con su hora.
  - Sin ubicación.
  - Ubicación desactivada.
  - Permiso pendiente.
  - Permiso denegado.
  - Error de actualización: el celular informó «timeout», «unavailable», «insecure» o «unsupported».
- **Actualización del mapa:** cada 30 s, solo con la pestaña visible, con una llamada `#[Renderless]` que hace dos consultas para todos los técnicos. Sin WebSockets nuevos, Redis ni servicios pagos.

Configuración: `config/location.php`:

| Clave | Valor por defecto |
|---|---|
| Intervalo de envío | 60 s |
| Vigencia | 5 min |
| Mínimo entre envíos | 20 s |
| Conservación | 12 h |
| Precisión máxima | 5000 m |
| Precisión «aproximada» | más de 150 m |
| Refresco del mapa | 30 s |

## Endpoints (app del técnico, sesión autenticada)

| Método y ruta | Qué hace | Límite |
|---|---|---|
| `POST /{empresa}/ubicacion/activar` | Activa en Ascento | 20/min |
| `POST /{empresa}/ubicacion/desactivar` | Desactiva y borra la posición | 20/min |
| `POST /{empresa}/ubicacion` | `latitude`, `longitude`, `accuracy` | 6/min, más el mínimo de 20 s entre envíos |
| `POST /{empresa}/ubicacion/estado` | Permiso o error, sin coordenadas | 20/min |

- Todos exigen sesión, el grupo de middleware del técnico y CSRF.
- El usuario y la empresa salen de la sesión; los `user_id` y `company_id` del pedido se ignoran.
- Se rechazan las coordenadas fuera de rango, el (0, 0) y una precisión mayor a 5 km.
- Las respuestas nunca repiten las coordenadas.

Panel: `BuildingsMap::technicianLocations()` (Livewire). Exige que la persona pueda abrir el mapa (área «Edificios» y plan con Mapa) y tenga «Técnicos». La empresa sale de `CompanyContext`.

## Seguimiento en segundo plano: qué es posible

**Probado:** el seguimiento con la página abierta y visible. Lo verifiqué en Chrome con una posición simulada: activar, dar permiso, enviar la posición, verla en el mapa y desactivar.

**No probado:** la pantalla bloqueada y el segundo plano. No hay forma de lograrlo de manera fiable desde la web, como se explica a continuación.

- **La API de geolocalización web** (`watchPosition`) entrega posiciones solo mientras la página está activa:
  - **iOS (Safari y app agregada a la pantalla de inicio):** al bloquear la pantalla o pasar a otra app, la página queda suspendida y no llegan posiciones.
  - **Android (Chrome):** las pestañas en segundo plano se congelan o se limitan, y `watchPosition` deja de entregar posiciones con la pantalla apagada.
- **Los service workers no tienen acceso a `navigator.geolocation`.**
- **«Periodic Background Sync»** existe solo en Chrome con la PWA instalada, con intervalos de horas, y tampoco da ubicación.
- **No existe una API web de «geolocalización en segundo plano»** disponible en los navegadores.

Por eso el tracker (`resources/js/tech-location.js`):
- deja de pedir posiciones cuando la página se oculta y retoma al volver;
- nunca envía posiciones inventadas ni encola posiciones viejas.

El perfil y el mapa dicen «solo mientras Ascento está abierto en pantalla».

## Si se necesita seguimiento continuo: aplicación móvil

**Se necesita una app nativa o híbrida.** No está en este repositorio ni se agregó ninguna dependencia.

**Opción sugerida:** un contenedor del mismo sitio web (por ejemplo Capacitor) con un plugin de geolocalización en segundo plano.

- **Android:**
  - servicio en primer plano con notificación persistente;
  - permisos `ACCESS_BACKGROUND_LOCATION` y `FOREGROUND_SERVICE_LOCATION`;
  - Google Play exige justificar el uso de la ubicación en segundo plano.
- **iOS:**
  - autorización «Siempre» y modo de fondo «location updates», o el servicio de «cambios significativos», más económico en batería;
  - la App Store revisa la justificación.

**Backend reutilizable:** el mismo `TechnicianLocationService`. Faltaría:
1. autenticación por token para la app (por ejemplo Laravel Sanctum, que hoy no está instalado);
2. una ruta de API equivalente a `POST /{empresa}/ubicacion`, con el mismo servicio, validación y límites;
3. el mismo consentimiento: el interruptor de Ascento sigue mandando.

**Costos posibles a evaluar:**
- cuentas de desarrollador de Apple (USD 99/año) y Google (USD 25 una vez);
- algunos plugins de segundo plano tienen licencia paga para Android.

No se agregó nada de esto sin aprobación.
