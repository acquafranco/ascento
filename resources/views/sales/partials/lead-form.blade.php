{{--
    Pedido de implementación o de propuesta personalizada. Pide solo lo
    necesario para responder; el presupuesto lo recalcula el servidor. La clave
    «k» (una por resultado mostrado) evita que un reenvío cree otro pedido.
--}}
<details class="mt-5 rounded-2xl border border-ink/10 bg-white" data-lead-box @if($errors?->any() && old('tipo') === $type) open @endif>
    <summary class="cursor-pointer list-none rounded-2xl px-5 py-3.5 text-center text-sm font-semibold {{ $type === 'custom' ? 'bg-graphite text-white' : 'bg-amber-500 text-graphite' }}">{{ $cta }}</summary>
    <form method="POST" action="{{ route('sales.lead') }}" class="space-y-3 px-5 pb-5 pt-4" data-lead-form>
        @csrf
        <input type="hidden" name="tipo" value="{{ $type }}">
        <input type="hidden" name="k" value="{{ $leadKey }}">
        <input type="hidden" name="plan" value="{{ $advice['recommended'] }}">
        @foreach(['edificios', 'ascensores', 'clientes', 'tecnicos', 'datos'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $params[$k] }}">@endforeach
        @foreach($params['opcionales'] as $o)<input type="hidden" name="opcionales[]" value="{{ $o }}">@endforeach
        <div aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden"><label>No completar <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>

        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm font-semibold text-ink/80">Tu nombre
                <input name="nombre" required maxlength="120" autocomplete="name" class="mt-1 w-full rounded-xl border border-ink/15 px-3 py-2.5 text-base font-normal">
            </label>
            <label class="block text-sm font-semibold text-ink/80">Correo
                <input name="email" type="email" required maxlength="255" autocomplete="email" class="mt-1 w-full rounded-xl border border-ink/15 px-3 py-2.5 text-base font-normal">
            </label>
            <label class="block text-sm font-semibold text-ink/80">Teléfono <span class="font-normal text-ink/50">(opcional)</span>
                <input name="telefono" type="tel" maxlength="40" autocomplete="tel" class="mt-1 w-full rounded-xl border border-ink/15 px-3 py-2.5 text-base font-normal">
            </label>
            <label class="block text-sm font-semibold text-ink/80">Empresa <span class="font-normal text-ink/50">(opcional)</span>
                <input name="empresa" maxlength="160" autocomplete="organization" class="mt-1 w-full rounded-xl border border-ink/15 px-3 py-2.5 text-base font-normal">
            </label>
        </div>
        <label class="block text-sm font-semibold text-ink/80">¿Algo que debamos saber? <span class="font-normal text-ink/50">(opcional)</span>
            <textarea name="mensaje" maxlength="1000" rows="3" class="mt-1 w-full rounded-xl border border-ink/15 px-3 py-2.5 text-base font-normal"></textarea>
        </label>
        <p class="text-xs text-ink/50">Usamos estos datos solo para responderte sobre este pedido. No es una compra: el presupuesto se confirma antes de cualquier pago.</p>
        <p class="text-sm font-medium text-red-700" role="alert" data-lead-error hidden></p>
        <button type="submit" class="inline-flex w-full items-center justify-center rounded-full bg-graphite px-5 py-3 text-sm font-semibold text-white transition hover:bg-ink">Enviar pedido</button>
    </form>
</details>
