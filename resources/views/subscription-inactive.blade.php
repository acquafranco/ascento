<x-guest-layout>
    <div class="space-y-4 text-center">
        <h1 class="text-xl font-bold text-gray-900">
            El servicio de {{ $company->name }} está suspendido
        </h1>

        <p class="text-sm text-gray-600">
            La suscripción de tu empresa a Ascento no está activa en este momento, por eso no podés
            cargar remitos ni operar órdenes de trabajo. Tu información y el historial están guardados.
        </p>

        <p class="text-sm text-gray-600">
            Avisale al administrador de tu empresa para que reactive la suscripción.
        </p>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="mt-2 inline-flex items-center rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white">
                Cerrar sesión
            </button>
        </form>
    </div>
</x-guest-layout>
