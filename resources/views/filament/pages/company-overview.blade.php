<x-filament-panels::page>

<div class="space-y-6">

    {{-- Datos empresa --}}
    <x-filament::section>

        <div class="flex items-center justify-between">

            <div>
                <h1 class="text-2xl font-black">
                    {{ $company->name }}
                </h1>

                @if($company->business_name)
                    <p class="text-gray-500">
                        {{ $company->business_name }}
                    </p>
                @endif

                @if($company->email)
                    <p class="text-gray-500">
                        {{ $company->email }}
                    </p>
                @endif
            </div>

        </div>

    </x-filament::section>


    {{-- Estadísticas --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

        @foreach($this->getStats() as $label => $value)

            <x-filament::section>

                <div class="text-sm text-gray-500">
                    {{ $label }}
                </div>

                <div class="text-3xl font-black mt-2">
                    {{ $value }}
                </div>

            </x-filament::section>

        @endforeach

    </div>



    {{-- Accesos --}}
    <x-filament::section>

        <h2 class="text-lg font-black mb-4">
            Gestión de empresa
        </h2>


        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">


            <a
                href="{{ route('filament.ascensores_app.resources.buildings.index', [
                    'tableFilters[company_id][value]' => $company->id
                ]) }}"
                class="rounded-xl bg-blue-50 p-5 text-center font-bold text-blue-700 hover:bg-blue-100 transition"
            >
                🏢
                <br>
                Edificios
            </a>



            <a
                href="{{ route('filament.ascensores_app.resources.users.index', [
                    'tableFilters[company_id][value]' => $company->id
                ]) }}"
                class="rounded-xl bg-green-50 p-5 text-center font-bold text-green-700 hover:bg-green-100 transition"
            >
                👥
                <br>
                Usuarios
            </a>



            <a
                href="{{ route('filament.ascensores_app.resources.delivery-notes.index', [
                    'tableFilters[company_id][value]' => $company->id
                ]) }}"
                class="rounded-xl bg-orange-50 p-5 text-center font-bold text-orange-700 hover:bg-orange-100 transition"
            >
                📄
                <br>
                Remitos
            </a>



            <a
                href="{{ route('filament.ascensores_app.resources.work-orders.index', [
                    'tableFilters[company_id][value]' => $company->id
                ]) }}"
                class="rounded-xl bg-purple-50 p-5 text-center font-bold text-purple-700 hover:bg-purple-100 transition"
            >
                🔧
                <br>
                Órdenes
            </a>


        </div>

    </x-filament::section>


</div>

</x-filament-panels::page>
