{{--
    Desafío del registro (ver App\Support\Auth\RegistrationChallenge).
    - Visual: tocar el botón del piso que muestra el indicador. Cada botón manda
      un token al azar; el número no está en el HTML como texto.
    - Alternativa accesible (lectores de pantalla, dificultades visuales o
      motrices): una pregunta en palabras, siempre visible y sin recargar.
    - Todo se valida en el servidor; acá solo se elige.
--}}
<fieldset class="rounded-2xl border border-ink/10 bg-ink/[0.02] p-4" aria-describedby="challenge-help">
    <legend class="px-1 text-sm font-semibold text-ink">Comprobación</legend>

    @if ($challenge['passed'])
        <p class="text-sm font-medium text-emerald-700" role="status">✓ Comprobación completada.</p>
    @else
        <p id="challenge-help" class="text-sm text-ink/60">Tocá el botón del piso que muestra el indicador del ascensor.</p>

        <div class="mt-3 flex flex-wrap items-center gap-4">
            <div class="shrink-0">{!! $challenge['display'] !!}</div>
            <div class="grid grid-cols-3 gap-2" role="group" aria-label="Botones de piso">
                @foreach ($challenge['buttons'] as $i => $button)
                    <button type="button" data-challenge-option="{{ $button['token'] }}" aria-pressed="false"
                            aria-label="Opción {{ $i + 1 }}"
                            class="flex h-14 w-14 items-center justify-center rounded-full border-2 border-ink/15 bg-white shadow-sm transition hover:border-amber-500 focus:outline-none focus-visible:ring-4 focus-visible:ring-amber-200 aria-pressed:border-amber-600 aria-pressed:bg-amber-50">
                        {!! $button['svg'] !!}
                    </button>
                @endforeach
            </div>
        </div>
        <input type="hidden" name="challenge" value="" data-challenge-input>

        <div class="mt-4 border-t border-ink/10 pt-3">
            <label for="challenge_text" class="text-sm text-ink/60">¿No podés ver o tocar los botones? Respondé con números: <span class="font-medium text-ink">{{ $challenge['question'] }}</span></label>
            <x-text-input id="challenge_text" name="challenge_text" type="text" inputmode="numeric" autocomplete="off" class="mt-1.5 max-w-[8rem]" />
        </div>
    @endif

    <x-input-error :messages="$errors->get('challenge')" class="mt-2" />
</fieldset>

<script>
    (function () {
        var input = document.querySelector('[data-challenge-input]');
        if (!input) { return; }
        document.querySelectorAll('[data-challenge-option]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll('[data-challenge-option]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
                button.setAttribute('aria-pressed', 'true');
                input.value = button.getAttribute('data-challenge-option');
            });
        });
    })();
</script>
