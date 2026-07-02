<x-filament-widgets::widget>

    <div
        class="rounded-3xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-700 shadow-xl p-10 text-white"
    >

        <div class="flex items-center gap-6">

            <div>

                <h1 class="text-5xl font-black">
                    Bienvenido, {{ auth()->user()->name }}  👋
                </h1>

                <p class="text-xl text-slate-200 mt-2">
                    Panel de administración de Ascensores
                </p>

                <p class="text-slate-300 mt-3">
                    {{ now()->translatedFormat('l d \d\e F \d\e Y') }}
                </p>

            </div>

        </div>

    </div>

</x-filament-widgets::widget>
