# QA de experiencia de usuario, responsive y funcionamiento

**Rama:** `qa/experiencia-responsive`, desde `main` 9cb60cd (14/10/2026). La primera parte se integró como PR #85; la corrección de la sesión vencida (sección 3) va en commits posteriores de la misma rama.

**Alcance:** toda la aplicación, en una copia local con los datos de la demo (`DemoValidationSeeder`) más casos límite. **No** se usó producción ni datos reales.

---

## 1. Cómo se probó

| Tipo | Qué se hizo |
|---|---|
| Navegador real (Chrome headless + Puppeteer, fuera del proyecto) | Recorrido automático de 554 combinaciones página × ancho y 7 perfiles. Por cada una registra: código HTTP, errores de consola y de red, texto de error visible, desborde horizontal, controles tapados por otro elemento (clics que no llegan), áreas táctiles chicas, textos recortados y botones sin nombre accesible |
| Flujos de interacción en el navegador | Crear, validar, buscar, ordenar, paginar, modales, menú en el celular, selectores con búsqueda, firma del remito, reporte con foto, filtros del portal, PDF, acceso y recuperación de contraseña. Se verifica el efecto (dato guardado, cambio de página), no solo que no haya error |
| Matriz de anchos | 34 pantallas clave × 15 anchos (320 a 1920 px), más celular horizontal (844×390) y una **aproximación** del zoom al 200 % (720 px CSS con densidad 2): 544 combinaciones |
| Teclado | Tab y Shift+Tab en ingreso, inicio público, formulario del panel, reporte del técnico y portal. Foco visible y retorno del foco al cerrar un modal |
| Pruebas automatizadas | Suite completa y pruebas nuevas en `tests/Feature/Ux/QaFixesTest.php` y `tests/Feature/Ux/ExpiredSessionTest.php` |

**Perfiles:**
- visitante;
- dueño de empresa;
- administrativo con permisos parciales;
- técnico;
- cliente del portal;
- SuperAdmin;
- dueño de una empresa vacía.

**Casos límite cargados:**
- nombres y direcciones muy largos;
- 60 órdenes de trabajo (paginación);
- 25 tareas;
- empresa sin datos.

---

## 2. Defectos corregidos

| # | Prioridad | Área | Observado | Causa | Corrección |
|---|---|---|---|---|---|
| D1 | P2 | Botón flotante de WhatsApp | En el celular quedaba sin nombre accesible | El texto se oculta con `display:none` a ≤480 px | `aria-label` |
| D2 | P2 | Servicios (crear y editar) | A 1024–1440 px, 6 campos en una fila: «Mensual», «Activo» cortados letra por letra, fecha truncada | Desde Filament 4, una `Grid` en la raíz del formulario ocupa 1 de las 2 columnas | `columnSpanFull()` en las grillas |
| D3 | P2 | Stock | «Unidad» cortada letra por letra | Misma causa que D2 | Ídem |
| D4 | P2 | Edificios | Dirección en 7 columnas, ejemplos cortados, contacto a media fila | Misma causa que D2 | Ídem |
| D5 | P2 | Mi empresa | A 1024 px, Provincia de 79 px («Seleccione una opción» en sílabas) | 12 columnas con barra lateral | El domicilio pasa a dos filas hasta 1280 px |
| D6 | **P1** | Presupuesto, ítems | El subtotal de la fila quedaba en $0,00 aunque cambiaran la cantidad o el precio (el total sí cambiaba) | Se calculaba solo al cargar | Se recalcula al cambiar cantidad o precio. Es solo visual: el servidor sigue calculando |
| D7 | P2 | Presupuesto, ítems | A 720–1024 px: concepto, precio y subtotal truncados | Tabla de 5 columnas en ~590 px | Anchos por columna y desplazamiento horizontal dentro de la tabla (sin desbordar la página) |
| D8 | P3 | Listados de reportes y técnicos | Enlaces de la celda de foto sin nombre accesible | Imagen sin `alt` dentro del enlace de la fila | `alt` en la foto del reporte; nombre oculto para lectores en la foto del técnico |
| D9 | P2 | Ficha del ascensor y detalle del remito (administrativo) | Enlaces que terminaban en 403 cuando faltaba el permiso del área | Se armaban sin consultar `canView` o `canEdit` | El enlace solo aparece si se puede abrir. **No cambian los permisos** |
| D10 | P3 | Acceso, ayuda contextual, inicio del técnico, portal | Enlaces de 16–20 px de alto, difíciles de tocar | Texto chico sin área táctil | Relleno vertical hasta ≥24 px, con márgenes compensados |

**Notas sobre D7:**
- El primer intento hacía desbordar la página entera. Lo detectó la matriz de anchos.
- La causa eran unos textos solo para lectores de pantalla, con posición absoluta y fuera del recorte de la tabla. Se resolvió con `position: relative` en el contenedor.
- **Compromiso:** entre 720 y 1024 px hay que desplazar la tabla para ver el subtotal y el botón de borrar.

---

## 3. Sesión vencida en el panel (P1, corregido con autorización)

**Síntoma:** con la sesión vencida, una acción de Livewire del panel (por ejemplo, ordenar una tabla) terminaba en **error 500** (`isSuperAdmin()` sobre null en `WorkOrderResource::getEloquentQuery`). En producción se veía un modal de error en lugar de volver al ingreso.

