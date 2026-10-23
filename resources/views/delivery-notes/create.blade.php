{{--
    Remito digital: lo completa el técnico al terminar una visita (mantenimiento
    o inspección del mes) o una orden de trabajo, con su firma y, si se puede,
    la del cliente. Mismos campos y reglas que antes; el servidor valida todo.
--}}
@php
    $typeLabel = $workOrder
        ? 'Orden de trabajo · '.\App\Support\WorkOrderLabels::type($workOrder->type)
        : ($assignmentType === 'inspection' ? 'Inspección del mes' : 'Mantenimiento del mes');
    $participants = $workOrder
        ? $workOrder->users->unique('id')->values()
        : $building->users()->wherePivot('type', $assignmentType)->orderBy('name')->get()->unique('id')->values();
    if ($participants->isEmpty()) {
        $participants = collect([auth()->user()]);
    }
@endphp
<x-app-layout>
<div class="t-page max-w-2xl lg:max-w-2xl">
    <a href="{{ $workOrder ? route('work-orders.show', ['company' => auth()->user()->company->slug, 'workOrder' => $workOrder]) : route('buildings.index', ['company' => auth()->user()->company->slug]) }}"
       class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $workOrder ? 'Orden de trabajo' : 'Visitas' }}
    </a>
    <header class="mb-4 mt-1 flex items-start justify-between gap-3">
        <div>
            <h1 class="t-h1">Nuevo remito</h1>
            <span class="t-chip-progress mt-1.5">{{ $typeLabel }}</span>
        </div>
        @if(auth()->user()->company?->logo)
            <img src="{{ asset('storage/'.auth()->user()->company->logo) }}" alt="{{ auth()->user()->company->name }}" class="h-12 w-12 shrink-0 rounded-xl bg-white object-contain p-1 ring-1 ring-ink-100">
        @endif
    </header>

    @if ($errors->any())
        <div id="remito-errors" class="t-alert-error mb-4" role="alert">
            <p class="mb-1">No se pudo generar el remito. Revisá los campos marcados en rojo:</p>
            <ul class="list-inside list-disc space-y-0.5 font-normal">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Sin fotos en el remito: las de antes y después se cargan y se ven en su trabajo (Visitas o Trabajos). --}}
    @if(! $workOrder && ($assignmentType ?? null) === 'inspection' && auth()->user()->company?->hasWorkPhotos() && ! ($visitInfo['blocked'] ?? null))
        {{-- Inspección (plan Empresa): acceso al reporte antes de firmar. Fuera del formulario del remito. --}}
        <section class="t-card mb-4 flex items-center justify-between gap-3 px-4 py-3" data-remito-inspection-report>
            <span class="text-sm text-ink-700">Observaciones y fotos de la inspección</span>
            @if($visitInfo['open_visit'] ?? null)
                <a href="{{ route('inspection-reports.show', ['company' => auth()->user()->company->slug, 'visit' => $visitInfo['open_visit']->id]) }}" class="t-btn-secondary t-btn-sm shrink-0">Reporte</a>
            @else
                <form method="POST" action="{{ route('visits.start', ['company' => auth()->user()->company->slug]) }}">
                    @csrf
                    <input type="hidden" name="building_id" value="{{ $building->id }}">
                    <input type="hidden" name="assignment_type" value="inspection">
                    <input type="hidden" name="month" value="{{ $month }}">
                    <input type="hidden" name="year" value="{{ $year }}">
                    <input type="hidden" name="then" value="report">
                    <button type="submit" class="t-btn-secondary t-btn-sm shrink-0">Crear reporte</button>
                </form>
            @endif
        </section>
    @endif
    {{-- Borrador por trabajo: una orden, o un tipo de visita de UN período (un borrador viejo de otro mes no se recupera en este remito). --}}
    {{-- novalidate: la validación la hace este formulario (marca cada campo y lleva al primero) y la repite el servidor. --}}
    <form method="POST" action="{{ route('delivery-notes.store', ['company' => auth()->user()->company->slug]) }}" id="remito-form" class="space-y-4" novalidate
          data-draft="remito.{{ auth()->id() }}.{{ $building->id }}.{{ $workOrder ? 'ot'.$workOrder->id : $assignmentType.'.'.$year.'-'.$month }}" @if($errors->any()) data-draft-restore="off" @endif>
        @csrf
        @include('partials.draft-notice', ['media' => 'Las firmas y los materiales se vuelven a cargar.'])
        @if($workOrder)
            <input type="hidden" name="work_order_id" value="{{ $workOrder->id }}">
        @endif
        <input type="hidden" name="building_id" value="{{ $building->id }}">
        @isset($submissionToken)
            {{-- Identidad de este formulario: si se reenvía, no se registra otra visita. --}}
            <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
        @endisset
        <input type="hidden" name="year" value="{{ $year }}">
        <input type="hidden" name="elevator_quantity" value="{{ $building->elevator_count }}">
        <input type="hidden" name="freight_elevator_quantity" value="{{ $building->freight_elevator_count }}">
        {{-- Si el servidor rechazó el envío por otro campo, la firma vuelve cargada, solo si es una firma válida (imagen real con trazo): una en blanco o inválida se vuelve a firmar. --}}
        @php
            $oldSignature = fn (string $field) => ! $errors->has($field) && is_string(old($field)) && \App\Support\DeliveryNotes\SignatureImage::normalize(old($field))[1] === null ? old($field) : '';
        @endphp
        <input type="hidden" name="signature" id="sig-tech-input" value="{{ $oldSignature('signature') }}">
        <input type="hidden" name="client_signature" id="sig-client-input" value="{{ $oldSignature('client_signature') }}">
        <input type="hidden" name="assignment_type" value="{{ $workOrder ? 'work_order' : $assignmentType }}">

        {{-- 1. Dónde y cuándo --}}
        <section class="t-card divide-y divide-ink-100">
            <div class="px-4 py-3">
                <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Edificio</div>
                <div class="font-semibold text-ink">{{ $building->name }}</div>
                <div class="text-sm text-ink-700">{{ $building->address }}{{ $building->client?->name ? ' · '.$building->client->name : '' }}</div>
            </div>
            <div class="grid grid-cols-2 divide-x divide-ink-100">
                <div class="px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Equipos</div>
                    <div class="font-semibold text-ink">
                        @if($workOrder)
                            {{ $workOrder->unit ?: 'Según la orden' }}
                        @else
                            {{ $building->elevator_count }} asc. · {{ $building->freight_elevator_count }} mont.
                        @endif
                    </div>
                </div>
                <div class="px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Fecha</div>
                    <div class="font-semibold text-ink">{{ now()->format('d/m/Y') }}</div>
                </div>
            </div>
            <div class="px-4 py-3">
                <label for="month" class="t-label">Corresponde al mes</label>
                <select id="month" name="month" class="t-input" data-draft-field data-field="month">
                    @foreach(range(1, 12) as $m)
                        <option value="{{ $m }}" @selected(old('month', $month) == $m)>{{ ucfirst(\Carbon\Carbon::create()->month($m)->locale('es')->monthName) }} {{ $year }}</option>
                    @endforeach
                </select>
                @isset($visitInfo)
                    @if($visitInfo['blocked'])
                        <p class="mt-2 text-sm font-semibold text-red-700" data-visit-blocked>{{ $visitInfo['blocked'] }} Elegí otro mes o revisá la pantalla de Visitas.</p>
                    @elseif($visitInfo['open_visit'])
                        <p class="mt-2 text-xs text-ink-500" data-open-visit>
                            Completa la visita en curso{{ $visitInfo['expected'] > 1 ? ' ('.$visitInfo['slot'].' de '.$visitInfo['expected'].')' : '' }}.
                            <a class="font-semibold text-brand-700 underline" href="{{ route('visits.show', ['company' => auth()->user()->company->slug, 'visit' => $visitInfo['open_visit']->id]) }}">Ver la visita</a>
                        </p>
                    @elseif($visitInfo['expected'] > 1 || $visitInfo['period'])
                        <p class="mt-2 text-xs text-ink-500" data-visit-info>
                            Frecuencia: {{ $visitInfo['frequency'] }}.
                            @if($visitInfo['expected'] > 1) Vas a registrar la visita {{ $visitInfo['slot'] }} de {{ $visitInfo['expected'] }} del mes. @endif
                            @if($visitInfo['period']) Cuenta para el período {{ $visitInfo['period'] }}. @endif
                        </p>
                    @endif
                @endisset
            </div>
        </section>

        {{-- 2a. Trabajos de la visita (intervenciones): qué se hizo en cada uno y cómo quedó. --}}
        @php
            $jobs = $workOrder ? $workOrder->jobs()->get() : collect();
        @endphp
        @if($jobs->isNotEmpty())
            <section class="t-card space-y-4 p-4" data-remito-jobs>
                <div>
                    <h2 class="t-label mb-0">Trabajos de esta visita ({{ $jobs->count() }})</h2>
                    <p class="text-xs text-ink-500">Cada uno queda por separado en el remito, con su resultado.</p>
                </div>
                @foreach($jobs as $i => $job)
                    <div class="rounded-xl border border-ink-100 p-3" data-remito-job="{{ $job->id }}">
                        <div class="text-sm font-bold text-ink">Trabajo {{ $i + 1 }} · {{ $job->elevator_number }}</div>
                        <p class="mt-0.5 text-sm text-ink-700">{{ $job->description }}</p>
                        <label class="t-label mt-3" for="job-{{ $job->id }}-done">Qué hiciste</label>
                        <textarea id="job-{{ $job->id }}-done" name="jobs[{{ $job->id }}][work_done]" rows="3" maxlength="5000" required class="t-input resize-y" data-job-done data-field="jobs.{{ $job->id }}.work_done" data-required-message="Contá qué hiciste en el trabajo {{ $i + 1 }}.">{{ old('jobs.'.$job->id.'.work_done') }}</textarea>
                        <label class="t-label mt-3" for="job-{{ $job->id }}-result">Cómo quedó</label>
                        <select id="job-{{ $job->id }}-result" name="jobs[{{ $job->id }}][result]" required class="t-input" data-job-result data-field="jobs.{{ $job->id }}.result" data-required-message="Elegí cómo quedó el trabajo {{ $i + 1 }}.">
                            <option value="">Elegí el resultado</option>
                            @foreach(\App\Models\Report::RESULTS as $value => $label)
                                <option value="{{ $value }}" @selected(old('jobs.'.$job->id.'.result') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <label class="t-label mt-3" for="job-{{ $job->id }}-state">Cómo quedó el equipo</label>
                        <select id="job-{{ $job->id }}-state" name="jobs[{{ $job->id }}][elevator_state]" required class="t-input" data-job-state data-field="jobs.{{ $job->id }}.elevator_state" data-required-message="Elegí en qué estado quedó el equipo del trabajo {{ $i + 1 }}.">
                            <option value="">Elegí el estado</option>
                            @foreach(\App\Models\Report::ELEVATOR_STATES as $value => $label)
                                <option value="{{ $value }}" @selected(old('jobs.'.$job->id.'.elevator_state') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        {{-- Pendientes: obligatorios si el trabajo no quedó resuelto. --}}
                        <label class="t-label mt-3" for="job-{{ $job->id }}-pending">Qué quedó pendiente <span class="font-normal text-ink-500" data-pending-hint>(si no quedó resuelto)</span></label>
                        <textarea id="job-{{ $job->id }}-pending" name="jobs[{{ $job->id }}][pending_work]" rows="2" maxlength="2000" class="t-input resize-y" data-job-pending data-field="jobs.{{ $job->id }}.pending_work" data-required-message="Contá qué quedó pendiente en el trabajo {{ $i + 1 }}." placeholder="Ej.: falta el repuesto; volver a cambiar el operador">{{ old('jobs.'.$job->id.'.pending_work') }}</textarea>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- 2. Trabajo --}}
        <section class="t-card space-y-4 p-4">
            <div>
                <label class="t-label" for="description">{{ $jobs->isNotEmpty() ? 'Resumen de la visita (opcional)' : 'Trabajo realizado' }}</label>
                <textarea name="description" id="description" data-draft-field rows="{{ $jobs->isNotEmpty() ? 3 : 6 }}" @if($jobs->isEmpty()) required @endif maxlength="5000" class="t-input resize-y" data-field="description" data-required-message="Escribí el trabajo realizado." @if($jobs->isNotEmpty()) data-optional @endif
                          placeholder="{{ $jobs->isNotEmpty() ? 'Si lo dejás vacío, el remito lista los trabajos de arriba' : 'Detallá todo el trabajo realizado durante la visita' }}">{{ old('description') }}</textarea>
            </div>

            <label class="flex min-h-[52px] cursor-pointer select-none items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4">
                <input type="checkbox" name="performed" value="1" data-draft-field @checked($errors->any() ? old('performed') == '1' : true) class="h-5 w-5 shrink-0 rounded border-ink-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm font-semibold text-emerald-900">Trabajo realizado correctamente</span>
            </label>
            <p class="-mt-2 text-xs text-ink-500">Si no se pudo hacer, destildalo: la oficina lo ve y coordina la nueva visita.</p>

            <fieldset>
                <legend class="t-label">Participantes</legend>
                <div class="space-y-2">
                    @foreach($participants as $participant)
                        <label class="flex min-h-[52px] items-center justify-between gap-3 rounded-xl border border-ink-100 bg-ink-50/60 px-4">
                            <span>
                                <span class="block font-semibold text-ink">{{ $participant->name }}</span>
                                <span class="block text-xs text-ink-500">{{ $participant->id == auth()->id() ? 'Vos' : 'Participó del trabajo' }}</span>
                            </span>
                            <input type="checkbox" name="participants[]" value="{{ $participant->id }}"
                                   @checked($participant->id == auth()->id() || in_array($participant->id, old('participants', $participants->pluck('id')->toArray())))
                                   @disabled($participant->id == auth()->id())
                                   class="h-5 w-5 rounded border-ink-300 text-brand focus:ring-brand">
                            @if($participant->id == auth()->id())
                                <input type="hidden" name="participants[]" value="{{ $participant->id }}">
                            @endif
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>

        {{-- Materiales usados (solo órdenes de trabajo con stock cargado). --}}
        @if($workOrder && isset($stockItems) && $stockItems->isNotEmpty())
            <section id="materialsSection" class="t-card p-4">
                <h2 class="font-bold text-ink">Materiales utilizados</h2>
                <p class="mb-3 text-xs text-ink-500">Opcional. Agregá los repuestos que usaste: se descuentan del stock al guardar el remito.</p>

                @if($workOrder->materials->isNotEmpty())
                    <div class="mb-3 rounded-xl bg-ink-50 p-3 text-sm">
                        <div class="mb-1 text-xs font-bold uppercase text-ink-500">Ya cargados por la oficina</div>
                        @foreach($workOrder->materials as $material)
                            <div class="flex justify-between"><span>{{ $material->stockItem?->name }}</span><span class="font-semibold">{{ $material->stockItem?->formatQuantity($material->quantity) }}</span></div>
                        @endforeach
                    </div>
                @endif

                @if($errors->has('materials') || $errors->has('materials.*'))
                    <div class="t-field-error mb-2" data-materials-errors>
                        @foreach(collect($errors->get('materials'))->merge(collect($errors->get('materials.*'))->flatten())->unique() as $message)<p>{{ $message }}</p>@endforeach
                    </div>
                @endif
                <div id="materialRows" class="space-y-3"></div>
                <button type="button" id="addMaterial" class="t-btn-secondary mt-3 w-full border-dashed">+ Agregar material</button>

                <template id="materialTemplate">
                    <div class="material-row space-y-2 rounded-xl border border-ink-100 p-3">
                        <select class="material-item t-input" required>
                            <option value="">Elegí el material…</option>
                            @foreach($stockItems as $item)
                                <option value="{{ $item->id }}" data-unit="{{ $item->unit }}" data-stock="{{ (float) $item->current_stock }}">
                                    {{ $item->name }}@if($item->code) ({{ $item->code }})@endif
                                </option>
                            @endforeach
                        </select>
                        <div class="flex items-center gap-2">
                            <input type="number" class="material-qty t-input flex-1" min="0.01" max="99999" step="0.01" inputmode="decimal" placeholder="Cantidad" required>
                            <span class="material-unit w-16 text-sm text-ink-500"></span>
                            <button type="button" class="material-remove t-btn-secondary t-btn-sm text-red-700">Quitar</button>
                        </div>
                        <p class="material-stock text-xs"></p>
                    </div>
                </template>
            </section>
        @endif


        {{-- Prueba de paracaídas (opcional): solo se registra la que el técnico elige; «No se hizo» no crea nada. --}}
        @if(($parachuteRows ?? collect())->isNotEmpty())
            @php
                $parachuteStatus = fn (array $row) => match ($row['status']) {
                    \App\Services\Parachute\ParachuteService::OVERDUE => ['Vencida desde el '.$row['next_due']->format('d/m/Y'), 'text-red-700'],
                    \App\Services\Parachute\ParachuteService::UPCOMING => ['Prevista para el '.$row['next_due']->format('d/m/Y'), 'text-amber-700'],
                    \App\Services\Parachute\ParachuteService::FAILED => ['La última no fue satisfactoria', 'text-red-700'],
                    \App\Services\Parachute\ParachuteService::NO_RECORD => ['Sin pruebas registradas', 'text-ink-500'],
                    \App\Services\Parachute\ParachuteService::UNCONFIGURED => ['Sin periodicidad definida por la oficina', 'text-ink-500'],
                    default => ['Al día'.($row['next_due'] ? ' · próxima: '.$row['next_due']->format('d/m/Y') : ''), 'text-emerald-700'],
                };
            @endphp
            <section class="t-card space-y-3 p-4" data-remito-parachute-form>
                <div>
                    <h2 class="font-semibold text-ink">Prueba de paracaídas</h2>
                    <p class="text-xs text-ink-500">Completalo solo si hiciste la prueba en esta visita. Queda registrada con la fecha de hoy, a tu nombre y en este remito.</p>
                </div>
                @foreach($parachuteRows as $row)
                    @php([$label, $tone] = $parachuteStatus($row))
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-ink-100 bg-ink-50/60 px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $row['elevator']->label }}</p>
                            <p class="text-xs font-semibold {{ $tone }}">{{ $label }}</p>
                        </div>
                        <label class="sr-only" for="parachute-{{ $row['elevator']->id }}">Resultado de la prueba de {{ $row['elevator']->label }}</label>
                        <select id="parachute-{{ $row['elevator']->id }}" name="parachute[{{ $row['elevator']->id }}]" class="t-input w-full py-2 sm:w-auto sm:min-w-[14rem]">
                            <option value="">No se hizo en esta visita</option>
                            @foreach(\App\Models\ParachuteTest::RESULTS as $value => $resultLabel)
                                <option value="{{ $value }}" @selected(old('parachute.'.$row['elevator']->id) === $value)>{{ $resultLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                @error('parachute')<p class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
            </section>
        @endif

        {{-- 3. Firmas --}}
        <section class="t-card p-4">
            <div class="mb-3 flex items-baseline justify-between gap-2">
                <h2 class="font-bold text-ink">Firmas</h2>
                <span class="text-xs text-ink-500">Tocá el recuadro para firmar</span>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach(['tech' => ['Técnico', true], 'client' => ['Cliente', false]] as $key => [$who, $required])
                    <div class="flex flex-col gap-2">
                        <div class="text-xs font-bold uppercase tracking-wider text-ink-700">
                            {{ $who }} <span class="font-normal normal-case tracking-normal {{ $required ? 'text-red-600' : 'text-ink-500' }}">{{ $required ? '(obligatoria)' : '(opcional)' }}</span>
                        </div>
                        <button type="button" id="{{ $key }}-trigger" data-target="{{ $key }}" @if($key === 'tech') data-field="signature" @else data-field="client_signature" @endif
                                class="sig-trigger relative flex h-28 w-full items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-ink-300 bg-ink-50 transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                                aria-label="Firmar como {{ mb_strtolower($who) }}">
                            <span id="{{ $key }}-placeholder" class="pointer-events-none flex flex-col items-center gap-1 text-ink-500">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17c3-3 4.5-9 7-9s-1 9 2 9 3-5 5-5 2 3 4 3"/></svg>
                                <span class="text-xs font-semibold">Tocá para firmar</span>
                            </span>
                            <img id="{{ $key }}-preview" src="" alt="" class="pointer-events-none absolute inset-0 hidden h-full w-full object-contain p-1.5">
                        </button>
                        @if($key === 'tech')
                            <p id="tech-error" class="t-field-error {{ $errors->has('signature') ? '' : 'hidden' }}">{{ $errors->first('signature') ?: 'Falta tu firma: tocá el recuadro y firmá.' }}</p>
                        @elseif($errors->has('client_signature'))
                            <p class="t-field-error">{{ $errors->first('client_signature') }}</p>
                        @endif
                        <div>
                            <label class="mb-1 block text-xs text-ink-500" for="{{ $key }}-name">Aclaración</label>
                            @if($key === 'tech')
                                <input type="text" id="tech-name" name="signature_name" data-draft-field value="{{ old('signature_name', auth()->user()->name) }}" class="t-input py-2.5" placeholder="Nombre y apellido" data-field="signature_name" data-required-message="Escribí tu nombre (aclaración de la firma).">
                            @else
                                <input type="text" id="client-name" name="client_signature_name" data-draft-field value="{{ old('client_signature_name') }}" class="t-input py-2.5" placeholder="Nombre y apellido" data-field="client_signature_name">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div id="remito-client-error" class="t-alert-error hidden" role="alert"></div>
        <button id="submit-remito" type="submit" class="t-btn-primary w-full text-base">
            <span id="submit-text">Generar remito</span>
        </button>
    </form>
</div>

{{-- ═══════════════════════════ MODAL DE FIRMA ═══════════════════════════ --}}
<div id="sig-modal" role="dialog" aria-modal="true" aria-labelledby="sig-modal-title"
    style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.72);">

    {{-- Wrapper centrado —— NO usar flex en el overlay mismo para evitar bugs de altura en Safari --}}
    <div style="display:flex; min-height:100%; align-items:center; justify-content:center; padding:16px;">

        <div style="background:#fff; border-radius:20px; box-shadow:0 25px 60px rgba(0,0,0,0.3);
                    width:100%; max-width:440px; display:flex; flex-direction:column; max-height:88vh;">

            {{-- Cabecera --}}
            <div style="display:flex; align-items:center; justify-content:space-between;
                        padding:20px 20px 16px; border-bottom:1px solid #f1f5f9; flex-shrink:0;">
                <h2 id="sig-modal-title" style="font-size:16px; font-weight:900; color:#1e293b; margin:0;"></h2>
                <button id="sig-modal-close" type="button"
                    style="width:32px; height:32px; border:none; background:#f1f5f9; border-radius:50%;
                           cursor:pointer; font-size:18px; color:#64748b; display:flex; align-items:center;
                           justify-content:center; flex-shrink:0;">
                    &times;
                </button>
            </div>

            {{-- Instrucción --}}
            <p style="margin:12px 20px 8px; font-size:13px; color:#94a3b8; flex-shrink:0;">
                Firmá con el dedo o el mouse dentro del recuadro.
            </p>

            {{-- Canvas --}}
            <div style="padding:0 20px; flex:1; min-height:0;">
                <div style="border:2px solid #e2e8f0; border-radius:14px; overflow:hidden;
                            height:100%; min-height:190px; background:#fff;">
                    <canvas id="sig-modal-canvas"
                        style="touch-action:none; display:block; width:100%; height:100%;"></canvas>
                </div>
            </div>

            {{-- Botones --}}
            <div style="display:flex; gap:10px; padding:16px 20px 20px; flex-shrink:0;">
                <button id="sig-modal-clear" type="button"
                    style="flex:1; border:2px solid #e2e8f0; background:#fff; border-radius:12px;
                           padding:11px 16px; font-size:14px; font-weight:600; color:#475569;
                           cursor:pointer;">
                    Limpiar
                </button>
                <button id="sig-modal-confirm" type="button"
                    style="flex:2; border:none; background:#FF6600; border-radius:12px;
                           padding:11px 16px; font-size:15px; font-weight:800; color:#11161D;
                           cursor:pointer;">
                    Listo
                </button>
            </div>

        </div>
    </div>
</div>
@vite('resources/js/remito-pad.js')
<script>
document.addEventListener('DOMContentLoaded', function () {

    const modal       = document.getElementById('sig-modal');
    const modalTitle  = document.getElementById('sig-modal-title');
    const modalCanvas = document.getElementById('sig-modal-canvas');
    const btnClear    = document.getElementById('sig-modal-clear');
    const btnConfirm  = document.getElementById('sig-modal-confirm');
    const btnClose    = document.getElementById('sig-modal-close');
    const form        = document.getElementById('remito-form');
    const techError   = document.getElementById('tech-error');

    const pads = {
        tech: {
            hiddenInput: document.getElementById('sig-tech-input'),
            trigger:     document.getElementById('tech-trigger'),
            placeholder: document.getElementById('tech-placeholder'),
            preview:     document.getElementById('tech-preview'),
            title:       'Firma del técnico',
            dataURL:     null,
        },
        client: {
            hiddenInput: document.getElementById('sig-client-input'),
            trigger:     document.getElementById('client-trigger'),
            placeholder: document.getElementById('client-placeholder'),
            preview:     document.getElementById('client-preview'),
            title:       'Firma del cliente',
            dataURL:     null,
        },
    };

    let signaturePad  = null;
    let currentTarget = null;

    // Firma que volvió del servidor (otro campo tenía un error): se muestra
    // como firmada, sin tener que volver a firmar.
    Object.values(pads).forEach(function (pad) {
        if (!pad.hiddenInput.value) return;
        pad.dataURL = pad.hiddenInput.value;
        pad.preview.src = pad.dataURL;
        pad.preview.classList.remove('hidden');
        pad.placeholder.classList.add('hidden');
        pad.trigger.style.borderStyle = 'solid';
        pad.trigger.style.borderColor = '#10B981';
        pad.trigger.style.background  = '#ECFDF5';
    });

    // La librería de firma no cargó (p. ej. bloqueada por una extensión):
    // se avisa en vez de dejar un recuadro que no dibuja.
    function padAvailable() {
        if (typeof window.SignaturePad === 'function') return true;
        showFormError('No se pudo cargar el recuadro de firma. Recargá la página; si usás un bloqueador de contenido, permití este sitio.');
        return false;
    }

    function initPad() {
        if (signaturePad) signaturePad.off();
        signaturePad = new SignaturePad(modalCanvas, {
            penColor: '#111111',
            backgroundColor: 'rgba(255,255,255,0)',
            minWidth: 1,
            maxWidth: 3,
        });
    }

    function resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect  = modalCanvas.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;

        const saved = signaturePad ? signaturePad.toData() : [];

        modalCanvas.width  = Math.round(rect.width  * ratio);
        modalCanvas.height = Math.round(rect.height * ratio);
        modalCanvas.getContext('2d').scale(ratio, ratio);

        signaturePad.clear();
        if (saved.length) signaturePad.fromData(saved);
    }

    function openModal(target) {
        if (!padAvailable()) return;
        currentTarget = target;
        modalTitle.textContent = pads[target].title;
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        initPad();

        // Doble rAF: esperar que el navegador pinte el modal antes de medir
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                resizeCanvas();

                if (pads[target].dataURL) {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const img   = new Image();
                    img.onload  = function () {
                        modalCanvas.getContext('2d').drawImage(
                            img, 0, 0,
                            modalCanvas.width  / ratio,
                            modalCanvas.height / ratio
                        );
                    };
                    img.src = pads[target].dataURL;
                }
            });
        });
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        currentTarget = null;
    }

    btnConfirm.addEventListener('click', function () {
        if (!currentTarget) return;
        if (signaturePad.isEmpty()) { closeModal(); return; }

        const dataURL = signaturePad.toDataURL('image/png');
        const pad     = pads[currentTarget];

        pad.dataURL            = dataURL;
        pad.hiddenInput.value  = dataURL;

        pad.preview.src = dataURL;
        pad.preview.classList.remove('hidden');
        pad.placeholder.classList.add('hidden');

        pad.trigger.style.borderStyle = 'solid';
        pad.trigger.style.borderColor = '#10B981';
        pad.trigger.style.background  = '#ECFDF5';

        if (currentTarget === 'tech') {
            techError.classList.add('hidden');
            pad.trigger.classList.remove('t-input-invalid');
            pad.trigger.removeAttribute('aria-invalid');
        }

        closeModal();
    });

    btnClear.addEventListener('click', function () { signaturePad.clear(); });
    btnClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target === modal.firstElementChild) closeModal();
    });

    document.querySelectorAll('.sig-trigger').forEach(function (el) {
        el.addEventListener('click', function () { openModal(el.dataset.target); });
    });

    let resizeTimer;
    window.addEventListener('resize', function () {
        if (modal.style.display === 'none' || !modal.style.display) return;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(resizeCanvas, 150);
    });

    let sending = false;
    const formError = document.getElementById('remito-client-error');
    function showFormError(message) {
        formError.textContent = message;
        formError.classList.remove('hidden');
        formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /*
    | Errores por campo (los de este formulario y los que devuelve el servidor):
    | borde rojo, mensaje debajo del campo, la lista arriba y el foco en el
    | primero. Nada se relaja: el servidor vuelve a validar todo.
    */
    function fieldError(el, message) {
        if (!el) return;
        el.classList.add('t-input-invalid');
        el.setAttribute('aria-invalid', 'true');
        if (el.id === 'tech-trigger') {
            techError.textContent = message;
            techError.classList.remove('hidden');
            el.style.borderStyle = 'solid';
            el.style.borderColor = '#f87171';
            return;
        }
        const next = el.nextElementSibling;
        if (next && next.classList.contains('t-field-error') && next.dataset.clientError) {
            next.textContent = message;
            return;
        }
        const note = document.createElement('p');
        note.className = 't-field-error';
        note.dataset.clientError = '1';
        note.textContent = message;
        el.insertAdjacentElement('afterend', note);
    }

    function clearFieldErrors() {
        form.querySelectorAll('.t-input-invalid').forEach(function (el) {
            el.classList.remove('t-input-invalid');
            el.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('[data-client-error]').forEach(function (el) { el.remove(); });
        if (!pads.tech.dataURL) techError.classList.add('hidden');
    }

    function goTo(el) {
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        try { el.focus({ preventScroll: true }); } catch (err) {}
    }

    function showProblems(list) {
        formError.innerHTML = '';
        const title = document.createElement('p');
        title.textContent = list.length === 1 ? 'Falta completar un dato:' : 'Faltan completar ' + list.length + ' datos:';
        const ul = document.createElement('ul');
        ul.className = 'mt-1 list-inside list-disc font-normal';
        list.forEach(function (problem) {
            const li = document.createElement('li');
            li.textContent = problem.message;
            ul.appendChild(li);
        });
        formError.append(title, ul);
        formError.classList.remove('hidden');
    }

    // Al corregir un campo, deja de estar en rojo.
    form.addEventListener('input', function (e) {
        if (!e.target.classList || !e.target.classList.contains('t-input-invalid')) return;
        e.target.classList.remove('t-input-invalid');
        e.target.removeAttribute('aria-invalid');
        const next = e.target.nextElementSibling;
        if (next && next.dataset && next.dataset.clientError) next.remove();
    });
    // Los selects avisan con «change»: mismo tratamiento que al escribir.
    form.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('t-input-invalid')) e.target.dispatchEvent(new Event('input', { bubbles: true }));
    });

    function collectProblems() {
        const problems = [];
        const add = function (el, message) { problems.push({ el: el, message: message }); };

        // Trabajos de la visita: lo que se hizo, el resultado, cómo quedó el equipo y, si no quedó resuelto, lo pendiente.
        form.querySelectorAll('[data-job-done], [data-job-result], [data-job-state]').forEach(function (field) {
            if (!field.value.trim()) add(field, field.dataset.requiredMessage);
        });
        form.querySelectorAll('[data-job-pending]').forEach(function (field) {
            const result = form.querySelector('[name="' + field.name.replace('[pending_work]', '[result]') + '"]');
            if (result && result.value && result.value !== 'resolved' && !field.value.trim()) add(field, field.dataset.requiredMessage);
        });

        const description = document.getElementById('description');
        if (!description.value.trim() && !description.hasAttribute('data-optional')) add(description, description.dataset.requiredMessage);

        // Materiales: si se agregó un renglón, material y cantidad mayor que cero.
        form.querySelectorAll('.material-row').forEach(function (row, i) {
            const item = row.querySelector('.material-item');
            const qty = row.querySelector('.material-qty');
            if (!item.value) add(item, 'Elegí el material del renglón ' + (i + 1) + ' (o quitalo).');
            const amount = parseFloat(qty.value);
            if (!(amount > 0)) add(qty, 'Indicá una cantidad mayor que cero en el renglón ' + (i + 1) + '.');
        });

        if (!pads.tech.dataURL) add(pads.tech.trigger, 'Falta tu firma: tocá el recuadro y firmá.');
        const techName = document.getElementById('tech-name');
        if (!techName.value.trim()) add(techName, techName.dataset.requiredMessage);

        return problems;
    }

    // Errores que devolvió el servidor: se marcan en su campo y se va al primero.
    const serverErrors = @json($errors->getMessages());
    if (Object.keys(serverErrors).length) {
        let first = null;
        Object.entries(serverErrors).forEach(function ([name, messages]) {
            const el = form.querySelector('[data-field="' + name + '"]');
            if (!el) return;
            fieldError(el, messages[0]);
            first = first || el;
        });
        window.addEventListener('load', function () { goTo(first || document.getElementById('remito-errors')); });
    }

