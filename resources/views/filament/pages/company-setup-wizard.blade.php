{{--
    Configuración inicial guiada (CompanySetupWizard): una pantalla por paso,
    pocos campos, progreso visible y «Volver» / «Continuar». Los campos son
    los mismos del panel; todo se guarda al continuar.
--}}
@php
    $tech = $progress['technician'];
    $client = $progress['client'];
    $building = $progress['building'];
    $order = $progress['order'];
    $saved = fn (string $title, string $text) => '<div class="sw-saved"><span class="sw-check" aria-hidden="true">✓</span><div><p class="sw-saved-t">'.e($title).'</p><p class="sw-saved-d">'.e($text).'</p></div></div>';
    $headings = [
        'welcome' => ['Configuremos '.$company->name, 'En 5 pasos cortos dejás lista tu empresa: tus datos, tu primer técnico, un cliente, un edificio con sus ascensores y tu primera orden de trabajo. Se guarda a medida que avanzás.'],
        'company' => ['Los datos de tu empresa', 'Así te van a ver tus clientes en remitos y presupuestos.'],
        'brand' => ['Tu logo y tu color', 'Opcional: lo podés cambiar cuando quieras en «Mi empresa».'],
        'technician' => ['Tu primer técnico', 'La persona que sale a trabajar. Entra a Ascento desde el celular con este correo y esta contraseña.'],
        'client' => ['Tu primer cliente', 'Un consorcio, una administración o una empresa para la que trabajás.'],
        'building' => ['Un edificio de '.($client?->name ?? 'tu cliente'), 'Su dirección y sus ascensores. Con los equipos cargados se programan los mantenimientos.'],
        'work_order' => ['Tu primera orden de trabajo', 'En '.trim(($building?->name ?? '').' '.($building?->address ?? '')).', asignada a '.($tech?->name ?? 'tu técnico').'.'],
        'done' => ['¡Listo, '.$company->name.' ya está configurada!', 'Esto es lo que cargaste. Desde el inicio seguís con el resto.'],
    ];
    [$title, $lead] = $headings[$screen];
