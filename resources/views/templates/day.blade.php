<x-app-layout>

<div class="max-w-5xl mx-auto px-4 py-8">

    <h1 class="text-3xl font-black mb-6">

        {{ $date->translatedFormat('l d F Y') }}

    </h1>

    <div class="space-y-4">

        @foreach($visits as $visit)

            <div class="bg-white rounded-3xl shadow p-5">

                <div class="font-black text-lg">

                    {{ $visit->building?->name }}

                </div>

                <div class="text-gray-500">

                    {{ $visit->building?->address }}

                </div>
                  <div class="text-gray-500">

                    @if($visit->deliveryNote)

                        <a
                            href="{{ route(
                                'delivery-notes.show',
                                $visit->deliveryNote
                            ) }}"
                            class="inline-flex mt-2 px-3 py-1 rounded-lg bg-green-100 text-green-700 font-semibold"
                        >
                            📄 Ver remito #{{ $visit->deliveryNote->number }}
                        </a>

                    @endif

                </div>
                <div class="mt-3">

                    {{ $visit->work_type }}

                </div>

                <div class="mt-2">

                    Técnico:
                    {{ $visit->user?->name }}

                </div>

                <div class="mt-2">

                    Inicio:
                    {{ optional($visit->started_at)->format('H:i') }}

                </div>

                <div>

                    Fin:
                    {{ optional($visit->finished_at)->format('H:i') }}

                </div>

            </div>

        @endforeach

    </div>

</div>

</x-app-layout>
