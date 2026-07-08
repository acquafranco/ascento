<x-app-layout>

<div class="fixed inset-0 top-16 bottom-20 lg:bottom-0 overflow-y-auto">

    <div class="grid grid-cols-2 grid-rows-4 h-full">

        {{-- PENDIENTES --}}
        <a href="/work-orders?status=pending"
           class="bg-blue-100 flex flex-col items-center justify-center">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                ⏱ Pendientes
            </div>

            <div class="text-4xl font-black">
                {{ $pending }}
            </div>

        </a>


        {{-- EN PROGRESO --}}
        <a href="/work-orders?status=in_progress"
           class="bg-yellow-100 flex flex-col items-center justify-center ">

            <div class="flex items-center gap-2 text-sm text-gray-600">
                ⏳ En progreso
            </div>

            <div class="text-4xl font-black">
                {{ $in_progress }}
            </div>

        </a>


        {{-- COMPLETADAS --}}
        <a href="/work-orders?status=completed"
           class="bg-green-100 flex flex-col items-center justify-center">

            <div class="text-sm text-gray-600">
                ✓ Completadas
            </div>

            <div class="text-4xl font-black">
                {{ $completed_today }}
            </div>

        </a>


        {{-- EDIFICIOS --}}
        <a href="/buildings"
           class="bg-gray-200 flex flex-col items-center justify-center">

            <div class="text-sm text-gray-600">
                🏢 Mis edificios
            </div>

            <div class="text-4xl font-black">
                {{ $total_buildings }}
            </div>

        </a>


        {{-- HOY --}}
        <a href="/work-orders?today=1"
           class="bg-purple-100 flex flex-col items-center justify-center ">

            <div class="text-sm text-gray-600">
                📅 Trabajos de hoy
            </div>

            <div class="text-4xl font-black">
                {{ $tasks_today }}
            </div>

        </a>


        {{-- REMITOS --}}
        <a href="{{ route('delivery-notes.index') }}"
           class="bg-cyan-100 flex flex-col items-center justify-center">

            <div class="text-sm text-gray-600">
                📄 Mis remitos
            </div>

            <div class="text-4xl font-black">
                {{ $deliveryNotes }}
            </div>

        </a>


        {{-- PLANTILLAS --}}
        <a href="/my-templates"
           class="col-span-2 bg-orange-100 flex items-center justify-center ">

            <div class="text-xl font-bold flex items-center gap-2">
                Mis plantillas →
            </div>

        </a>


    </div>

</div>

</x-app-layout>
