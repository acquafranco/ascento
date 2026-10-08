@php
    use App\Support\WorkOrderLabels;
@endphp

<x-app-layout>

<div class="max-w-7xl mx-auto px-4 pt-8 pb-32">

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

            @include('work-orders.partials.card', ['workOrder' => $workOrder])

        @empty

            <div class="text-center py-20 text-gray-500">
                No hay órdenes de trabajo
            </div>

        @endforelse

        @if ($workOrders->hasPages())
            <div class="pt-2">
                {{ $workOrders->onEachSide(1)->links() }}
            </div>
        @endif

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
