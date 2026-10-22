{{--
    Calculadora en la Welcome: primero el valor, después la invitación a
    calcular. Sale de los planes reales (PlanAdvisor) y de las tarifas de
    implementación publicadas (ImplementationQuote).
--}}
<section id="calculadora" class="relative overflow-hidden border-y border-ink/10 bg-paper py-20 sm:py-28" data-calc-root data-result-url="{{ route('sales.calculator.result') }}">
    <div class="mx-auto max-w-6xl px-5 sm:px-8">
        <div data-reveal class="mx-auto max-w-3xl text-center">
            <span class="font-mono text-xs uppercase tracking-widest text-amber-600">Tu propuesta en un minuto</span>
            <h2 class="mt-3 font-display text-3xl font-semibold tracking-tight sm:text-[2.6rem] sm:leading-[1.1]">
                Muchos ascensores, clientes y tareas. <span class="text-amber-600">Un solo lugar.</span>
            </h2>
            <p class="mt-5 text-base leading-relaxed text-ink/65 sm:text-lg">
                Ascento conecta el trabajo de la oficina, los técnicos, tus clientes y las administraciones. Descubrí qué plan se adapta a tu empresa y cómo podemos ayudarte a empezar: sin formularios largos y sin dejar tus datos.
            </p>
        </div>

        <div class="mx-auto mt-10 max-w-4xl rounded-3xl border border-ink/10 bg-white p-6 shadow-card sm:p-8">
            @include('sales.partials.form', ['old' => $old ?? []])
        </div>

        <div class="mx-auto mt-10 max-w-6xl" data-calc-output aria-live="polite">
            @isset($advice)
                @include('sales.partials.result')
            @endisset
        </div>
    </div>
</section>
@include('sales.partials.script')
