<x-app-layout>
@if(session('success'))
    <div
        class="mb-5 rounded-2xl border border-emerald-200/60 bg-emerald-50/80 backdrop-blur-sm p-4 text-emerald-700 font-semibold flex items-center gap-2.5"
    >
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        {{ session('success') }}
    </div>
@endif
<div class="fixed left-0 right-0 top-0 bottom-20 lg:top-16 lg:bottom-0 overflow-y-auto" style="background-color:#fff">

    <div class="h-full flex flex-col gap-1 p-1">
        <div class="rounded-3xl border flex items-center justify-between px-6 py-6 shadow-[0_6px_20px_-8px_rgba(20,23,28,0.18)] bg-white"
             style="border-color:#FFE1CC;">
            <div>
                <div class="text-sm font-medium" style="color:#FF6A1A;">
                    {{ now()->translatedFormat('l, j \\d\\e F') }}
                </div>

                <h1 class="mt-1 text-2xl font-bold" style="color:#12151C;">
                    ¡Hola, {{ auth()->user()->name }}!
                </h1>

                <p class="mt-2 text-sm" style="color:#5B6472;">
                    Bienvenido. Revisá tus tareas pendientes y comenzá tu jornada.
                </p>
            </div>

           <div class="flex items-center justify-center">
    <svg class="w-14 h-14" viewBox="0 0 24 24" fill="none" style="color:#FF6A1A">
        <rect x="6" y="3" width="12" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/>
        <path d="M12 7V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        <path d="M10 9L12 7L14 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M10 15L12 17L14 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
