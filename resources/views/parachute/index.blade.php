{{--
    Pruebas de paracaídas del técnico: primero lo que corresponde hacer
    (vencidas, no satisfactorias, próximas), después lo que falta definir y lo
    que está al día. Ver ParachuteController.
--}}
@php
    use App\Services\Parachute\ParachuteService as P;
    $titles = [
        P::OVERDUE => ['Vencidas', 'Ya pasó la fecha prevista y no hay una prueba registrada después.', 'border-red-200 bg-red-50', 'text-red-700'],
        P::FAILED => ['No satisfactorias', 'La última prueba registrada no fue satisfactoria: hay que repetirla.', 'border-red-200 bg-red-50', 'text-red-700'],
        P::UPCOMING => ['Próximas', 'Se acerca la fecha prevista.', 'border-amber-200 bg-amber-50', 'text-amber-800'],
        P::NO_RECORD => ['Sin pruebas registradas', 'Todavía no hay ninguna prueba cargada: no se puede calcular la próxima.', 'border-ink-100 bg-white', 'text-ink-700'],
        P::UNCONFIGURED => ['Sin periodicidad definida', 'La oficina todavía no indicó cada cuánto corresponde la prueba de estos equipos.', 'border-ink-100 bg-white', 'text-ink-700'],
        P::OK => ['Al día', 'Con una prueba satisfactoria vigente.', 'border-ink-100 bg-white', 'text-emerald-700'],
    ];
    $date = fn ($d) => $d?->format('d/m/Y');
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Pruebas de paracaídas</h1>
        <p class="t-sub">Cuándo corresponde la prueba de cada ascensor que seguís. Se registra al firmar el remito de la visita.</p>
    </header>

    @if($total === 0)
        <div class="t-empty" data-parachute-empty>
            <p class="font-semibold text-ink">No tenés ascensores para seguir</p>
            <p class="mt-1">Aparecen acá los equipos de los edificios donde hacés la inspección y los que la oficina te asignó como inspector o responsable.</p>
        </div>
    @endif

    @foreach($groups as $status => $rows)
        @php([$title, $help, $box, $tone] = $titles[$status])
        <h2 class="t-section" data-parachute-group="{{ $status }}">{{ $title }} <span class="text-ink-500">({{ $rows->count() }})</span></h2>
        <p class="-mt-1 mb-2 text-xs text-ink-500">{{ $help }}</p>
        <div class="t-card t-divide {{ $box }}">
            @foreach($rows as $row)
                <div class="t-row items-start" data-parachute-row="{{ $row['elevator']->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink">{{ $row['elevator']->label }} · {{ $row['building']?->name }}</p>
                        <p class="text-sm font-semibold {{ $tone }}">
                            @switch($status)
                                @case(P::OVERDUE) Prevista para el {{ $date($row['next_due']) }} @break
                                @case(P::UPCOMING) Prevista para el {{ $date($row['next_due']) }} @break
                                @case(P::FAILED) Última: {{ $date($row['last']?->performed_on) }}, no satisfactoria @break
                                @case(P::NO_RECORD) Cada {{ $row['interval'] }} {{ $row['interval'] === 1 ? 'mes' : 'meses' }} · sin pruebas registradas @break
                                @case(P::UNCONFIGURED) Falta que la oficina defina la periodicidad @break
                                @default Próxima: {{ $date($row['next_due']) }}
                            @endswitch
                        </p>
                        @if($row['last_ok'])
                            <p class="text-xs text-ink-500">Última satisfactoria: {{ $date($row['last_ok']->performed_on) }}{{ $row['interval'] ? ' · cada '.$row['interval'].' '.($row['interval'] === 1 ? 'mes' : 'meses') : '' }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
</x-app-layout>
