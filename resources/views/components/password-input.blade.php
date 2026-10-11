{{--
    Campo de contraseña con botón "Mostrar / Ocultar".
    - Es un <button> real: se alcanza con Tab y se activa con Enter o Espacio;
      aria-pressed y aria-label dicen el estado; el foco vuelve al campo.
    - No cambia el name ni el autocomplete: los gestores de contraseñas y el
      pegado funcionan igual. Al enviar el formulario vuelve a ocultarse.
    - JavaScript mínimo y sin Alpine (las páginas públicas no lo cargan).
--}}
@props(['id', 'name' => 'password', 'autocomplete' => 'new-password'])

<div class="relative" data-password-field>
    <x-text-input :id="$id" :name="$name" type="password" :autocomplete="$autocomplete" {{ $attributes->merge(['class' => 'pr-12']) }} />
    <button type="button" data-password-toggle aria-controls="{{ $id }}" aria-pressed="false" aria-label="Mostrar contraseña" title="Mostrar contraseña"
            class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-ink/45 hover:text-ink focus:outline-none focus-visible:ring-4 focus-visible:ring-rail-100">
        <svg data-icon="show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3 9.96 7.18a1 1 0 0 1 0 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3-9.96-7.18Z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg data-icon="hide" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.22A10.48 10.48 0 0 0 2.04 12c1.38 4.18 5.32 7.5 9.96 7.5 1.9 0 3.68-.5 5.2-1.38M6.23 6.23A10.45 10.45 0 0 1 12 4.5c4.64 0 8.58 3.01 9.96 7.18a10.5 10.5 0 0 1-4.29 5.37M6.23 6.23 3 3m3.23 3.23 3.65 3.65m7.89 7.89L21 21m-3.23-3.23-3.65-3.65m0 0a3 3 0 1 0-4.24-4.24m4.24 4.24L9.88 9.88"/></svg>
    </button>
</div>

@once
    <script>
        (function () {
            function set(button, show) {
                var input = document.getElementById(button.getAttribute('aria-controls'));
                if (!input) { return; }
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
                button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                button.title = button.getAttribute('aria-label');
                button.querySelector('[data-icon=show]').classList.toggle('hidden', show);
                button.querySelector('[data-icon=hide]').classList.toggle('hidden', !show);
            }
            document.addEventListener('click', function (e) {
                var button = e.target.closest('[data-password-toggle]');
                if (!button) { return; }
                set(button, button.getAttribute('aria-pressed') !== 'true');
                var input = document.getElementById(button.getAttribute('aria-controls'));
                input && input.focus();
            });
            document.addEventListener('submit', function (e) {
                e.target.querySelectorAll('[data-password-toggle][aria-pressed=true]').forEach(function (b) { set(b, false); });
            }, true);
        })();
    </script>
@endonce
