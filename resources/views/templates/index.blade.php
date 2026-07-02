<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="mb-6">

        <h1 class="text-3xl font-black">
            📋 Planillas Semanales
        </h1>

        <p class="text-gray-500">
            Historial mensual de trabajos
        </p>

    </div>

    {{-- FILTROS --}}
    <form
        method="GET"
        class="flex gap-3 mb-8"
    >

        <select
            name="month"
            onchange="this.form.submit()"
            class="rounded-xl border-gray-300"
        >
            @foreach(range(1,12) as $m)

                <option
                    value="{{ $m }}"
                    @selected($month == $m)
                >
                    {{
                        \Carbon\Carbon::create()
                            ->month($m)
                            ->translatedFormat('F')
                    }}
                </option>

            @endforeach
        </select>

        <select
            name="year"
            onchange="this.form.submit()"
            class="rounded-xl border-gray-300"
        >
            @foreach(range(now()->year - 3, now()->year + 1) as $y)

                <option
                    value="{{ $y }}"
                    @selected($year == $y)
                >
                    {{ $y }}
                </option>

            @endforeach
        </select>

    </form>

    <div class="space-y-8">

        @forelse($weeks as $week)

            <div>

                {{-- TITULO SEMANA --}}
                <h2
                    class="font-black text-lg mb-4"
                >

                    Semana del

                    {{
                        $week['start']
                            ->translatedFormat('d')
                    }}

                    al

                    {{
                        $week['end']
                            ->translatedFormat('d M')
                    }}

                </h2>

                {{-- GRID 7 DIAS --}}
                <div
                    class="grid grid-cols-2 lg:grid-cols-7 gap-4"
                >

            @for($i = 0; $i < 7; $i++)

                @php

                    $date =
                        $week['start']
                            ->copy()
                            ->addDays($i);

                    $dayVisits =
                        $week['visits']
                        ->filter(function ($visit) use ($date) {

                            return
                                \Carbon\Carbon::parse(
                                    $visit->visited_at
                                )->isSameDay($date);

                        });

                @endphp

                <a
                    href="{{ $dayVisits->count() ? route('templates.day', $date->format('Y-m-d')) : '#' }}"
                    class="block bg-white rounded-3xl shadow p-4 min-h-[220px] hover:shadow-xl transition"
                >

                    {{-- DIA --}}
                    <div class="font-black text-lg mb-4">

                        {{
                            ucfirst(
                                $date->translatedFormat('D')
                            )
                        }}

                        {{ $date->format('d') }}

                    </div>

                  @if($dayVisits->count())

                <div class="rounded-2xl bg-slate-50 border p-3">

                    @foreach($dayVisits->take(3) as $visit)

                        <div class="mb-2">

                            <div class="text-xs font-semibold truncate">
                                🏢 {{ $visit->building?->name }}

                                {{ $visit->building?->address }}
                            </div>

                        </div>

                    @endforeach

                    @if($dayVisits->count() > 3)

                        <div class="text-xs text-gray-500 mt-2">
                            +{{ $dayVisits->count() - 3 }} trabajo(s) más
                        </div>

                    @endif

                </div>

               <div class="mt-3 text-center text-blue-600 text-xs font-bold">

                    Ver día completo →

                </div>

            @else

                <div class="text-sm text-gray-400">
                    Sin trabajos
                </div>

            @endif

                            </a>

                        @endfor

                            </div>

                        </div>

                    @empty

                        <div class="text-gray-500">
                            No hay trabajos
                        </div>

                    @endforelse

    </div>

</div>

</x-app-layout>