**Causa raíz:**
- **Cómo funciona Livewire:** en cada acción (`/livewire/update`) solo vuelve a aplicar los middlewares de la página que están marcados como **persistentes**. Para decidirlo compara el **nombre exacto de la clase** (`PersistentMiddleware::filterMiddlewareByPersistentMiddleware`).
- **Qué marca Filament:** registra como persistente su `Filament\Http\Middleware\Authenticate`.
- **Qué usa este panel:** `App\Http\Middleware\AuthenticatePanel`, una subclase con otro nombre. Por eso nunca se volvía a ejecutar en las acciones de Livewire.
- **Consecuencias:**
  - el componente se ejecutaba sin usuario;
  - tampoco se volvía a comprobar `canAccessPanel`: un usuario autenticado sin acceso al panel (un técnico) que tuviera un snapshot firmado podía ejecutar una acción del panel. La prueba lo reproduce con 200.

**Solución:** `->authMiddleware([...], isPersistent: true)` en `AscensoresAppPanelProvider`. Es la opción de Filament 5.10 para este caso: agrega `AuthenticatePanel` y `EnsureSuperAdminTwoFactor` a los middlewares persistentes de Livewire.
- **No debilita nada:** solo vuelve a aplicar, en cada acción, los mismos controles de la página. El 2FA ya se aplicaba también por el grupo `web`; repetirlo da el mismo resultado.
- **Sesión vencida:** `AuthenticatePanel` lanza `AuthenticationException`. Livewire no pide JSON (no manda `Accept: application/json`), así que Laravel responde con una **redirección al ingreso**. Livewire la sigue (`response.redirected`) y lleva la página a `/login`.
- **Después de ingresar:** el usuario entra a su inicio (`HomeRedirect`), como en cualquier ingreso. El 2FA del SuperAdmin se vuelve a pedir.

**Pruebas:** `tests/Feature/Ux/ExpiredSessionTest.php`. Usan un pedido real a `/livewire/update` con el snapshot firmado de la página, igual que el navegador.

| Prueba | Sin la corrección | Con la corrección |
|---|---|---|
| Sesión válida: la acción funciona | Pasa | Pasa |
| Sesión vencida: redirige al ingreso, no queda sesión y se puede volver a entrar y usar el panel | **Falla (500)** | Pasa |
| Usuario autenticado sin acceso al panel: 403 | **Falla (200)** | Pasa |
| SuperAdmin sin el segundo factor verificado: 403 | Pasa | Pasa |
| App del técnico y portal: la sesión vencida va al ingreso | Pasa | Pasa |

**En el navegador real** (Chrome, servidor local con el 2FA activo):
- **Dueño:**
  - con sesión válida, ordenar funciona;
  - con la sesión vencida, la acción recibe un 302 y la página va a `/login`, sin modal de error ni diálogo;
  - al volver a ingresar, el listado carga.
- **SuperAdmin con 2FA real:**
  - con el código verificado, ordenar funciona;
  - con la sesión vencida, va a `/login`;
  - al volver a ingresar, se le pide el código otra vez.

**Regresión:**
- **Suite completa:** 1200 pruebas pasan.
- **Recorrido de los 7 perfiles (552 combinaciones):** mismos hallazgos que antes del cambio, ninguno nuevo. La única diferencia es esperada: con el 2FA activo, la gestión del segundo factor da 403 en lugar de 404.
- **Flujos interactivos:**
  - panel: crear, validar, ordenar, paginar, buscar, modales y menú en el celular;
  - técnico: tomar trabajo, remito con firma y reporte con foto;
  - portal y acceso público.
  - Mismos resultados que antes.

**Riesgos y límites:**
- **No vuelve a la pantalla de antes:** después de ingresar, el usuario no vuelve a la pantalla donde estaba sino a su inicio. Es como ya funciona el ingreso; cambiarlo excede esta tarea.
- **Abonos no repetidos (observación, sin cambios):**
  - `EnsureActiveSubscription`, en la lista general del panel, tampoco es persistente. Si el abono de una empresa vence con la página abierta, las acciones de Livewire siguen hasta que la página se recarga.
  - Es una regla comercial y no se tocó: queda para decidir.

## 4. Revisado y sin cambios (con motivo)

- **Pantallas con 403 o 404:**
  - Mantenimientos e inspecciones no se crean a mano: `canCreate()` en `false`, y la interfaz no muestra el botón.
  - Telegram para el técnico.
  - Las áreas sin permiso del administrativo.
  - `/seguridad/segundo-factor` con el 2FA apagado en local.
- **Consola, `InvalidStateError … ViewTransition`:** lo genera el navegador al abortar una transición de página de `@view-transition` en una redirección después de un POST. No afecta a la navegación ni lo ve el usuario.
- **Controles del núcleo de Filament de menos de 24 px:**
  - encabezados para ordenar (20 px de alto, anchos);
  - «Limpiar selección» (16×16);
  - «Quitar filtro» (20×20).
  - Son de Filament y no se tocaron.
- **La barra superior del panel tapa el borde del ítem activo de la barra lateral** cuando Filament la desplaza hasta él. Es el comportamiento estándar de Filament.
- **Guía de bienvenida de una empresa nueva:** tapa la pantalla a propósito. En el celular entra completa, se cierra al primer toque y no vuelve a aparecer.

---

## 5. Limitaciones

- **Sin celulares reales ni Safari:** el navegador es Chrome en modo celular emulado (táctil y ancho). No se probaron iOS, Safari ni Firefox.
- **Teclado virtual:** no se puede reproducir en Chrome headless.
- **Zoom al 200 %:** aproximado por ancho CSS, no con el zoom real del navegador.
- **SuperAdmin:** se recorrió con el 2FA apagado solo en el proceso local. Las pantallas del 2FA se probaron con el control activo en la Etapa 11.
- **Cobertura:** no se probaron todas las combinaciones de todas las pantallas. Se priorizaron los componentes compartidos y se amplió donde aparecieron defectos.
- **Tiempo real:** se levantó Reverb en local. No se probaron los avisos push en un teléfono.