form.addEventListener('submit', function (e) {

    if (sending) {
        e.preventDefault();
        return;
    }

    clearFieldErrors();
    const problems = collectProblems();
    if (problems.length) {
        e.preventDefault();
        problems.forEach(function (problem) { fieldError(problem.el, problem.message); });
        showProblems(problems);
        goTo(problems[0].el);
        return;
    }

    // Sin señal: no se envía (se perderían las firmas en la página de error
    // del navegador). Todo queda cargado; se manda cuando vuelva la conexión.
    if (navigator.onLine === false) {
        e.preventDefault();
        showFormError('No hay conexión a internet. El remito NO se envió, pero no se perdió nada: esperá a tener señal y tocá "Generar remito" de nuevo.');
        return;
    }

    sending = true;

    const btn = document.getElementById('submit-remito');
    const text = document.getElementById('submit-text');

    formError.classList.add('hidden');
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');

    text.textContent = 'Generando remito…';

    pads.tech.hiddenInput.value = pads.tech.dataURL;
    pads.client.hiddenInput.value = pads.client.dataURL || '';
});

// Volver atrás después de enviar (p. ej. desde una página de error sin
// señal): el navegador puede restaurar esta página desde su caché con el
// botón deshabilitado. Se rehabilita; el token del formulario evita duplicar.
const submitText = document.getElementById('submit-text').textContent;
window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    sending = false;
    document.getElementById('submit-remito').disabled = false;
    document.getElementById('submit-remito').removeAttribute('aria-busy');
    document.getElementById('submit-text').textContent = submitText;
});

});
</script>




