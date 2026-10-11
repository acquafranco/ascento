<x-portal-layout :company="$company" title="Portal no disponible" :bell="false" :nav="false">
    <h1 class="p-h1">El portal no está disponible</h1>
    <p class="p-sub">El servicio de {{ $company->name }} en Ascento no está activo en este momento. Consultá directamente con {{ $company->name }}.</p>
</x-portal-layout>
