<x-app-layout>

<div class="max-w-5xl mx-auto px-4 py-8">

    <h1 class="text-3xl font-black mb-2">
    {{ $date->translatedFormat('l d F Y') }}
    </h1>

    @if(isset($user))
        <div class="text-gray-500 mb-6">
            Técnico:
            <span class="font-bold">{{ $user->name }}</span>
        </div>
    @endif

    <div class="space-y-4">

        @if($visits->isEmpty())
            <div class="bg-white rounded-3xl shadow p-5 text-center">
                <div class="font-black text-lg mb-2">
                    Todavía no hay trabajos registrados para este día.
                </div>
                <div class="text-gray-500">
                    Cuando completes un mantenimiento, una inspección o una orden de trabajo aparecerán aquí.
                </div>
            </div>
        @else
            @foreach($visits as $visit)

                <div class="bg-white rounded-3xl shadow p-5">

                    <div class="font-black text-lg">
                        {{ $visit->building?->client?->name ?? 'Sin cliente' }}
                    </div>

                    <div class="text-gray-500">
                        {{ trim(($visit->building?->name ?? '').' '.($visit->building?->address ?? '')) }}
                    </div>
                      <div class="text-gray-500">

                        @if($visit->deliveryNote)

                            <a
                               href="{{ route('delivery-notes.show', [
                                'company' => auth()->user()->company->slug,
                                'deliveryNote' => $visit->deliveryNote->number
                            ]) }}"
                                class="inline-flex mt-2 px-3 py-1 rounded-lg bg-green-100 text-green-700 font-semibold"
                            >
                                📄 Ver remito #{{ $visit->deliveryNote->number }}
                            </a>

                        @endif

                    </div>
                    <div class="mt-3">
                        @if($visit->source === 'work_order')
                            <span class="inline-flex px-3 py-1 rounded-lg bg-orange-100 text-orange-700 font-semibold">
                                🛠️ Orden de trabajo - {{ match($visit->work_type) {
                                    'claim' => 'Reclamo',
                                    'inspection' => 'Inspección',
                                    'maintenance' => 'Mantenimiento',
                                    'installation' => 'Instalación',
                                    'modernization' => 'Modernización',
                                    default => ucfirst($visit->work_type ?? 'Orden de trabajo')
                                } }}
                            </span>
                        @else
                            <span class="inline-flex px-3 py-1 rounded-lg bg-blue-100 text-blue-700 font-semibold">
                                {{ $visit->assignment_type === 'inspection' ? '🔎 Inspección' : '🔧 Mantenimiento' }}
                            </span>
                        @endif
                    </div>


                        @if($visit->source === 'work_order')

                            <div class="mt-2">
                                Entrada:
                                {{ optional($visit->started_at)->format('H:i') }}
                            </div>

                            <div>
                                Salida:
                                {{ optional($visit->finished_at)->format('H:i') }}
                            </div>

                        @else

                            <div class="mt-2">
                                Hora visita:
                                {{ optional($visit->visited_at)->format('H:i') }}
                            </div>

                        @endif

                </div>

            @endforeach
        @endif

    </div>

</div>

</x-app-layout>
