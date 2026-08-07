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

  <form
    method="GET"
    class="mb-6 flex flex-nowrap items-end gap-2 overflow-x-auto"
    id="filters-form"
>

    <input
        type="hidden"
        name="status"
        value="{{ request('status') }}"
    >

   <select
        name="day"
        onchange="this.form.submit()"
        class="w-20 rounded-xl border-gray-300 text-sm px-2"
    >
        <option value="">Día</option>

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
        class="w-20 rounded-xl border-gray-300 text-sm px-2"
    >

        <option value="">Mes</option>

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
        class="w-20 rounded-xl border-gray-300 text-sm px-2"
    >

        <option value="">Año</option>

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
        href="{{ route('work-orders.index', [
            'company' => auth()->user()->company->slug,
            'status' => request('status')
        ]) }}"
        title="Limpiar filtros"
        class="h-11 w-20 shrink-0 rounded-xl bg-blue-600 text-white flex items-center justify-center hover:bg-blue-700 transition shadow-sm"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
    </a>

</form>

<div class="mb-6 grid grid-cols-3 gap-2">

    <a
        href="{{ route('work-orders.index', [
            'company' => auth()->user()->company->slug,
            'status' => 'pending',
        ]) }}"
        class="py-3 rounded-xl bg-blue-100 hover:bg-blue-200 text-center text-xs sm:text-sm font-semibold"
    >
        📋<br>
        Pendientes
    </a>

    <a
        href="{{ route('work-orders.index', [

        'company' => auth()->user()->company->slug,

        'status' => 'in_progress']) }}"
        class="py-3 rounded-xl bg-yellow-100 hover:bg-yellow-200 text-yellow-800 text-center text-xs sm:text-sm font-semibold"
    >
        🟡<br>
        En curso
    </a>

    <a
        href="{{ route('work-orders.index', [

        'company' => auth()->user()->company->slug,

        'status' => 'completed']) }}"
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

                          <form
                            method="POST"
                            action="{{ route('work-orders.start', [
                                'company' => auth()->user()->company->slug,
                                'workOrder' => $workOrder,
                            ]) }}"
                            x-data="{ loading:false }"
                            @submit="loading=true"
                        >
                            @csrf

                            <button
                                type="submit"
                                :disabled="loading"
                                class="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 disabled:cursor-not-allowed text-white px-5 py-3 rounded-2xl font-bold transition"
                            >
                                <span x-show="!loading">
                                    🛠 Tomar trabajo
                                </span>

                                <span
                                    x-show="loading"
                                    class="flex items-center justify-center gap-2"
                                >
                                    <svg class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/>
                                        <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/>
                                    </svg>

                                    Tomando...
                                </span>
                            </button>
                        </form>


                        @endif

                        {{-- FINALIZAR --}}
                        @if(
                            $workOrder->status === 'in_progress'
                            &&
                            $workOrder->users->contains(auth()->id())
                        )

                        <a
                            href="{{ route('delivery-notes.work-order', [
                                'company' => auth()->user()->company->slug,
                                'workOrder' => $workOrder
                            ]) }}"
                            x-data="{ loading:false }"
                            @click="loading=true"
                            @pageshow.window="loading=false"
                            :class="{ 'pointer-events-none opacity-70': loading }"
                            class="bg-green-600 hover:bg-green-700 text-white px-5 py-3 rounded-2xl font-bold text-center transition"
                        >

                            <span x-show="!loading">
                                ✅ Finalizar
                            </span>

                            <span
                                x-show="loading"
                                class="flex items-center justify-center gap-2"
                            >
                                <svg class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="24" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/>
                                    <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/>
                                </svg>

                                Abriendo...
                            </span>

                        </a>

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

<script>
document.querySelectorAll('.filter-select')
.forEach(select => {

    select.addEventListener('change', () => {

        document
            .getElementById('filters-form')
            .submit();

    });

});
</script>
@if(session('success'))
<script>
    navigator.vibrate?.(40);
</script>
@endif
</x-app-layout>
