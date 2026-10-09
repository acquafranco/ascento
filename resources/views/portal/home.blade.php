<x-portal-layout :company="$company" title="Mis edificios">
    <h1 class="p-h1">Hola, {{ $user->name }}</h1>
    <p class="p-sub">{{ $client?->name }} · Acá ves la información que {{ $company->name }} comparte sobre tus edificios.</p>

    @if($buildings->isEmpty())
        <div class="p-empty">Todavía no tenés edificios habilitados. Pedile a {{ $company->name }} que te dé acceso.</div>
    @else
        <div class="p-grid">
            @foreach($buildings as $building)
                <a class="p-card" href="{{ route('portal.building', $building) }}">
                    <h3>{{ $building->name }}</h3>
                    <div class="p-muted">{{ trim($building->address.' '.$building->locality) }}</div>
                    <div class="p-muted" style="margin-top: 6px">{{ $building->elevators_count }} {{ $building->elevators_count === 1 ? 'equipo' : 'equipos' }}</div>
                </a>
            @endforeach
        </div>
    @endif
</x-portal-layout>
