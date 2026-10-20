<x-filament-panels::page>
    <style>
        .ci-card { border-radius: .9rem; padding: 1rem 1.1rem; border: 1px solid rgba(148,163,184,.3); background: #fff; }
        .dark .ci-card { background: rgba(255,255,255,.03); }
        .ci-row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
        .ci-muted { font-size: .85rem; opacity: .7; margin: .25rem 0 0; }
        .ci-stats { display: flex; flex-wrap: wrap; gap: .5rem; }
        .ci-stat { border-radius: .6rem; padding: .45rem .75rem; font-size: .85rem; font-weight: 600; background: rgba(148,163,184,.15); }
        .ci-stat.ok { background: rgba(34,197,94,.15); color: #15803d; }
        .ci-stat.warn { background: rgba(245,158,11,.18); color: #b45309; }
        .ci-stat.bad { background: rgba(239,68,68,.15); color: #b91c1c; }
        .ci-table-wrap { overflow-x: auto; }
        .ci-table { width: 100%; border-collapse: collapse; font-size: .85rem; min-width: 44rem; }
        .ci-table th, .ci-table td { text-align: left; padding: .5rem .55rem; border-top: 1px solid rgba(148,163,184,.2); vertical-align: top; }
        .ci-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; opacity: .65; border-top: 0; }
        .ci-err { color: #b91c1c; }
        .ci-warn { color: #b45309; }
        .ci-select { border-radius: .5rem; border: 1px solid rgba(148,163,184,.45); padding: .45rem 2rem .45rem .6rem; background: transparent; max-width: 100%; }
        .ci-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr)); }
        .ci-grid label { display: grid; gap: .3rem; font-size: .85rem; font-weight: 600; }
        .ci-steps { display: flex; flex-wrap: wrap; gap: .4rem; list-style: none; margin: 0; padding: 0; font-size: .8rem; }
        .ci-steps li { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .65rem; border-radius: 999px; background: rgba(148,163,184,.14); opacity: .75; }
        .ci-steps li[aria-current] { background: rgba(255,102,0,.14); color: #b84a00; font-weight: 700; opacity: 1; }
        .ci-steps b { display: inline-flex; width: 1.25rem; height: 1.25rem; border-radius: 999px; align-items: center; justify-content: center; background: rgba(148,163,184,.3); font-size: .72rem; }
        .ci-steps li[aria-current] b { background: #ff6600; color: #fff; }
        .ci-how { margin: .5rem 0 0; padding-left: 1.1rem; font-size: .85rem; line-height: 1.55; }
        .ci-note { margin: .6rem 0 0; padding: .55rem .75rem; border-radius: .6rem; font-size: .85rem; background: rgba(245,158,11,.12); color: #92400e; }
    </style>

    @php($stepIndex = ['upload' => 1, 'map' => 2, 'preview' => 3, 'done' => 4][$step] ?? 1)
    <ol class="ci-steps" aria-label="Pasos de la importación" data-import-steps>
        @foreach(['Archivo', 'Columnas', 'Revisar', 'Listo'] as $i => $label)
            <li @if($stepIndex === $i + 1) aria-current="step" @endif><b>{{ $i + 1 }}</b>{{ $label }}</li>
        @endforeach
    </ol>

    <div style="display:flex; flex-direction:column; gap:1rem" data-client-import data-step="{{ $step }}">
        @if($step === 'upload')
            <section class="ci-card" data-import-how>
                <p style="font-weight:700; margin:0">Cómo funciona</p>
                <ol class="ci-how">
                    <li>Subís tu planilla (podés usar la tuya o la plantilla de Ascento).</li>
                    <li>Antes de guardar nada, ves cada fila: cuáles son nuevas, cuáles ya existen y cuáles tienen errores.</li>
                    <li>Elegís qué importar y confirmás. Al final ves el resultado fila por fila.</li>
                </ol>
                <p class="ci-muted">Nunca se modifica ni se borra un cliente que ya tenés. Los edificios se cargan después, desde cada cliente.</p>
            </section>
            <section class="ci-card">
                <p style="font-weight:700; margin:0">1. Descargá la plantilla (opcional)</p>
                <p class="ci-muted">Columnas: nombre, persona_contacto, telefono, email y notas. Solo el nombre es obligatorio. Escribí los teléfonos como texto para no perder ceros ni prefijos.</p>
                <div style="margin-top:.6rem"><x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadTemplate" data-import-template>Descargar plantilla</x-filament::button></div>
            </section>
            <section class="ci-card">
                <p style="font-weight:700; margin:0">2. Subí tu archivo</p>
                <p class="ci-muted">.xlsx o .csv, hasta 2 MB y {{ $maxRows }} filas. Se lee solo la primera hoja; las fórmulas no se usan.</p>
                <form wire:submit="analyzeFile" class="ci-row" style="margin-top:.6rem">
                    <input type="file" wire:model="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" aria-label="Archivo de clientes" data-import-file>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,analyzeFile">Ver vista previa</x-filament::button>
                    <span wire:loading wire:target="file,analyzeFile" class="ci-muted">Leyendo el archivo…</span>
                </form>
                @error('file')<p class="ci-err" style="margin:.5rem 0 0">{{ $message }}</p>@enderror
            </section>
        @elseif($step === 'map')
            <section class="ci-card" data-import-mapping>
                <p style="font-weight:700; margin:0">Relacioná las columnas</p>
                <p class="ci-muted">No reconocimos todas las columnas. Elegí cuál corresponde a cada dato (el nombre es obligatorio).</p>
                <div class="ci-grid" style="margin-top:.75rem">
                    @foreach($fields as $field => $label)
                        <label>{{ $label }}{{ $field === 'name' ? ' *' : '' }}
                            <select class="ci-select" wire:model="mapping.{{ $field }}">
                                <option value="">— No importar —</option>
                                @foreach($headers as $i => $h)<option value="{{ $i }}">{{ $h !== '' ? $h : 'Columna '.($i + 1) }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                @error('mapping')<p class="ci-err" style="margin:.5rem 0 0">{{ $message }}</p>@enderror
                <div class="ci-row" style="margin-top:.9rem">
                    <x-filament::button wire:click="applyMapping">Ver vista previa</x-filament::button>
                    <x-filament::button color="gray" wire:click="restart">Subir otro archivo</x-filament::button>
                </div>
            </section>
        @elseif($step === 'preview')
            <section class="ci-card">
                <div class="ci-stats" data-import-counts>
                    <span class="ci-stat ok">{{ $counts['valid'] }} nuevos</span>
                    <span class="ci-stat warn">{{ $counts['matches'] }} posibles duplicados</span>
                    <span class="ci-stat">{{ $counts['duplicates'] }} ya existen</span>
                    <span class="ci-stat bad">{{ $counts['errors'] }} con errores</span>
                    <span class="ci-stat">{{ count($selected) }} elegidos para importar</span>
                </div>
                <p class="ci-muted" style="margin-top:.6rem">Los posibles duplicados quedan sin tildar: revisalos y tildá solo los que sean clientes distintos. Los que ya existen (mismo correo, o mismo nombre y teléfono) y las filas con errores no se importan. Nunca se modifica un cliente existente.</p>
                @if($ignored !== [])
                    <p class="ci-note" data-import-ignored>Estas columnas del archivo no se importan: <b>{{ implode(', ', $ignored) }}</b>. Si tienen datos que necesitás, copialos en «notas» o cargalos después en cada cliente.</p>
                @endif
                <div class="ci-row" style="margin-top:.6rem">
                    <x-filament::link tag="button" size="sm" wire:click="selectNew" data-import-select-new>Solo los nuevos</x-filament::link>
                    <x-filament::link tag="button" size="sm" wire:click="selectAll" data-import-select-all>Tildar todos los que se pueden importar</x-filament::link>
                    <x-filament::link tag="button" size="sm" color="gray" wire:click="selectAll(false)">Destildar todos</x-filament::link>
                </div>
                <div class="ci-row" style="margin-top:.75rem">
                    <label style="font-size:.85rem; font-weight:600">Tipo de cliente para todos
                        <select class="ci-select" wire:model="type" data-import-type style="margin-left:.4rem">
                            @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <span class="ci-muted" style="margin:0">Lo podés cambiar después en cada cliente.</span>
                </div>
                @error('type')<p class="ci-err">{{ $message }}</p>@enderror
                @error('rows')<p class="ci-err" style="margin:.5rem 0 0" data-import-error>{{ $message }}</p>@enderror
            </section>

            <section class="ci-card ci-table-wrap">
                <table class="ci-table" data-import-preview>
                    <thead><tr><th scope="col">Importar</th><th scope="col">Fila</th><th scope="col">Nombre</th><th scope="col">Contacto</th><th scope="col">Teléfono</th><th scope="col">Correo</th><th scope="col">Notas</th><th scope="col">Estado</th></tr></thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr wire:key="row-{{ $r['line'] }}" data-import-row="{{ $r['line'] }}">
                                <td>
                                    @if(\App\Services\Clients\ClientImport::selectable($r))
                                        <input type="checkbox" value="{{ $r['line'] }}" wire:model.live="selected" aria-label="Importar la fila {{ $r['line'] }}">
                                    @else
                                        <span aria-label="No se puede importar">—</span>
                                    @endif
                                </td>
                                <td>{{ $r['line'] }}</td>
                                <td>{{ $r['data']['name'] ?: '—' }}</td>
                                <td>{{ $r['data']['contact_person'] ?: '—' }}</td>
                                <td>{{ $r['data']['phone'] ?: '—' }}</td>
                                <td>{{ $r['data']['email'] ?: '—' }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($r['data']['notes'] ?? '', 60) ?: '—' }}</td>
                                <td>
                                    @if($r['errors'] !== [])
                                        <span class="ci-err">{{ implode(' ', $r['errors']) }}</span>
                                    @elseif($r['match'])
                                        <span class="ci-warn">{{ $r['matchLevel'] === 'clear' ? 'Ya existe (no se importa): ' : 'Posible duplicado: ' }}{{ $r['match'] }}</span>
                                    @else
                                        Nuevo
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <div class="ci-row">
                <x-filament::button wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" :disabled="count($selected) === 0" data-import-confirm>Importar {{ count($selected) }} {{ count($selected) === 1 ? 'cliente' : 'clientes' }}</x-filament::button>
                <x-filament::button color="gray" wire:click="restart">Cancelar</x-filament::button>
                <span wire:loading wire:target="confirm" class="ci-muted">Importando…</span>
            </div>
        @else
            <section class="ci-card" data-import-summary>
                <p style="font-weight:700; margin:0">Listo</p>
                <div class="ci-stats" style="margin-top:.6rem">
                    <span class="ci-stat ok">{{ $summary['imported'] }} importados</span>
                    <span class="ci-stat warn">{{ $summary['skipped'] }} omitidos</span>
                    <span class="ci-stat bad">{{ $summary['rejected'] }} rechazados</span>
                </div>
                @if($summary['skippedRows'] !== [] || $summary['rejectedRows'] !== [])
                    <div class="ci-table-wrap" style="margin-top:.75rem">
                        <table class="ci-table">
                            <thead><tr><th scope="col">Fila</th><th scope="col">Nombre</th><th scope="col">Resultado</th><th scope="col">Motivo</th></tr></thead>
                            <tbody>
                                @foreach($summary['skippedRows'] as $r)<tr><td>{{ $r['line'] }}</td><td>{{ $r['name'] }}</td><td>Omitido</td><td>{{ $r['reason'] }}</td></tr>@endforeach
                                @foreach($summary['rejectedRows'] as $r)<tr><td>{{ $r['line'] }}</td><td>{{ $r['name'] ?: '—' }}</td><td class="ci-err">Rechazado</td><td>{{ $r['reason'] }}</td></tr>@endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="ci-row" style="margin-top:.9rem">
                    <x-filament::button tag="a" :href="\App\Filament\Resources\Clients\ClientResource::getUrl('index')">Ver clientes</x-filament::button>
                    <x-filament::button color="gray" wire:click="restart">Importar otro archivo</x-filament::button>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
