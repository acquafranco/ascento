<x-app-layout>

<div class="max-w-6xl mx-auto py-10">

    <h1 class="text-2xl font-bold mb-6">
        Centro de operaciones
    </h1>

    <div class="space-y-3">

        @foreach($visits as $visit)

            <div class="bg-white p-4 rounded shadow flex justify-between items-center">

                <div>
                    <div class="font-bold">
                        {{ $visit->building->name }}
                    </div>

                    <div class="text-sm text-gray-500">
                        Estado: {{ $visit->status }}
                    </div>

                    <div class="text-xs text-gray-400">
                        Inicio: {{ $visit->started_at ?? '—' }}
                    </div>

                </div>

                <div class="flex gap-2">

                    @if($visit->status === 'pending')
                        <form method="POST" action="{{ route('visits.start', $visit) }}">
                            @csrf
                            <button class="bg-blue-600 text-white px-3 py-1 rounded">
                                Iniciar
                            </button>
                        </form>
                    @endif

                    @if($visit->status === 'in_progress')
                        <form method="POST" action="{{ route('visits.finish', $visit) }}">
                            @csrf
                            <button class="bg-green-600 text-white px-3 py-1 rounded">
                                Finalizar
                            </button>
                        </form>
                    @endif

                </div>

            </div>

        @endforeach

    </div>

</div>

</x-app-layout>
