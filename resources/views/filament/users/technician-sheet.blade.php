{{-- Planilla de un técnico en el panel (TechnicianSheetPage): la vista compartida con la app. --}}
<x-filament-panels::page>
    <div style="max-width: 900px; width: 100%; margin: 0 auto;">
        @include('planilla.sheet', ['sheet' => $sheet, 'self' => false, 'navUrl' => $navUrl, 'remitoUrl' => $remitoUrl])
    </div>
</x-filament-panels::page>
