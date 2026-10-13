{{-- Saludo del inicio (sin datos de la empresa): dueño, administrativos y SuperAdmin con empresa elegida. --}}
<x-filament-widgets::widget>
    <div style="border-radius:1.25rem; padding:1.25rem 1.5rem; color:#fff; background:linear-gradient(90deg,#0f172a,#1e293b 55%,#334155); box-shadow:0 10px 25px -10px rgba(15,23,42,.45)" data-welcome>
        <p style="margin:0; font-size:1.5rem; font-weight:800; line-height:1.2">Bienvenido, {{ auth()->user()->name }} 👋</p>
        <p style="margin:.35rem 0 0; color:#cbd5e1; font-size:.95rem">{{ ucfirst(now()->translatedFormat('l d \d\e F \d\e Y')) }}</p>
    </div>
</x-filament-widgets::widget>