@endphp
<div class="sw" style="--sw-brand: {{ $color }}" data-setup-wizard data-screen="{{ $screen }}">
    <style>
        .sw { font-family: inherit; }
        .sw-top { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: 1.25rem; }
        .sw-brand { display: flex; align-items: center; gap: .6rem; min-width: 0; font-weight: 700; }
        .sw-brand img { width: 2.25rem; height: 2.25rem; border-radius: .6rem; object-fit: contain; background: #fff; }
        .sw-brand span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sw-link { background: none; border: 0; padding: .35rem 0; font: inherit; font-size: .85rem; color: inherit; opacity: .7; cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }
        .sw-link:hover { opacity: 1; }
        .sw-progress { list-style: none; margin: 0 0 1.5rem; padding: 0; display: grid; grid-template-columns: repeat(5, 1fr); gap: .35rem; }
        .sw-progress li { display: grid; gap: .35rem; font-size: .72rem; font-weight: 600; opacity: .55; }
        .sw-progress li::before { content: ''; height: .3rem; border-radius: 999px; background: rgba(148,163,184,.35); transition: background .3s; }
        .sw-progress li.is-done, .sw-progress li.is-current { opacity: 1; }
        .sw-progress li.is-done::before { background: var(--sw-brand); }
        .sw-progress li.is-current::before { background: linear-gradient(90deg, var(--sw-brand) 50%, rgba(148,163,184,.35) 50%); }
        .sw-step-label { display: none; }
        @media (min-width: 640px) { .sw-step-label { display: block; } }
        /* El layout simple de Filament ya es la tarjeta: acá solo el contenido. */
        .sw-card { padding: 0; }
        .sw-screen { animation: sw-in .35s ease-out both; }
        @keyframes sw-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .sw-screen { animation: none; } }
        .sw-kicker { font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--sw-brand); margin: 0 0 .35rem; }
        .sw-title { font-size: 1.5rem; line-height: 1.2; font-weight: 800; letter-spacing: -.01em; margin: 0; }
        .sw-lead { margin: .5rem 0 1.25rem; opacity: .75; line-height: 1.5; }
        .sw-actions { display: flex; flex-wrap: wrap-reverse; gap: .6rem; justify-content: space-between; align-items: center; margin-top: 1.5rem; }
        .sw-actions .sw-primary { margin-left: auto; }
        @media (max-width: 480px) { .sw-actions > * { width: 100%; } .sw-actions .sw-primary { margin-left: 0; } }
        .sw-saved { display: flex; gap: .75rem; align-items: flex-start; padding: .9rem 1rem; border-radius: .9rem; background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); }
        .sw-check { flex: none; width: 1.6rem; height: 1.6rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: #16a34a; color: #fff; font-weight: 800; font-size: .9rem; }
        .sw-saved-t { margin: 0; font-weight: 700; }
        .sw-saved-d { margin: .15rem 0 0; font-size: .875rem; opacity: .75; }
        .sw-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .6rem; }
        .sw-welcome { display: grid; gap: .5rem; margin: 0 0 .5rem; padding: 0; list-style: none; counter-reset: sw; }
        .sw-welcome li { counter-increment: sw; display: flex; gap: .65rem; align-items: center; font-weight: 600; }
        .sw-welcome li::before { content: counter(sw); flex: none; width: 1.6rem; height: 1.6rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--sw-brand) 15%, transparent); color: var(--sw-brand); font-size: .8rem; }
        .sw-foot { margin-top: 1rem; text-align: center; font-size: .8rem; opacity: .6; }
    </style>

    <div class="sw-top">
        <div class="sw-brand">
            @if($logo)<img src="{{ $logo }}" alt="">@endif
            <span>{{ $company->name }}</span>
        </div>
        @if($screen !== 'done')
            <button type="button" class="sw-link" wire:click="pause" data-setup-pause>Seguir después</button>
        @endif
    </div>

    @if($step)
        <ol class="sw-progress" aria-label="Progreso de la configuración">
            @foreach($steps as $number => $label)
                <li @class(['is-done' => $number < $step, 'is-current' => $number === $step]) @if($number === $step) aria-current="step" @endif>
                    <span class="sw-step-label">{{ $label }}</span>
                    <span class="fi-sr-only">Paso {{ $number }}: {{ $label }}{{ $number < $step ? ' (hecho)' : '' }}</span>
                </li>
            @endforeach
        </ol>
    @endif

    <div class="sw-card">
        <div class="sw-screen" wire:key="setup-screen-{{ $screen }}">
            @if($step)
                <p class="sw-kicker">Paso {{ $step }} de {{ count($steps) }}{{ in_array($screen, ['company', 'brand'], true) ? ' · '.($screen === 'company' ? '1' : '2').' de 2' : '' }}</p>
            @endif
            <h1 class="sw-title">{{ $title }}</h1>
            <p class="sw-lead">{{ $lead }}</p>

            <form wire:submit="next" id="setup-form">
                @switch($screen)
                    @case('welcome')
                        <ol class="sw-welcome">
                            @foreach($steps as $label)<li>{{ $label }}</li>@endforeach
                        </ol>
                        @break
                    @case('company')
                        {{ $this->companyForm }}
                        @break
                    @case('brand')
                        {{ $this->brandForm }}
                        @break
                    @case('technician')
                        @if($tech)
                            {!! $saved($tech->name, 'Ya está creado. Entra con '.$tech->email.'.') !!}
                        @else
                            {{ $this->technicianForm }}
                        @endif
                        @break
                    @case('client')
                        @if($client)
                            {!! $saved($client->name, 'Ya está creado.') !!}
                        @else
                            {{ $this->clientForm }}
                        @endif
                        @break
                    @case('building')
                        @if($building)
                            {!! $saved(trim($building->name.' '.$building->address), 'Ya está creado, con '.$building->elevator_count.' asc. y '.$building->freight_elevator_count.' mont.') !!}
                        @else
                            {{ $this->buildingForm }}
                        @endif
                        @break
                    @case('work_order')
                        @if($order)
                            {!! $saved('Orden #'.$order->id, 'Ya está creada y asignada a '.($tech?->name ?? 'tu técnico').'.') !!}
                        @else
                            {{ $this->orderForm }}
                        @endif
                        @break
                    @case('done')
                        <ul class="sw-list" data-setup-summary>
                            <li>{!! $saved($company->name, 'Datos y marca de tu empresa') !!}</li>
                            @if($tech)<li>{!! $saved($tech->name, 'Técnico · entra desde el celular con '.$tech->email) !!}</li>@endif
                            @if($client)<li>{!! $saved($client->name, 'Cliente') !!}</li>@endif
                            @if($building)<li>{!! $saved(trim($building->name.' '.$building->address), 'Edificio con '.$building->elevator_count.' asc. y '.$building->freight_elevator_count.' mont.') !!}</li>@endif
                            @if($order)<li>{!! $saved('Orden #'.$order->id, \App\Support\WorkOrderLabels::type((string) $order->type).' · '.($order->unit ?? '').' · asignada a '.($tech?->name ?? 'tu técnico')) !!}</li>@endif
                        </ul>
                        <p class="sw-lead" style="margin:1rem 0 0">{{ $tech?->name ?? 'Tu técnico' }} ya ve la orden en su celular y le llega el aviso si activó las notificaciones.</p>
                        @break
                @endswitch
            </form>

            <div class="sw-actions">
                @if($canGoBack)
                    <x-filament::button color="gray" wire:click="back" wire:loading.attr="disabled" icon="heroicon-m-arrow-left" data-setup-back>Volver</x-filament::button>
                @endif

                @if($screen === 'done')
                    <x-filament::button class="sw-primary" size="lg" wire:click="finish" icon="heroicon-m-check" data-setup-finish>Ir al inicio</x-filament::button>
                @else
                    @php
                        $recordDone = ['technician' => $tech, 'client' => $client, 'building' => $building, 'work_order' => $order][$screen] ?? null;
                        $label = match (true) {
                            $screen === 'welcome' => 'Empezar',
                            $recordDone !== null => 'Continuar',
                            $screen === 'technician' => 'Crear el técnico',
                            $screen === 'client' => 'Crear el cliente',
                            $screen === 'building' => 'Crear el edificio',
                            $screen === 'work_order' => 'Crear la orden',
                            default => 'Guardar y continuar',
                        };
                    @endphp
                    <x-filament::button class="sw-primary" size="lg" type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" icon="heroicon-m-arrow-right" icon-position="after" data-setup-next>
                        <span wire:loading.remove wire:target="next">{{ $label }}</span>
                        <span wire:loading wire:target="next">Guardando…</span>
                    </x-filament::button>
                @endif
            </div>
        </div>
    </div>

    @if($screen === 'welcome')
        <p class="sw-foot">¿Ya conocés Ascento? <button type="button" class="sw-link" wire:click="skip" wire:confirm="¿Omitir la configuración inicial? La podés retomar desde «Ayuda»." data-setup-skip>Omitir y cargar todo desde el menú</button></p>
    @endif
</div>
