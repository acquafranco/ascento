<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6" style="padding-bottom: 100px;">

    {{-- HEADER --}}
    <div class="flex justify-between items-start gap-4 mb-6">

        <div>
            <h1 class="text-3xl font-black text-black flex items-center gap-2">
                📋 Mis reportes
            </h1>

            <p class="text-gray-500 mt-1">
                Historial de incidencias registradas
            </p>
        </div>

        <a href="{{ route('reports.create', ['company' => $company->slug]) }}"
           class="px-4 py-3 rounded-2xl text-white font-bold shadow-sm whitespace-nowrap"
           style="background:#2563EB;">
            + Nuevo
        </a>

    </div>


    {{-- LISTA --}}
    <div class="space-y-4">

        @forelse($reports as $report)

            <a href="{{ route('reports.show', ['company' => $company->slug, 'report' => $report->id]) }}"
               class="block bg-white rounded-3xl border border-slate-200 shadow-sm p-5 hover:shadow-md transition">

                <div class="flex justify-between items-start gap-3">

                    <div>
                        <h2 class="text-xl font-black text-black">
                            {{ $report->building?->name ?? 'Edificio sin asignar' }}
                        </h2>

                        <div class="text-sm text-gray-500 mt-1">
                            📍 {{ $report->building?->address ?? 'Sin dirección' }}
                        </div>
                    </div>

                    <div class="text-right text-xs text-gray-500 whitespace-nowrap">
                        <div class="font-bold text-black">
                            {{ $report->created_at->format('d/m/Y') }}
                        </div>
                        <div>
                            {{ $report->created_at->format('H:i') }} hs
                        </div>
                    </div>

                </div>


                <div class="mt-4 grid grid-cols-2 gap-3">

                    <div class="bg-slate-50 rounded-2xl p-3">
                        <div class="text-xs text-gray-500">
                            Ascensor
                        </div>

                        <div class="font-black text-black">
                            🛗 {{ $report->elevator_number ?? 'No indicado' }}
                        </div>
                    </div>


                    <div class="bg-slate-50 rounded-2xl p-3">
                        <div class="text-xs text-gray-500">
                            Prioridad
                        </div>

                        <div class="font-black">
                            @if($report->priority === 'critica')
                                <span class="text-red-600">🔴 Crítica</span>
                            @elseif($report->priority === 'alta')
                                <span class="text-orange-600">🟠 Alta</span>
                            @elseif($report->priority === 'media')
                                <span class="text-yellow-600">🟡 Media</span>
                            @else
                                <span class="text-green-600">🟢 Baja</span>
                            @endif
                        </div>
                    </div>

                </div>


                <div class="mt-4 flex justify-between items-center gap-3">

                    <div class="text-xs text-gray-500">
                        Estado: {{ ucfirst($report->status) }}
                    </div>


                    <div class="text-sm text-blue-600 font-bold whitespace-nowrap">
                        Ver detalle →
                    </div>

                </div>

            </a>

        @empty

            <div class="text-center text-gray-500 py-10">
                Todavía no realizaste ningún reporte.
            </div>

        @endforelse

    </div>


    <div class="mt-6">
        {{ $reports->links() }}
    </div>

</div>

</x-app-layout>
