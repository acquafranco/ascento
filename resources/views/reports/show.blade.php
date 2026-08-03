@php
    use Illuminate\Support\Facades\Storage;
@endphp

<x-app-layout>

<div class="max-w-4xl mx-auto px-4 py-6" style="padding-bottom: 100px;">

    <div class="mb-6">
        <a href="{{ route('reports.index', ['company' => $company->slug]) }}"
           class="text-sm text-blue-600 font-semibold">
            ← Volver a mis reportes
        </a>

        <div class="flex items-center justify-between gap-3 mt-3">

            <h1 class="text-2xl font-black text-black flex items-center gap-2">
                📋 Detalle del reporte
            </h1>

        </div>

        <p class="text-gray-500 mt-2">
            Información completa del incidente
        </p>
    </div>


    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">

        <div class="flex items-center justify-between gap-3 mb-4">

            <h2 class="text-xl font-black text-black">
                {{ $report->building?->name ?? 'Edificio sin asignar' }}
            </h2>

            <div class="text-right text-xs text-gray-500 whitespace-nowrap">
                <div class="font-bold text-black">
                    {{ $report->created_at->format('d/m/Y') }}
                </div>
                <div>
                    {{ $report->created_at->format('H:i') }} hs
                </div>
            </div>

        </div>

        <div class="text-sm text-gray-500 mt-1">
            📍 {{ $report->building?->address ?? 'Sin dirección' }}
        </div>


        <div class="mt-4 grid grid-cols-2 gap-3">

            <div class="bg-slate-50 rounded-xl p-3">
                <div class="text-gray-500 text-xs">
                    Ascensor
                </div>
                <div class="font-bold text-black">
                    {{ $report->elevator_number ?? '—' }}
                </div>
            </div>

            <div class="bg-slate-50 rounded-xl p-3">
                <div class="text-gray-500 text-xs">
                    Prioridad
                </div>
                <div class="font-bold text-black">
                    {{ ucfirst($report->priority) }}
                </div>
            </div>

        </div>


        <div class="mt-4 bg-slate-50 rounded-xl p-4">
            <div class="text-gray-500 text-xs mb-2">
                Descripción
            </div>

            <div class="text-black">
                {{ $report->description }}
            </div>
        </div>


        @if($report->photo)
            <div class="mt-5">
                <div class="text-gray-500 text-xs mb-2">
                    Imagen adjunta
                </div>

                <img src="{{ url('storage/' . $report->photo) }}"
                     loading="lazy"
                     class="rounded-2xl border border-slate-200 w-40 h-40 object-cover cursor-pointer"
                     onerror="this.style.display='none';">
            </div>
        @endif


    </div>

</div>

</x-app-layout>
