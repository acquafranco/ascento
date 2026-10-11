/**
 * Páginas públicas (inicio, ingreso/registro, legales). Misma configuración
 * que antes se cargaba en el navegador con el CDN de Tailwind; ahora se
 * compila con Vite (resources/css/public.css). La app del técnico usa
 * tailwind.config.js.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './resources/views/welcome.blade.php',
        './resources/views/layouts/guest.blade.php',
        './resources/views/legal/**/*.blade.php',
        './resources/views/auth/**/*.blade.php',
        './resources/views/portal/**/*.blade.php',
        './resources/views/components/**/*.blade.php',
        './resources/views/partials/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                ink: '#14171C',
                paper: '#F7F7F4',
                graphite: '#12151C',
                graphite2: '#1B1F29',
                amber: { 100: '#FFE8D6', 400: '#FF8A3D', 500: '#FF6A1A', 600: '#E85A0A', 700: '#C24800' },
                rail: { 100: '#E7ECFB', 400: '#5A78D6', 500: '#2E4FBE', 600: '#233D99' },
            },
            fontFamily: {
                display: ['"Space Grotesk"', 'sans-serif'],
                body: ['Inter', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'monospace'],
            },
            boxShadow: {
                card: '0 1px 2px rgba(20,23,28,0.04), 0 8px 24px -8px rgba(20,23,28,0.10)',
                cardHover: '0 4px 10px rgba(20,23,28,0.06), 0 20px 40px -12px rgba(20,23,28,0.18)',
            },
        },
    },
    plugins: [],
};
