{{--
    Elección del tipo de cuenta, arriba de cada registro. Son dos registros
    distintos (empresa de mantenimiento / proveedor de repuestos): cada opción
    lleva al suyo, y la actual queda marcada.
--}}
<nav aria-labelledby="account-type-title" class="mt-6" data-account-type="{{ $current }}">
    <p id="account-type-title" class="text-sm font-semibold text-ink">¿Cómo querés utilizar Ascento?</p>
    <div class="mt-2 grid gap-2 sm:grid-cols-2">
        @foreach([
            'company' => ['Soy una empresa de mantenimiento de ascensores', 'Clientes, edificios, técnicos y órdenes de trabajo.', route('register')],
            'supplier' => ['Soy un proveedor de repuestos', 'Publicá tu catálogo y recibí pedidos de cotización.', route('suppliers.register')],
        ] as $key => [$label, $hint, $url])
            <a href="{{ $url }}" @if($current === $key) aria-current="page" @endif
               class="block rounded-2xl border-2 px-4 py-3 transition {{ $current === $key ? 'border-amber-500 bg-amber-50' : 'border-ink/10 hover:border-ink/25' }}">
                <span class="flex items-start gap-2">
                    <span aria-hidden="true" class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 {{ $current === $key ? 'border-amber-500' : 'border-ink/25' }}">
                        @if($current === $key)<span class="h-2 w-2 rounded-full bg-amber-500"></span>@endif
                    </span>
                    <span>
                        <span class="block text-sm font-semibold text-ink">{{ $label }}</span>
                        <span class="mt-0.5 block text-xs text-ink/55">{{ $hint }}</span>
                    </span>
                </span>
            </a>
        @endforeach
    </div>
</nav>
