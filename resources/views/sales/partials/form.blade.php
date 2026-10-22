{{--
    Las cuatro preguntas de la calculadora (sin datos personales). Funciona sin
    JavaScript (GET a /calculadora); con JavaScript, el resultado aparece debajo
    sin recargar y los datos se recuerdan en esta pestaña.
--}}
@php
    $old = $old ?? [];
    $fields = [
        'edificios' => ['¿Cuántos edificios mantenés?', 'Ej: 35'],
        'ascensores' => ['¿Cuántos ascensores y montacargas en total?', 'Ej: 60'],
        'clientes' => ['¿Cuántos clientes tenés?', 'Ej: 20'],
        'tecnicos' => ['¿Cuántos técnicos trabajan con vos?', 'Ej: 4'],
    ];
@endphp
<form method="GET" action="{{ route('sales.calculator') }}" class="grid gap-4 sm:grid-cols-2" data-calc-form novalidate>
    @foreach($fields as $name => [$label, $placeholder])
        <div>
            <label for="calc-{{ $name }}" class="block text-sm font-semibold text-ink/80">{{ $label }}</label>
            <input id="calc-{{ $name }}" name="{{ $name }}" type="number" inputmode="numeric" min="1" max="100000" step="1" required
                   value="{{ $old[$name] ?? '' }}" placeholder="{{ $placeholder }}"
                   class="mt-1.5 w-full rounded-xl border border-ink/15 bg-white px-4 py-3 text-base text-ink shadow-sm outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
            @isset($errors)@if($errors->has($name))<p class="mt-1 text-sm font-medium text-red-700">{{ $errors->first($name) }}</p>@endif @endisset
        </div>
    @endforeach
    <p class="text-xs text-ink/50 sm:col-span-2">Un edificio puede tener varios ascensores y un cliente, varios edificios: por eso preguntamos cada cosa por separado. No te pedimos datos personales para ver el resultado.</p>
    <div class="sm:col-span-2">
        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-amber-500 px-7 py-3.5 font-semibold text-graphite transition hover:bg-amber-400 focus:outline-none focus-visible:ring-4 focus-visible:ring-amber-200 sm:w-auto" data-calc-submit>
            Ver qué plan me conviene
        </button>
    </div>
</form>
