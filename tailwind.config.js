import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Identidad de Ascento (app del técnico).
            colors: {
                brand: { 50: '#FFF4EC', 100: '#FFE6D5', 200: '#FFC9A3', 500: '#FF6600', 600: '#E85D00', 700: '#B84A00', DEFAULT: '#FF6600' },
                ink: { 50: '#F4F5F7', 100: '#E6E8EC', 300: '#A6ACB8', 500: '#5B6472', 700: '#2A313C', 900: '#11161D', DEFAULT: '#11161D' },
                // Usados por componentes compartidos con las páginas públicas
                // (botón principal, campos): sin esto no se veían en la app.
                graphite: '#12151C',
                graphite2: '#1B1F29',
                paper: '#F7F7F4',
                rail: { 100: '#E7ECFB', 400: '#5A78D6', 500: '#2E4FBE', 600: '#233D99' },
            },
            boxShadow: {
                card: '0 1px 2px rgba(20,23,28,0.04), 0 8px 24px -8px rgba(20,23,28,0.10)',
                cardHover: '0 4px 10px rgba(20,23,28,0.06), 0 20px 40px -12px rgba(20,23,28,0.18)',
            },
        },
    },

    plugins: [forms],
};
