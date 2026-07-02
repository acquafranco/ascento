<style>
html,
body{
    overflow:hidden;
    height:100%;
}
</style>
<x-app-layout>

<div class="fixed inset-0 top-16 bottom-20 lg:bottom-0 overflow-hidden barra menu">
<div class="grid grid-cols-2 grid-rows-4 h-full">
        {{-- PENDIENTES --}}
        <a href="/work-orders?status=pending"
           class="bg-blue-100 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7v5l3 3"/>
                </svg>
                Pendientes
            </div>

            <div class="text-4xl font-black">{{ $pending }}</div>

        </a>

        {{-- EN PROGRESO --}}
        <a href="/work-orders?status=in_progress"
           class="bg-yellow-100 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
                En progreso
            </div>

            <div class="text-4xl font-black">{{ $in_progress }}</div>

        </a>

        {{-- COMPLETADAS --}}
        <a href="/work-orders?status=completed"
           class="bg-green-100 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <path d="M20 6L9 17l-5-5"/>
                </svg>
                Completadas
            </div>

            <div class="text-4xl font-black">{{ $completed_today }}</div>

        </a>

        {{-- EDIFICIOS --}}
        <a href="/buildings"
           class="bg-gray-200 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <path d="M3 21h18"/>
                    <path d="M6 21V7l6-4 6 4v14"/>
                </svg>
                Mis edificios
            </div>

            <div class="text-4xl font-black">{{ $total_buildings }}</div>

        </a>

        {{-- HOY --}}
        <a href="/work-orders?today=1"
           class="bg-purple-100 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <path d="M8 2v4M16 2v4"/>
                    <path d="M3 10h18"/>
                </svg>
                Trabajos de hoy
            </div>

            <div class="text-4xl font-black">{{ $tasks_today }}</div>

        </a>

        {{-- REMITOS --}}
        <a href="{{ route('delivery-notes.index') }}"
           class="bg-cyan-100 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <path d="M6 2h9l5 5v15H6z"/>
                    <path d="M14 2v6h6"/>
                </svg>
                Mis remitos
            </div>

            <div class="text-4xl font-black">{{ $deliveryNotes }}</div>

        </a>

        {{-- PLANTILLAS --}}
        <a href="/my-templates"
           class="col-span-2 bg-orange-100 text-gray-800 flex flex-col items-center justify-center">

            <div class="text-lg font-semibold flex items-center gap-2">

                Mis plantillas

                <svg xmlns="http://www.w3.org/2000/svg"
                     class="w-5 h-5"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     stroke-linecap="round"
                     stroke-linejoin="round">

                    <line x1="5" y1="12" x2="19" y2="12"/>
                    <polyline points="12 5 19 12 12 19"/>

                </svg>

            </div>

        </a>



    </div>

</div>

</x-app-layout>
