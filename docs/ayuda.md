# Menú, ayuda y Centro de ayuda de Ascento

## Menú del panel (categorías plegables)

| Ubicación | Opciones (según plan y permisos) |
|---|---|
| Arriba, siempre visibles | Mi empresa (solo el dueño; es la pantalla con la que abre el panel, como antes), Inicio, Centro de atención, Indicadores, Ayuda |
| **Gestión** | Clientes, Edificios, Ascensores, Mapa, QR públicos, Historial de contactos, Tareas |
| **Operaciones** | Agenda, Mantenimientos, Inspecciones, Órdenes de trabajo, Remitos, Reportes, Pedidos de ayuda, Guardias, Pruebas de paracaídas, Reparaciones, Stock |
| **Comercial** | Presupuestos, Servicios, Cobranzas |
| **Administración** | Técnicos, Administrativos, Mi suscripción, Exportar datos |
| **Plataforma** (solo SuperAdmin) | Empresas, Suscripciones, Planes, Backups |

- Solo cambió el grupo y el orden de cada pantalla (`$navigationGroup`, `$navigationSort`). Rutas, permisos y planes, igual: cada pantalla sigue verificando el acceso en el servidor.
- **Plegables** (`filament.partials.sidebar-state`): arrancan cerradas, salvo la categoría de la pantalla actual y «Operaciones» si hay un pedido de ayuda abierto. Una categoría cerrada con avisos muestra cuántas opciones tienen número.
- **Persistencia:** en el navegador (`localStorage`), con una clave por usuario y panel (`NavigationState::storageKey`, HMAC con la clave de la app). Solo guarda los nombres de las categorías abiertas. Sin almacenamiento disponible (`storage-fallback`), el menú funciona igual y no recuerda nada.
- La app del técnico y el portal tienen su propia barra de pestañas, corta: no cambian.

## Ayuda dentro de la aplicación

| Qué | Para quién | Cómo |
|---|---|---|
| Introducción breve | Dueño y administrativos vigentes | Se abre sola la primera vez (`AdminOnboarding`). Dueño: menú, primeros pasos y «?»; administrativo: menú y «?» |
| Botón «?» | Dueño y administrativos | En esta pantalla (texto + guías de `HelpTopics::screenGuides`), «Recorrer el menú» por categoría, ver la introducción, Centro de ayuda |
| Recorrido del menú | Dueño y administrativos | `NavigationGuide`: explica cada opción que ESA persona ve. Abre la categoría si está cerrada y la vuelve a cerrar al terminar; nunca señala algo oculto |
| Tarjetas de cada pantalla | Dueño y administrativos | `HelpTip`, hasta tocar «Entendido» |
| Bienvenida del técnico | Técnicos | Tarjeta en el Inicio, hasta tocar «Entendido» |
| Bienvenida del portal | Usuarios del portal | Tarjeta en el Inicio, hasta tocar «Entendido» |

**Estado de la introducción** (`users.onboarding_completed_at` / `onboarding_skipped_at`, del propio usuario):
- *Completada:* «Terminar» en el último paso (o un primer paso del cierre).
- *Descartada:* «Omitir», la X o Esc.
- En los dos casos no vuelve a abrirse sola. Abrirla desde «?» no cambia el estado. Ver páginas, recargar o un error de JavaScript no marcan nada: solo esos botones llaman al servidor. Si sigue un enlace en medio de la introducción, se retoma en el mismo paso.
- Las bienvenidas del técnico y del portal usan `help_dismissals` (claves `welcome_technician` y `welcome_portal`) con un formulario: funcionan sin JavaScript.

## Centro de ayuda

- **Dónde:** panel → «Ayuda» (`/admin/ayuda`), app del técnico → «Más» → «Ayuda», portal → «Ayuda».
- **Contenido:** `app/Support/Help/Content/` (un archivo por perfil y `HelpFaq`). Cada artículo: clave estable, categoría, título, para qué sirve, antes de empezar, pasos, qué sucede después, notas, problemas frecuentes, relacionados, palabras clave, y opcionalmente orden sugerido (`flow`), captura (`image`) y «Abrir la sección» (`open`).
- **Quién ve qué:** `HelpGuides::allows` (perfil, plan, área de permisos, solo dueño, solo SuperAdmin). Los relacionados, las preguntas frecuentes y «Abrir la sección» se ocultan si la persona no puede usarlos.
- **Búsqueda** (`HelpSearch`, en memoria): sin acentos, palabras incompletas, plurales, otras formas del verbo y sinónimos del rubro; pesa más el título. En el técnico y el portal es un formulario `?q=` (sin JavaScript).
- **Enlaces directos:** `?guia=clave`, `?tema=categoría`, `?desde=/admin/…` (solo rutas internas del panel: «Volver a donde estabas»).
- **Capturas:** `public/images/help/*.webp`, con datos de ejemplo (el QR está difuminado).

## Cómo agregar o corregir una guía

1. Editá el artículo en `Content/PanelGuides.php` (o el del perfil). Describí lo que la pantalla hace hoy, con las etiquetas como se ven.
2. Si es una pantalla nueva: su explicación corta en `NavigationGuide::items()`, sus guías en `HelpTopics::SCREEN_GUIDES` y, si corresponde, su tarjeta en `HelpTopics`.
3. `MenuAndHelpCenterTest` y `HelpGuidesTest` verifican que todo enlace apunte a contenido que existe y que cada opción del menú tenga explicación.

## Landing

`partials/landing-connect-diagram`: diagrama SVG + CSS en «El cuidado del ascensor conecta a todos». Corre una vez al entrar en pantalla; sin JavaScript o con movimiento reducido se ve completo y quieto. El pedido de ayuda va punteado, con «si la empresa lo activa», y solo si algún plan lo incluye.