<script>
(() => {
    const rows = document.getElementById('materialRows');
    if (!rows) return;
    const template = document.getElementById('materialTemplate');
    const old = @json(array_values(old('materials', [])));
    let next = 0;

    function refresh(row) {
        const option = row.querySelector('.material-item').selectedOptions[0];
        const qty = parseFloat(row.querySelector('.material-qty').value || '0');
        const info = row.querySelector('.material-stock');
        row.querySelector('.material-unit').textContent = option?.dataset.unit || '';

        if (!option || !option.value) { info.textContent = ''; return; }

        const stock = parseFloat(option.dataset.stock);
        const fmt = (n) => n.toLocaleString('es-AR', { maximumFractionDigits: 2 }) + ' ' + option.dataset.unit;
        info.className = 'material-stock text-xs ' + (qty > stock ? 'text-brand-700 font-semibold' : 'text-ink-500');
        info.textContent = qty > stock
            ? 'Atención: hay ' + fmt(stock) + '. Se registra igual; avisale a la oficina para reponer.'
            : 'Hay ' + fmt(stock) + ' en stock.';
    }

    function add(values = {}) {
        const row = template.content.firstElementChild.cloneNode(true);
        const i = next++;
        const item = row.querySelector('.material-item');
        const qty = row.querySelector('.material-qty');
        item.name = `materials[${i}][stock_item_id]`;
        qty.name = `materials[${i}][quantity]`;
        item.value = values.stock_item_id || '';
        qty.value = values.quantity || '';
        item.addEventListener('change', () => refresh(row));
        qty.addEventListener('input', () => refresh(row));
        row.querySelector('.material-remove').addEventListener('click', () => row.remove());
        rows.append(row);
        refresh(row);
        return row;
    }

    document.getElementById('addMaterial').addEventListener('click', () => add().querySelector('.material-item').focus());
    old.forEach(add);
})();
</script>
</x-app-layout>
