<x-app-layout>

<div class="max-w-5xl mx-auto py-8 px-4 space-y-6">

    <h1 class="text-2xl font-bold">
        👤 {{ $client->name }}
    </h1>

    <div class="bg-white p-4 rounded shadow">

        <h2 class="font-semibold mb-3">🏢 Edificios</h2>

        @forelse($client->buildings as $building)

            <div class="border-b py-2">
                <div class="font-semibold">
                    {{ $building->name }}
                </div>

                <div class="text-sm text-gray-500">
                    {{ $building->address }}
                </div>
            </div>

        @empty
            <p class="text-gray-400">Sin edificios</p>
        @endforelse

    </div>

</div>

</x-app-layout>
