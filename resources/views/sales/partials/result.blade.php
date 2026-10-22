{{--
    Resultado de la calculadora (SalesCalculatorController): planes compatibles
    con los límites reales, las dos formas de empezar y, si hace falta, la
    propuesta personalizada. Los importes de implementación salen de las
    tarifas publicadas; si no hay, «a confirmar».
--}}
@php
    use App\Services\Sales\CalculatorInput;
    use App\Services\Sales\ImplementationQuote;
    $fmt = fn (?float $v) => ImplementationQuote::format($v, $quote['currency']);
    $recommended = collect($advice['plans'])->firstWhere('slug', $advice['recommended']);
    $compatible = collect($advice['plans'])->where('fits', true)->values();
    $params = $input->toArray();
    $needsCustom = $advice['none']; // (si solo la implementación supera el tope, lo pide la Opción B)
@endphp
<div class="space-y-8" data-calc-result data-recommended="{{ $advice['recommended'] }}" tabindex="-1">

    {{-- 1. El plan --}}
    <section aria-labelledby="calc-plans-title">
        <h3 id="calc-plans-title" class="font-display text-2xl font-semibold tracking-tight">
            @if($advice['none'])
                Tu empresa supera los planes estándar
            @elseif($compatible->count() > 1)
                Estos planes te sirven
            @else
                Este es el plan para tu empresa
            @endif
        </h3>
        <p class="mt-1 text-sm text-ink/60">Comparamos tus {{ $input->buildings }} edificios, {{ $input->clients }} clientes y {{ $input->technicians }} técnicos con los límites reales de cada plan. La cantidad de ascensores no limita el plan.</p>

        <div class="mt-5 grid gap-4 lg:grid-cols-3">
            @foreach($advice['plans'] as $plan)
                @php $isRec = $plan['slug'] === $advice['recommended']; @endphp
                <article class="relative flex flex-col rounded-2xl border-2 p-5 {{ $isRec ? 'border-amber-500 bg-graphite text-white' : ($plan['fits'] ? 'border-ink/10 bg-white' : 'border-ink/10 bg-white opacity-60') }}" data-calc-plan="{{ $plan['slug'] }}" data-fits="{{ $plan['fits'] ? '1' : '0' }}">
                    @if($isRec)<span class="absolute -top-3 left-5 rounded-full bg-amber-500 px-3 py-1 text-xs font-bold uppercase tracking-wide text-graphite">Recomendado para vos</span>@endif
                    <h4 class="font-display text-xl font-semibold">{{ $plan['name'] }}</h4>
                    <p class="mt-1 font-display text-2xl font-semibold">{{ $plan['price_label'] }}<span class="text-sm font-medium {{ $isRec ? 'text-white/55' : 'text-ink/50' }}">/mes</span></p>
                    <ul class="mt-4 space-y-1.5 text-sm">
                        @foreach($plan['checks'] as $check)
                            <li class="flex gap-2">
                                <span aria-hidden="true" class="{{ $check['fits'] ? 'text-amber-500' : 'text-red-500' }}">{{ $check['fits'] ? '✓' : '✗' }}</span>
                                <span>{{ $check['label'] }}@unless($check['fits'])<span class="sr-only"> (no alcanza)</span> <span class="{{ $isRec ? 'text-white/60' : 'text-ink/50' }}">· necesitás {{ $check['need'] }}</span>@endunless</span>
                            </li>
                        @endforeach
                    </ul>
                    @if($plan['fits'] && $compatible->count() > 1 && $plan['adds'])
                        <p class="mt-3 text-xs font-semibold uppercase tracking-wide {{ $isRec ? 'text-white/60' : 'text-ink/50' }}">Además suma</p>
                        <ul class="mt-1 space-y-1 text-sm {{ $isRec ? 'text-white/85' : 'text-ink/70' }}">
                            @foreach(array_slice($plan['adds'], 0, 4) as $add)<li>· {{ $add }}</li>@endforeach
                        </ul>
                    @endif
                    <div class="mt-auto pt-5">
                        @if($plan['fits'])
                            <a href="{{ route('register', ['plan' => $plan['slug']]) }}" class="inline-flex w-full items-center justify-center rounded-full px-5 py-3 text-sm font-semibold transition {{ $isRec ? 'bg-amber-500 text-graphite hover:bg-amber-400' : 'border border-ink/15 hover:border-ink/30' }}" data-calc-choose="{{ $plan['slug'] }}">Elegir {{ $plan['name'] }} y probar gratis</a>
                        @else
                            <p class="text-sm {{ $isRec ? 'text-white/60' : 'text-ink/50' }}">No alcanza para tu empresa.</p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-ink/50">La prueba gratis dura {{ \App\Models\SubscriptionPlan::TRIAL_DAYS }} días con todas las funciones, sin tarjeta. El plan que elijas acá queda preseleccionado para cuando contrates.</p>
    </section>

    {{-- 2. Cómo empezar --}}
    @unless($advice['none'])
        <section aria-labelledby="calc-start-title">
            <h3 id="calc-start-title" class="font-display text-2xl font-semibold tracking-tight">¿Cómo querés empezar?</h3>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <article class="flex flex-col rounded-2xl border-2 border-ink/10 bg-white p-6" data-calc-option="self">
                    <p class="text-xs font-bold uppercase tracking-widest text-amber-600">Opción A</p>
                    <h4 class="mt-1 font-display text-xl font-semibold">Empiezo por mi cuenta</h4>
                    <p class="mt-2 text-sm text-ink/70">Te registrás, importás tus clientes desde Excel y cargás tus edificios y ascensores desde el panel. Una guía te acompaña los primeros pasos.</p>
                    <dl class="mt-4 space-y-1 text-sm">
                        <div class="flex justify-between gap-3"><dt>Implementación</dt><dd class="font-semibold">Sin costo</dd></div>
                        <div class="flex justify-between gap-3"><dt>Costo mensual (después de la prueba)</dt><dd class="font-semibold">{{ $recommended['price_label'] }}</dd></div>
                    </dl>
                    <a href="{{ route('register', ['plan' => $advice['recommended']]) }}" class="mt-auto inline-flex items-center justify-center rounded-full bg-graphite px-5 py-3 text-sm font-semibold text-white transition hover:bg-ink" data-calc-self>Comenzar la prueba gratis</a>
                </article>

                <article class="flex flex-col rounded-2xl border-2 border-amber-500 bg-amber-50/60 p-6" data-calc-option="assisted">
                    <p class="text-xs font-bold uppercase tracking-widest text-amber-600">Opción B</p>
                    <h4 class="mt-1 font-display text-xl font-semibold">Quiero que Ascento me ayude a implementarlo</h4>
                    <p class="mt-2 text-sm text-ink/70">Cargamos por vos tus clientes, edificios y ascensores y dejamos todo listo para que empieces a trabajar. El precio depende del volumen y del estado de tus datos.</p>

                    <form method="GET" action="{{ route('sales.calculator') }}" class="mt-4 space-y-3" data-calc-options>
                        @foreach(['edificios', 'ascensores', 'clientes', 'tecnicos'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $params[$k] }}">@endforeach
                        <fieldset>
                            <legend class="text-sm font-semibold text-ink/80">¿En qué estado están tus datos hoy?</legend>
                            <div class="mt-1.5 space-y-1.5">
                                @foreach(CalculatorInput::DATA_STATES as $key => $label)
                                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="datos" value="{{ $key }}" @checked($input->dataState === $key) class="accent-amber-600"> {{ $label }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend class="text-sm font-semibold text-ink/80">Servicios opcionales</legend>
                            <div class="mt-1.5 space-y-1.5">
                                @foreach(CalculatorInput::OPTIONS as $key => $label)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="opcionales[]" value="{{ $key }}" @checked(in_array($key, $input->options, true)) class="accent-amber-600"> {{ $label }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <noscript><button type="submit" class="rounded-full border border-ink/20 px-4 py-2 text-sm font-semibold">Recalcular</button></noscript>
                    </form>

                    {{-- Precios por separado: nunca mezclados con la suscripción. --}}
                    <dl class="mt-5 space-y-1.5 border-t border-ink/10 pt-4 text-sm" data-calc-quote="{{ $quote['status'] }}">
                        @if($quote['status'] === 'pending')
                            <p class="rounded-xl bg-white px-3 py-2 text-ink/70">Las tarifas del servicio todavía no están confirmadas. Pedilo y te enviamos el presupuesto exacto para tus datos.</p>
                        @elseif($quote['status'] === 'custom')
                            <p class="rounded-xl bg-white px-3 py-2 text-ink/70">Por el volumen de tus datos, armamos una propuesta a medida.</p>
                        @else
                            @foreach($quote['lines'] as $line)
                                <div class="flex justify-between gap-3 text-ink/70"><dt>{{ $line['label'] }}</dt><dd>{{ $fmt($line['amount']) }}</dd></div>
                            @endforeach
                            <div class="flex justify-between gap-3 font-semibold"><dt>Implementación inicial (pago único)</dt><dd>{{ $fmt($quote['implementation']) }}</dd></div>
                        @endif
                        @foreach($quote['options'] as $option)
                            <div class="flex justify-between gap-3 text-ink/70"><dt>{{ $option['label'] }}</dt><dd>{{ $fmt($option['amount']) }}</dd></div>
                        @endforeach
                        @if($quote['status'] === 'priced')
                            <div class="flex justify-between gap-3 border-t border-ink/10 pt-2 font-semibold"><dt>Total inicial</dt><dd>{{ $fmt($quote['total']) }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-3 font-semibold"><dt>Costo mensual recurrente ({{ $recommended['name'] }})</dt><dd>{{ $recommended['price_label'] }}</dd></div>
                    </dl>

                    @include('sales.partials.lead-form', ['type' => 'implementation', 'cta' => 'Solicitar la implementación'])
                </article>
            </div>
        </section>
    @endunless

    {{-- 3. Propuesta personalizada (supera los planes o el tope del servicio) --}}
    @if($needsCustom)
        <section class="rounded-2xl border-2 border-graphite bg-white p-6" aria-labelledby="calc-custom-title" data-calc-custom>
            <h3 id="calc-custom-title" class="font-display text-xl font-semibold">Pedí una propuesta personalizada</h3>
            <p class="mt-1 text-sm text-ink/70">
                @if($advice['none'])
                    Ningún plan estándar alcanza para tu empresa. Contanos qué necesitás y te armamos una propuesta.
                @else
                    Por el volumen de tus datos, el servicio de implementación se cotiza a medida.
                @endif
            </p>
            @include('sales.partials.lead-form', ['type' => 'custom', 'cta' => 'Pedir propuesta'])
        </section>
    @endif
</div>
