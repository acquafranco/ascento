# Lanzamiento comercial: países, catálogo público y decisiones pendientes

Rama `mejora/marketplace-lanzamiento`, 23/10/2026. Sin merge, sin deploy y sin cambios en producción.

## 1. Registro por país (SuperAdmin → Plataforma → Configuración de la plataforma)

- **Única fuente:** `App\Support\RegistrationCountries`. La usan:
  - el registro de empresas (`RegisteredUserController`);
  - el registro de proveedores (`SupplierRegistrationController`);
  - «Mi empresa» (`CompanySettings`).
- **Switch apagado (por defecto, `platform_settings.international_registration_enabled = false`):**
  - solo Argentina;
  - los formularios no muestran el selector;
  - el servidor asigna `AR`;
  - un `country` distinto enviado a mano se rechaza con un error de validación.
- **Switch encendido:**
  - Argentina más los países marcados uno por uno (`registration_countries`, códigos de `Countries::LATAM`);
  - sin países marcados, sigue siendo solo Argentina y no aparece ninguna opción;
  - el selector muestra solo los habilitados y avisa que eso no habilita pagos ni facturación.
- **No cambia ninguna cuenta existente.** En «Mi empresa», una empresa conserva su país aunque ya no esté habilitado, pero solo puede pasarse a un país habilitado.
- **Otras altas:**
  - las empresas creadas por el SuperAdmin usan el valor por defecto de la base (`AR`);
  - los usuarios invitados y los del portal no tienen país propio;
  - los proveedores no pueden cambiar su país (se pide por correo).
- **Seguridad:** solo el SuperAdmin entra a la página, y cada cambio queda en la auditoría de plataforma (`platform.registration.updated`).
- **Cobros:** habilitar el registro de un país **no** habilita cobros. Los cobros a proveedores siguen limitados a `config/marketplace.php` → `gateways` (hoy, Argentina con Mercado Pago).

## 2. Catálogo público de repuestos

| Ruta | Qué muestra |
|---|---|
| `/marketplace` | Página comercial, con buscador, rubros con productos y lo último publicado |
| `/marketplace/repuestos` | Listado con búsqueda, filtros (rubro, marca, disponibilidad, precio) y páginas de 24 productos |
| `/marketplace/repuestos/{id}/{nombre}` | Ficha con galería, datos, compatibilidad declarada, relacionados y otros del proveedor. Si el nombre no coincide, redirige (301) a la dirección correcta |
| `/marketplace/proveedores/{slug}` | Perfil comercial y catálogo del proveedor |
| `/marketplace/imagenes/{foto}` | Fotos de productos **visibles** (en cualquier otro caso, 404). La ruta privada `/marketplace/fotos/{foto}` sigue igual |
| `/sitemap.xml` | Páginas públicas, productos visibles y proveedores con productos visibles |

- **Qué cuenta como visible:** se usa siempre `SupplierProduct::visible()`, con la misma búsqueda que el panel (`CatalogSearch` + `CatalogRanking`). Visible es publicado, no oculto por moderación, de un proveedor aprobado y sin cerrar, y dentro del límite de su plan.
- **Nunca se muestran:**
  - el correo, el teléfono, la razón social ni la identificación fiscal del proveedor;
  - pedidos, compradores ni notas.
- **Siguen pidiendo cuenta:**
  - pedir cotización y favoritos (en el panel);
  - el área del proveedor.
- **Interruptor:** el SuperAdmin lo apaga en «Configuración del marketplace → Catálogo público» (`marketplace_settings.public_catalog_enabled`, encendido por defecto). Apagado:
  - el listado vuelve a `/marketplace`;
  - las fichas, los perfiles y las fotos dan 404;
  - el sitemap no lista productos.
- **SEO:**
  - título, descripción, `canonical` y Open Graph por página;
  - JSON-LD de producto (con oferta solo si hay precio publicado);
  - `noindex, follow` en búsquedas y filtros;
  - `robots.txt` excluye `/admin`, `/portal`, `/proveedores/` (salvo el registro), `/api/`, `/qr/`, los remitos públicos y las fotos privadas.
