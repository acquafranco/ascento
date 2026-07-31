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
                    @php
                        $currentUser = isset($user) ? $user : auth()->user();
                        $isParticipant = $visit->participants->contains('id', $currentUser->id);
                        $performedBy = $visit->user?->name ?? 'Técnico asignado';
                    @endphp

                    @if((int) $visit->user_id === (int) $currentUser->id)

                        <div class="bg-green-100 text-green-700">
                            ✅ Orden realizada por vos
                        </div>

                    @else

                        <div class="bg-blue-100 text-blue-700">
                            🤝 Participaste junto a {{ $performedBy }}
                        </div>

                    @endif
                      <div class="text-gray-500">

                        @php
                            $remito = $visit->deliveryNote ?? $visit->workOrder?->deliveryNote;
                        @endphp

                        @if($remito)

                            @if((int) $remito->user_id === (int) auth()->id())

                                <a
                                    href="{{ route('delivery-notes.show', [
                                        'company' => auth()->user()->company->slug,
                                        'deliveryNote' => $remito->number,
                                    ]) }}"
                                    class="inline-flex mt-2 px-3 py-1 rounded-lg bg-green-100 text-green-700 font-semibold"
                                >
                                    📄 Ver remito #{{ $remito->number }}
                                </a>

                            @else
                                @if($remito->public_token)
                                    <a
                                        href="{{ route('delivery-notes.public', [
                                            'company' => auth()->user()->company->slug,
                                            'token' => $remito->public_token,
                                        ]) }}"
                                        class="inline-flex mt-2 px-3 py-1 rounded-lg bg-blue-100 text-blue-700 font-semibold"
                                        target="_blank"
                                    >
                                        📄 Ver remito público #{{ $remito->number }}
                                    </a>
                                @else
                                    <span class="inline-flex mt-2 px-3 py-1 rounded-lg bg-yellow-100 text-yellow-700 font-semibold">
                                        ⚠️ Remito público no disponible
                                    </span>
                                @endif
                            @endif

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
                                {{ $visit->workOrder?->started_at?->format('H:i') ?? $visit->started_at?->format('H:i') ?? '-' }}
                            </div>

                            <div>
                                Salida:
                                {{ $visit->workOrder?->finished_at?->format('H:i') ?? $visit->finished_at?->format('H:i') ?? '-' }}
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
