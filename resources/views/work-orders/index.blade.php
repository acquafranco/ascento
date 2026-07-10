@php
    use App\Support\WorkOrderLabels;
@endphp

<x-app-layout>

<div class="max-w-7xl mx-auto px-4 py-8">

    <div class="mb-8">

        <h1 class="text-3xl font-black">
            🔧 Órdenes de trabajo
        </h1>

        <p class="text-gray-500">
            Tomá y finalizá trabajos asignados
        </p>

    </div>

  <form method="GET" class="mb-6 flex flex-wrap items-end gap-3">

    <input
        type="hidden"
        name="status"
        value="{{ request('status') }}"
    >

    <select
        name="day"
        class="h-11 rounded-xl border-gray-300 px-3"
    >
        <option value="">Día</option>

        @foreach(range(1,31) as $day)
            <option
                value="{{ $day }}"
                @selected(request('day') == $day)
            >
                {{ $day }}
            </option>
        @endforeach
    </select>

    <select
        name="month"
        class="h-11 rounded-xl border-gray-300 px-3"
    >
        <option value="">Mes</option>

        @foreach(range(1,12) as $month)
            <option
                value="{{ $month }}"
                @selected(request('month') == $month)
            >
                {{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}
            </option>
        @endforeach
    </select>

    <select
        name="year"
        class="h-11 rounded-xl border-gray-300 px-5"
    >
        <option value="">Año</option>

        @foreach(range(now()->year - 3, now()->year + 1) as $year)
            <option
                value="{{ $year }}"
                @selected(request('year') == $year)
            >
                {{ $year }}
            </option>
        @endforeach
    </select>

    <button
        type="submit"
        class="h-11 px-4 rounded-xl bg-blue-600 text-white font-medium hover:bg-blue-700 transition"
    >
        Filtrar
    </button>

</form>

<div class="mb-6 grid grid-cols-3 gap-2">

    <a
        href="{{ route('work-orders.index', ['status' => 'pending']) }}"
        class="py-3 rounded-xl bg-blue-100 hover:bg-blue-200 text-center text-xs sm:text-sm font-semibold"
    >
        📋<br>
        Pendientes
    </a>

    <a
        href="{{ route('work-orders.index', ['status' => 'in_progress']) }}"
        class="py-3 rounded-xl bg-yellow-100 hover:bg-yellow-200 text-yellow-800 text-center text-xs sm:text-sm font-semibold"
    >
        🟡<br>
        En progreso
    </a>

    <a
        href="{{ route('work-orders.index', ['status' => 'completed']) }}"
        class="py-3 rounded-xl bg-green-100 hover:bg-green-200 text-green-700 text-center text-xs sm:text-sm font-semibold"
    >
        ✅<br>
        Completados
    </a>

</div>

    <div class="space-y-4">

        @forelse($workOrders as $workOrder)

            <div class="bg-white rounded-3xl shadow border border-slate-200 p-5">

                <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4">

                    {{-- INFO --}}
            <div class="flex-1">

                {{-- Encabezado --}}
                <div class="flex justify-between items-start gap-3">
                    <div>
                        <h3 class="font-bold text-lg leading-tight text-slate-800">
                                {{ $workOrder->building?->name }}
                                {{ $workOrder->building?->address }}
                        </h3>
                    </div>

                    <span class="text-xs font-semibold text-slate-500 whitespace-nowrap">
                        {{ $workOrder->created_at->format('d/m/Y') }}
                    </span>
                </div>

                {{-- Badges --}}
                <div class="mt-3 flex flex-wrap gap-2">

                    <span class="px-2.5 py-1 rounded-full bg-slate-100 text-xs font-semibold">
                        {{ WorkOrderLabels::type($workOrder->type) }}
                    </span>

                    @if($workOrder->unit)
                        <span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-semibold">
                            {{ $workOrder->unit }}
                        </span>
                    @endif

                    @if($workOrder->deliveryNote)
                        <span class="px-2.5 py-1 rounded-full bg-green-50 text-green-700 text-xs font-semibold">
                            Remito: {{ $workOrder->deliveryNote->number }}
                        </span>
                    @endif

                    @php
                        $priorityColors = [
                            'urgent' => 'bg-red-100 text-red-700',
                            'high' => 'bg-orange-100 text-orange-700',
                            'medium' => 'bg-yellow-100 text-yellow-700',
                            'low' => 'bg-green-100 text-green-700',
                        ];
                    @endphp

                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $priorityColors[$workOrder->priority] ?? 'bg-gray-100' }}">
                        {{ WorkOrderLabels::priority($workOrder->priority) }}
                    </span>

                </div>

                {{-- Trabajo --}}
                @if($workOrder->notes)
                    <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-wide font-bold text-slate-500 mb-1">
                            Trabajo a realizar
                        </div>

                        <p class="text-sm text-slate-700 leading-6">
                            {{ $workOrder->notes }}
                        </p>
                    </div>
                @endif

                {{-- Horarios --}}
                <div class="mt-3 flex flex-wrap gap-4 text-sm">

                    @if($workOrder->started_at)
                        <span class="text-blue-600 font-medium">
                            🕒 Entrada:
                            <strong>{{ $workOrder->started_at->format('H:i') }}</strong>
                        </span>
                    @endif

                    @if($workOrder->finished_at)
                        <span class="text-green-600 font-medium">
                            ✅ Salida:
                            <strong>{{ $workOrder->finished_at->format('H:i') }}</strong>
                        </span>
                    @endif

                </div>

            </div>

                    {{-- ACCIONES --}}
                    <div class="flex flex-col gap-2">

                        {{-- TOMAR --}}
                        @if($workOrder->status === 'pending')

                            <form method="POST" action="{{ route('work-orders.start', $workOrder) }}">
                                @csrf

                                <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-2xl font-bold">
                                    Tomar trabajo
                                </button>

                            </form>


                        @endif

                        {{-- FINALIZAR --}}
                        @if($workOrder->status === 'in_progress' && $workOrder->user_id === auth()->id())

                            <a
                                    href="{{ route('delivery-notes.work-order', $workOrder) }}"
                                    class="bg-green-600 hover:bg-green-700 text-white px-5 py-3 rounded-2xl font-bold"
                                >
                                    Finalizar
                                </a>
                                @csrf

                            </form>

                        @endif

                        {{-- COMPLETADO --}}
                        @if($workOrder->status === 'completed')

                            <span class="inline-flex rounded-2xl bg-green-100 text-green-700 px-4 py-3 font-bold">
                                ✓ {{ WorkOrderLabels::status($workOrder->status) }}
                            </span>

                        @endif

                    </div>

                </div>

            </div>

        @empty

            <div class="text-center py-20 text-gray-500">
                No hay órdenes de trabajo
            </div>

        @endforelse

    </div>

</div>

</x-app-layout>
