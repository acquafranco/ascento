# Capturas de la página de inicio

El carrusel "Vista previa" de la página de inicio usa **capturas reales de la
app** con **datos ficticios** de la base de demo, nunca de producción. Están en
`public/images/landing/`:

- **Teléfono** (técnico y portal): 640 px de ancho, todas con la misma proporción.
- **Escritorio** (panel de la empresa): 1280×800 (16:10).
- Son JPEG livianos, de 44 a 112 KB cada uno, y se cargan solo al llegar a esa parte de la página.
- La portada (arriba de todo) **no** es una captura: es la ilustración animada original (indicador de piso). El dueño decidió conservarla el 13/10/2026. Sus números son decorativos.

## Qué se captura

- **Teléfono:**
  - inicio, trabajos, visitas y nuevo reporte del técnico;
  - inicio y documentos del portal;
  - el QR público de Torre Libertador. El script toma el enlace del panel, porque el token es aleatorio, y lo abre sin sesión.
- **Escritorio:** inicio del panel, mapa, un presupuesto, componentes en reparación, pruebas de paracaídas e indicadores.
- **Datos de demostración:** `DemoValidationSeeder` crea también, con datos ficticios, el QR con sus bloques, las pruebas de paracaídas (una próxima y otra al día) y dos componentes en reparación.
- **Login:** el script espera 3,5 segundos antes de enviar el formulario, porque la protección contra bots rechaza los envíos instantáneos.

## Cuándo actualizarlas

Después de cada cambio visual en el inicio del técnico, Trabajos, Visitas,
Nuevo reporte, el portal, el inicio del admin, el mapa o los presupuestos.

## Cómo

1. Base de demo local, nunca producción:
   ```bash
   php artisan migrate:fresh --seed
   php artisan db:seed --class=DemoValidationSeeder
   ```
   El seeder se niega a correr en producción.
2. `php artisan serve --port=8766` con esa base.
3. En tu máquina: `npm i -D puppeteer-core`. No es una dependencia de la app.
4. Capturar:
   ```bash
   BASE_URL=http://127.0.0.1:8766 \
   CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
   OUT=storage/app/landing-raw node tools/landing-screenshots/capture.cjs
   ```
   El script entra con las cuentas de demo. Antes de capturar oculta los
   avisos que se descartan con un toque (activar push, ayudas) y el acceso de
   WhatsApp.
5. Optimizar y copiar a `public/images/landing/`:
   ```bash
   php artisan landing:optimize-screenshots storage/app/landing-raw
   ```
6. Antes de publicar, revisá que no se vean correos, teléfonos ni datos reales.

Para agregar o quitar una pantalla, editá la lista `shots` del script y los
arreglos `$phoneShots` y la lista de escritorio en `resources/views/welcome.blade.php`.
`tests/Feature/Public/LandingScreenshotsTest.php` falla si falta una imagen
referenciada o si pesa más de 200 KB.

## Marketplace de repuestos (`public/images/marketplace/`)

La sección «Marketplace de repuestos» de la Welcome y la página `/marketplace`
usan capturas **reales** del catálogo, la ficha y el área del proveedor, tomadas
de una base de demo local con **datos ficticios**: «Ascensores Demo», tres
proveedores «Demo» y la marca «Marca Demo». Las imágenes de los productos son
**dibujos** generados para la demo (no fotos de productos reales) y se cargan con
el mismo procesador de fotos de la app (JPEG sin metadatos, miniatura).

| Archivo | Qué muestra | Tamaño |
|---|---|---|
| `catalogo.jpg` | Buscador, filtros y tarjetas | 1080×860 |
| `catalogo-filtros.jpg` | Filtro por rubro activo y «Limpiar filtros» | 1080×690 |
| `ficha.jpg` | Ficha con galería (4 fotos), datos y compatibilidad | 1080×640 |
| `ficha-movil.jpg` | La misma ficha en el celular (390 px, 2x) | 780×1440 |
| `proveedor-fotos.jpg` | Fotos del producto en el área del proveedor | 1080×468 |

Cada una pesa menos de 200 KB (lo verifica `MarketplaceLandingTest`).

Para regenerarlas, con una base de demo local (nunca producción):

```bash
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" node tools/marketplace-screenshots/product-images.cjs /tmp/mk-img
php artisan migrate:fresh --seed
DEMO_IMG=/tmp/mk-img php artisan tinker --execute="require 'tools/marketplace-screenshots/seed.php';"
php artisan serve --port=8766
BASE_URL=http://127.0.0.1:8766 CHROME="…" node tools/marketplace-screenshots/capture.cjs public/images/marketplace
```

El seeder se niega a correr en producción. Usuarios de la demo: `demo@empresa.test`
(empresa) y `demo@norte.test` (proveedor), con la contraseña que figura en `seed.php`.
