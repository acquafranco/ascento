<x-app-layout>

<div class="max-w-7xl mx-auto py-8 px-4">

    <h1 class="text-2xl font-bold mb-6">👤 Clientes</h1>

    <div class="bg-white rounded-xl shadow overflow-hidden">

        @forelse($clients as $client)

            <a href="{{ route('clients.show', ['client' => $client]) }}"
               class="block p-4 border-b hover:bg-gray-50">

                <div class="font-semibold">
                    {{ $client->name }}
                </div>

                <div class="text-sm text-gray-500">
                    {{ $client->email ?? 'Sin email' }}
                </div>

            </a>

        @empty

            <p class="p-4 text-gray-500">Todavía no hay clientes cargados.</p>

        @endforelse

    </div>

</div>

</x-app-layout>
