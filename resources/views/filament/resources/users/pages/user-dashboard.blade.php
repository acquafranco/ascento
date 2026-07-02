<x-filament-panels::page>

<div class="w-full space-y-10">

    @foreach($weeks as $week)

        <div class="border rounded-xl bg-white shadow-sm w-full overflow-hidden">

            {{-- HEADER --}}
            <div class="flex justify-between px-5 py-4 border-b bg-gray-50">
                <div class="font-bold text-lg">
                    Semana del {{ $week['start']->format('d/m') }}
                    al {{ $week['end']->format('d/m') }}
                </div>

                <div class="text-sm text-gray-500">
                    {{ $week['visits']->count() }} trabajos
                </div>
            </div>

            {{-- GRID 7 DIAS (ESTO ES LO IMPORTANTE) --}}
            <div class="grid grid-cols-7 gap-3 p-4 w-full min-w-[1200px]">

                @for($i = 0; $i < 7; $i++)

                    @php
                        $date = $week['start']->copy()->addDays($i);

                        $dayVisits = $week['visits']->filter(
                            fn ($v) =>
                                \Carbon\Carbon::parse($v->visited_at)
                                    ->isSameDay($date)
                        );
                    @endphp

                    <div class="border rounded-xl p-3 min-h-[280px] bg-gray-50">

                        {{-- DIA --}}
                        <div class="font-bold text-sm mb-3">
                            {{ ucfirst($date->locale('es')->translatedFormat('l')) }}
                            <div class="text-xs text-gray-500">
                                {{ $date->format('d/m') }}
                            </div>
                        </div>

                        {{-- VISITAS --}}
                        <div class="space-y-2">

                            @forelse($dayVisits as $visit)

                                <div class="bg-white border rounded-lg p-2 text-xs">

                                    🏢 {{ $visit->building?->name }}

                                </div>

                            @empty

                                <div class="text-xs text-gray-400">
                                    Sin trabajos
                                </div>

                            @endforelse

                        </div>

                    </div>

                @endfor

            </div>

        </div>

    @endforeach

</div>

</x-filament-panels::page>
