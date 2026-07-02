<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="mb-6">

        <h1 class="text-3xl font-black">
            📋 Templates
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

                        <div
                            class="bg-white rounded-3xl shadow p-4 min-h-[260px]"
                        >

                            {{-- DIA --}}
                            <div
                                class="font-black text-lg mb-4"
                            >

                                {{
                                    ucfirst(
                                        $date
                                            ->translatedFormat('D')
                                    )
                                }}

                                {{ $date->format('d') }}

                            </div>

                            @forelse($dayVisits as $visit)

                                <div
                                    class="border rounded-2xl p-3 mb-3 bg-slate-50"
                                >

                                    {{-- EDIFICIO --}}
                                    <div
                                        class="font-bold text-sm leading-tight"
                                    >
                                        {{
                                            $visit->building?->name
                                        }}
                                    </div>

                                    {{-- DIRECCION --}}
                                    <div
                                        class="text-xs text-gray-500"
                                    >
                                        {{
                                            $visit->building?->address
                                        }}
                                    </div>

                                    {{-- UNIDAD --}}
                                    @if($visit->unit)

                                        <div class="mt-2">

                                            <span
                                                class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-1 text-[11px] font-bold text-indigo-700"
                                            >
                                                {{ $visit->unit }}
                                            </span>

                                        </div>

                                    @endif

                                    {{-- TIPO --}}
                                    <div
                                        class="mt-2 text-xs font-semibold text-slate-600"
                                    >

                                        @switch($visit->type)

                                            @case('maintenance')
                                                🔧 Mantenimiento
                                                @break

                                            @case('inspection')
                                                🔎 Inspección
                                                @break

                                            @case('claim')
                                                🚨 Reclamo
                                                @break

                                            @case('installation')
                                                🏗 Instalación
                                                @break

                                            @case('modernization')
                                                ⚙️ Modernización
                                                @break

                                            @default
                                                {{ $visit->type }}

                                        @endswitch

                                    </div>

                                    {{-- TECNICO --}}
                                    <div
                                        class="text-xs text-gray-500 mt-1"
                                    >
                                        👷
                                        {{
                                            $visit->user?->name
                                            ?? 'Sin técnico'
                                        }}
                                    </div>

                                    {{-- HORARIOS --}}
                                    <div
                                        class="mt-2 text-xs flex flex-col gap-1"
                                    >

                                        @if($visit->started_at)

                                            <div
                                                class="text-blue-600 font-semibold"
                                            >
                                                ▶ Inicio:
                                                {{
                                                    \Carbon\Carbon::parse(
                                                        $visit->started_at
                                                    )->format('H:i')
                                                }}
                                            </div>

                                        @endif

                                        @if($visit->finished_at)

                                            <div
                                                class="text-green-600 font-semibold"
                                            >
                                                ✔ Fin:
                                                {{
                                                    \Carbon\Carbon::parse(
                                                        $visit->finished_at
                                                    )->format('H:i')
                                                }}
                                            </div>

                                        @endif

                                    </div>

                                    {{-- REMITO --}}
                                    @if($visit->delivery_note)

                                        <div class="mt-2">

                                            <span
                                                class="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-[11px] font-bold text-blue-700"
                                            >
                                                R:
                                                {{
                                                    $visit->delivery_note
                                                }}
                                            </span>

                                        </div>

                                    @endif

                                    {{-- ESTADO --}}
                                    <div class="mt-3">

                                        @if($visit->status === 'done')

                                            <span
                                                class="inline-flex rounded-full bg-green-100 px-2 py-1 text-[11px] font-bold text-green-700"
                                            >
                                                Completado
                                            </span>

                                        @else

                                            <span
                                                class="inline-flex rounded-full bg-red-100 px-2 py-1 text-[11px] font-bold text-red-700"
                                            >
                                                No realizado
                                            </span>

                                        @endif

                                    </div>

                                </div>

                            @empty

                                <div
                                    class="text-sm text-gray-400"
                                >
                                    Sin trabajos
                                </div>

                            @endforelse

                        </div>

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
