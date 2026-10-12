{{--
    Administrativo que no puede entrar al panel: el motivo (desactivado,
    empresa deshabilitada o suspendido por el límite del plan) y salir.
    $message, $company
--}}
<x-guest-layout>
    <div class="space-y-4 text-center" data-staff-blocked>
        <h1 class="text-xl font-bold text-gray-900">No podés entrar al panel{{ $company ? ' de '.$company->name : '' }}</h1>
        <p class="text-sm text-gray-600" data-staff-blocked-reason>{{ $message }}</p>
        <p class="text-sm text-gray-600">Si creés que es un error, hablá con el dueño de la empresa.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="mt-2 inline-flex items-center rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white">Cerrar sesión</button>
        </form>
    </div>
</x-guest-layout>
