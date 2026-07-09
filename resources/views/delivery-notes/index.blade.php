<x-app-layout>
    <div class="max-w-7xl mx-auto p-6">

 <div class="flex items-center justify-between mb-8">

    <div>
        <h1 class="text-3xl font-black">
            📄 Mis Remitos
        </h1>

        <p class="text-gray-500 mt-1">
            Historial de remitos generados
        </p>
    </div>

    <div class="bg-blue-50 text-blue-700 px-4 py-2 rounded-2xl font-bold">
        {{ $deliveryNotes->count() }} remitos
    </div>

</div>

<form method="GET" class="mb-6 flex flex-wrap gap-3">

    <select
        name="day"
        onchange="this.form.submit()"
        class="rounded-xl border-gray-300"
    >
        <option value="">Todos los días</option>

        @for($d=1;$d<=31;$d++)
            <option
                value="{{ $d }}"
                @selected(request('day')==$d)
            >
                {{ $d }}
            </option>
        @endfor

    </select>

    <select
        name="month"
        onchange="this.form.submit()"
        class="rounded-xl border-gray-300"
    >

        <option value="">Todos los meses</option>

        @for($m=1;$m<=12;$m++)
            <option
                value="{{ $m }}"
                @selected(request('month')==$m)
            >
                {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
            </option>
        @endfor

    </select>

    <select
        name="year"
        onchange="this.form.submit()"
        class="rounded-xl border-gray-300"
    >

        <option value="">Todos los años</option>

        @for($y=now()->year-3;$y<=now()->year+1;$y++)
            <option
                value="{{ $y }}"
                @selected(request('year')==$y)
            >
                {{ $y }}
            </option>
        @endfor

    </select>

    <a
        href="{{ route('delivery-notes.index') }}"
        class="px-4 py-2 rounded-xl bg-gray-200 hover:bg-gray-300"
    >
        Limpiar
    </a>

</form>

<div class="grid gap-4">

    @forelse($deliveryNotes as $note)

        <a
            href="{{ route('delivery-notes.show', $note) }}"
            class="bg-white rounded-3xl shadow hover:shadow-lg transition p-6 block"
        >

            <div class="flex justify-between items-start">

                <div>

                    <h2 class="font-black text-xl">
                        Remito #{{ $note->number }}
                    </h2>

                    <p class="text-gray-500 mt-1">
                        {{ $note->building?->name }}{{ $note->building?->address }}

                    </p>

                    @if($note->workOrder)
                        <p class="text-sm text-slate-500 mt-1">

                            OT: {{ ucfirst($note->workOrder->type) }}
                        </p>
                    @endif

                </div>

                <div>

                    @if($note->performed)

                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm font-bold">
                            Realizado
                        </span>

                    @else

                        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm font-bold">
                            No realizado
                        </span>

                    @endif

                </div>

            </div>

            <div class="mt-4 text-sm text-gray-500">

                {{ $note->created_at->format('d/m/Y H:i') }}

            </div>

        </a>

    @empty

        <div class="bg-white rounded-3xl shadow p-10 text-center">

            <div class="text-5xl mb-3">
                📄
            </div>

            <h2 class="font-bold text-lg">
                Todavía no hay remitos
            </h2>

        </div>

    @endforelse

</div>
</div>
</x-app-layout>