</div>
        </div>

        <div class="grid grid-cols-2 grid-rows-3 flex-1 gap-1">
            {{-- PENDIENTES --}}
            <a href="{{ route('work-orders.index', [
                'company' => auth()->user()->company->slug,
                'status' => 'pending',
            ]) }}"
            class="card-dashboard top-left  shadow-[0_4px_16px_-6px_rgba(20,23,28,0.35)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 flex flex-col items-center justify-center py-5"
            style="background-color:#12151C; border: 2px solid #FF6A1A;">

                <span class="flex h-8 w-8 items-center justify-center rounded-lg mb-2" style="color:#FF6A1A;">
                    <svg class="w-[16px] h-[16px]" viewBox="0 0 24 24" fill="none"><path d="M12 6V12L16 14M20 12A8 8 0 1 1 4 12A8 8 0 0 1 20 12Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>

                <div class="text-sm text-white font-medium">
                    Pendientes
                </div>

                <div class="text-4xl font-semibold tracking-tight text-white">
                    {{ $pending }}
                </div>

            </a>


            {{-- EN PROGRESO --}}
            <a href="{{ route('work-orders.index', [
                'company' => auth()->user()->company->slug,
                'status' => 'in_progress',
            ]) }}"
            class="card-dashboard top-right shadow-[0_4px_16px_-6px_rgba(20,23,28,0.35)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 flex flex-col items-center justify-center py-5" style="background-color:#12151C; border: 2px solid #FF6A1A;">

                <span class="flex h-8 w-8 items-center justify-center rounded-lg mb-2" style="color:#FF6A1A;">
                    <svg class="w-[16px] h-[16px]" viewBox="0 0 24 24" fill="none"><path d="M12 4V6M12 18V20M4 12H6M18 12H20M6.3 6.3L7.8 7.8M16.2 16.2L17.7 17.7M6.3 17.7L7.8 16.2M16.2 7.8L17.7 6.3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                </span>

                <div class="text-sm text-white font-medium">
                    En progreso
                </div>

                <div class="text-4xl font-semibold tracking-tight text-white">
                    {{ $in_progress }}
                </div>

            </a>


            {{-- COMPLETADAS --}}
            <a href="{{ route('work-orders.index', [
                'company' => auth()->user()->company->slug,
                'status' => 'completed',
            ]) }}"
            class="shadow-[0_4px_16px_-6px_rgba(20,23,28,0.35)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 flex flex-col items-center justify-center py-5" style="background-color:#12151C; border: 2px solid #FF6A1A;">

                <span class="flex h-8 w-8 items-center justify-center rounded-lg mb-2" style="color:#FF6A1A;">
                    <svg class="w-[16px] h-[16px]" viewBox="0 0 24 24" fill="none"><path d="M4 12L9.5 17.5L20 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>

                <div class="text-sm text-white font-medium">
                    Completadas hoy
                </div>

                <div class="text-4xl font-semibold tracking-tight text-white">
                    {{ $completed_today }}
                </div>

            </a>


            {{--  Visitas Tecnicas --}}
            <a href="{{ route('buildings.index', [
                'company' => auth()->user()->company->slug,
            ]) }}"
            class="shadow-[0_4px_16px_-6px_rgba(20,23,28,0.35)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 flex flex-col items-center justify-center py-5" style="background-color:#12151C; border: 2px solid #FF6A1A;">

                <span class="flex h-8 w-8 items-center justify-center rounded-lg mb-2" style="color:#FF6A1A;">
                    <svg class="w-[16px] h-[16px]" viewBox="0 0 24 24" fill="none"><path d="M12 21C12 21 5 14.5 5 9.5A7 7 0 0 1 19 9.5C19 14.5 12 21 12 21ZM9.5 9.5A2.5 2.5 0 1 0 14.5 9.5A2.5 2.5 0 0 0 9.5 9.5Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>

                <div class="text-sm text-white font-medium">
                    Visitas hoy
                </div>

                <div class="text-4xl font-semibold tracking-tight text-white">
                    {{ $tasks_today }}
                </div>

            </a>




            {{-- REMITOS --}}
            <a href="{{ route('delivery-notes.index', [
                'company' => auth()->user()->company->slug,
            ]) }}"
            class="bottom-left card-dashboard shadow-[0_4px_16px_-6px_rgba(20,23,28,0.35)] hover:-translate-y-0.5 active:scale-[0.98] hover:brightness-110 transition-all duration-200 flex flex-col items-center justify-center py-5" style="background:linear-gradient(180deg,#FF7A1A 0%,#FF6A1A 100%); border:2px solid #FF8F3A;">

                <span class="flex h-8 w-8 items-center justify-center rounded-lg mb-2 drop-shadow-sm" style="color:#FFFFFF;">
                    <svg class="w-[16px] h-[16px]" viewBox="0 0 24 24" fill="none"><path d="M6 3H14L18 7V21H6V3ZM14 3V7H18M9 12H15M9 16H15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>

                <div class="text-sm text-white font-semibold">
                    Remitos
                </div>

            </a>



            {{-- PLANTILLAS --}}
            <a href="{{ route('templates.index', [
                'company' => auth()->user()->company->slug,
            ]) }}"
            class="card-dashboard bottom-right shadow-[0_4px_16px_-6px_rgba(20,23,28,0.35)] hover:-translate-y-0.5 active:scale-[0.98] hover:brightness-110 transition-all duration-200 flex flex-col items-center justify-center py-5"
            style="background:linear-gradient(180deg,#FF7A1A 0%,#FF6A1A 100%); border:2px solid #FF8F3A;">

                <span class="flex h-8 w-8 items-center justify-center rounded-lg drop-shadow-sm" style="color:#FFFFFF;">
                    <svg class="w-[16px] w-[16px]" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9H21" stroke="currentColor" stroke-width="1.7"/></svg>
                </span>

                <div class="mt-2 text-sm font-semibold text-white">
                    Planillas
                </div>

            </a>
        </div>
    </div>

</div>

<style>

/* primera tarjeta */
.card-dashboard.top-left {
    border-top-left-radius: 24px;
}

/* segunda tarjeta */
.card-dashboard.top-right {
    border-top-right-radius: 24px;
}

/* tercera tarjeta */
.card-dashboard.bottom-left {
    border-bottom-left-radius: 24px;
}

/* sexta tarjeta */
.card-dashboard.bottom-right {
    border-bottom-right-radius: 24px;
}
</style>

</x-app-layout>
