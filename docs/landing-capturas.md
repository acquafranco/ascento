# Capturas de la página de inicio

El carrusel "Vista previa" de la página de inicio usa **capturas reales de la
app** con **datos ficticios** de la base de demo, nunca de producción. Están en
`public/images/landing/`:

- **Teléfono** (técnico y portal): 640 px de ancho, todas con la misma proporción.
- **Escritorio** (panel de la empresa): 1280×800 (16:10).
- Son JPEG livianos, de 44 a 112 KB cada uno, y se cargan solo al llegar a esa parte de la página.

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