- **Welcome:**
  - enlace «Marketplace» en la barra superior (escritorio) y en el menú del celular;
  - en la sección del marketplace, «Ver repuestos sin cuenta».

## 3. Planes y precios

- **Empresas:** los tres planes (`subscription_plans`: Inicial 69.000, Profesional 119.000, Empresa 169.000 ARS, prueba de 30 días) **no se tocaron**. `CommercialSeparationTest` lo verifica.
- **Proveedores:**
  - identificadores propios `proveedor-gratuito` y `proveedor-premium` (`MarketplaceSettings::*_PLAN_CODE`), en `marketplace_settings` y `supplier_subscriptions`, nunca en `subscription_plans`;
  - publicación gratuita hasta 20 productos y Premium hasta 1.000 (sin cambios: `PublicationLimits`);
  - si vence Premium, nada se borra: lo que excede queda «fuera del límite» y vuelve a publicarse con lugar.
- **Precios objetivo** (Premium ARS 19.900 por mes; destacados ARS 4.900 por 7 días y 9.900 por 30 días):
  - están en `config/marketplace.php` → `target_prices`;
  - **no se cargan solos**: en «Configuración del marketplace», «Usar precios objetivo» completa el formulario y hay que guardar;
  - las ventas siguen apagadas hasta encenderlas, con Mercado Pago verificado;
  - los ARS 5.000 / 15.000 que trajo la migración 2027_01_18 eran provisorios.
- **Mercado Pago:** ver `docs/mercado-pago-produccion.md`.

## 4. Capturas de la Welcome

Se recapturaron las 16 con la app actual y datos ficticios (`docs/landing-capturas.md`):
- el carrusel del celular pasó de 7 a 10;
- se sumaron el detalle de la orden, el remito digital firmado y las pruebas de paracaídas del técnico.

Lo que no se capturó y por qué:
- **Detalle de un reporte con fotos:** las fotos de la demo son marcadores grises.
- **Mapa con puntos:** la demo no geocodifica sin la clave de Geoapify.

## 5. Legales: estado y datos que tenés que completar

- **Aviso de borrador:** `LEGAL_REVIEWED` sigue en `false`, así que todas las páginas muestran «Borrador pendiente de revisión legal». No se simula ninguna aprobación.
- **Textos condicionados a la configuración** (versión 23/10/2026):
  - países donde se acepta el registro (Términos, sección 01, y Privacidad);
  - cobros solo con Mercado Pago de Argentina, sin moneda ni comprobantes de otros países (sección 10);
  - catálogo público, si está encendido (sección 17, y en Privacidad, los datos de proveedores y con quién se comparten);
  - «Otros países» para proveedores (sección 18);
  - «Precio vigente» de Premium y destacados solo si de verdad se pueden cobrar.

**Datos y decisiones pendientes (no se inventaron):**
1. **Titular de Ascento:** `LEGAL_OWNER_NAME` (razón social), `LEGAL_OWNER_CUIT`, `LEGAL_OWNER_ADDRESS` y, si corresponde, `LEGAL_EMAIL`. Hoy figuran como «pendiente de completar».
2. **Revisión profesional** de Términos, Privacidad, Cookies y Eliminación de datos. Después, `LEGAL_REVIEWED=true`.
3. **Facturación** de suscripciones, Premium y destacados: no está implementada.
4. **Política de reembolsos:** la sección 18 dice «se revisa cada pedido». Falta definir plazos y casos, en particular un destacado cancelado por moderación.
5. **Empresas de otros países que ya existen:** si pueden contratar online (hoy el checkout no mira el país y cobra en ARS).
6. **Antes de abrir un país:** qué normativa de datos personales, defensa del consumidor y fiscal aplica.
7. **Avisar a los proveedores existentes** que su catálogo pasa a ser visible sin cuenta (cambian los Términos, sección 17), o apagar el catálogo público hasta hacerlo.
