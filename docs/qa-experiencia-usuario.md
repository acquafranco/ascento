# QA de experiencia de usuario, responsive y funcionamiento

**Rama:** `qa/experiencia-responsive`, desde `main` 9cb60cd (14/10/2026).

**Alcance:** toda la aplicación, en una copia local con los datos de la demo (`DemoValidationSeeder`) más casos límite. **No** se usó producción ni datos reales.

---

## 1. Cómo se probó

| Tipo | Qué se hizo |
|---|---|
| Navegador real (Chrome headless + Puppeteer, fuera del proyecto) | Recorrido automático de 554 combinaciones página × ancho y 7 perfiles. Por cada una registra: código HTTP, errores de consola y de red, texto de error visible, desborde horizontal, controles tapados por otro elemento (clics que no llegan), áreas táctiles chicas, textos recortados y botones sin nombre accesible |
| Flujos de interacción en el navegador | Crear, validar, buscar, ordenar, paginar, modales, menú en el celular, selectores con búsqueda, firma del remito, reporte con foto, filtros del portal, PDF, acceso y recuperación de contraseña. Se verifica el efecto (dato guardado, cambio de página), no solo que no haya error |
| Matriz de anchos | 34 pantallas clave × 15 anchos (320 a 1920 px), más celular horizontal (844×390) y una **aproximación** del zoom al 200 % (720 px CSS con densidad 2): 544 combinaciones |
| Teclado | Tab y Shift+Tab en ingreso, inicio público, formulario del panel, reporte del técnico y portal. Foco visible y retorno del foco al cerrar un modal |
| Pruebas automatizadas | Suite completa y pruebas nuevas en `tests/Feature/Ux/QaFixesTest.php` |

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

## 3. Pendiente de aprobación

| Prioridad | Qué pasa | Por qué no se corrigió acá | Propuesta |
|---|---|---|---|
| **P1** | Panel con la sesión vencida: una acción de Livewire (por ejemplo, ordenar una tabla) llega al componente sin usuario y da **error 500** (`WorkOrderResource::getEloquentQuery`: `isSuperAdmin()` sobre null). En producción se vería un modal de error en lugar de volver al ingreso. Del lado del técnico y del portal, en cambio, se vuelve al ingreso sin problemas | Toca el mecanismo de autenticación del panel: esta etapa no cambia la seguridad | `->authMiddleware([...], isPersistent: true)` en el panel, para que Filament vuelva a autenticar cada pedido de Livewire. Con una prueba que reproduzca el 500 antes |

---

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
