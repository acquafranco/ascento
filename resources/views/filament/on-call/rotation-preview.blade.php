{{--
    Vista previa de la rotación con los datos del formulario, antes de guardar
    (OnCallScheduleResource::rotationPreview → RotationService::plan). No
    guarda nada. $preview: missing, rows, example, warnings, changes.
--}}
@php
    $status = [
        'new' => ['Turno nuevo', ''],
        'same' => ['Ya está así', 'opacity:.7'],
        'change' => ['Cambia: se avisa', 'color:#b45309; font-weight:600'],
        'manual' => ['Tiene un cambio a mano: no se toca', 'opacity:.7'],
        'uncovered' => ['Sin técnico disponible', 'color:#dc2626; font-weight:600'],
    ];
@endphp
<div data-on-call-rotation-preview style="display:grid; gap:.6rem; font-size:.9rem; line-height:1.45">
    <p style="margin:0; font-weight:700">Vista previa de los próximos turnos</p>

    @if($preview['missing'])
        <div role="status" style="border-radius:.75rem; padding:.7rem .9rem; background:rgba(217,119,6,.08); border:1px solid rgba(217,119,6,.3)">
            <p style="margin:0 0 .3rem; font-weight:600">Para armar la rotación falta:</p>
            <ul style="margin:0; padding-left:1.1rem">
                @foreach($preview['missing'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    @else
        @if($preview['example'])
            <p style="margin:0; border-left:3px solid #ff6600; padding:.3rem .7rem; overflow-wrap:anywhere" data-on-call-rotation-example>{{ $preview['example'] }}</p>
        @endif

        <ul style="margin:0; padding:0; list-style:none; display:grid; gap:.3rem">
            @foreach($preview['rows'] as $row)
                <li style="display:flex; flex-wrap:wrap; gap:.2rem .75rem; overflow-wrap:anywhere">
                    <strong style="min-width:13rem">{{ ucfirst(\App\Services\OnCall\OnCallText::short($row['start'])) }} a {{ \App\Services\OnCall\OnCallText::short($row['end']) }}</strong>
                    <span>{{ $row['who'] ?? 'Sin técnico' }}</span>
                    <span style="{{ $status[$row['status']][1] }}">· {{ $status[$row['status']][0] }}</span>
                </li>
            @endforeach
        </ul>

        @foreach($preview['warnings'] as $warning)
            <p style="margin:0; color:#92400e" class="dark:text-amber-300">⚠ {{ $warning }}</p>
        @endforeach

        <p style="margin:0; opacity:.7">Solo se generan turnos que todavía no empezaron. Los cambios que hagas a mano en un turno (un reemplazo, por ejemplo) se respetan.</p>
    @endif
</div>
