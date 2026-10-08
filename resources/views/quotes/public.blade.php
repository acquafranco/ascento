<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Presupuesto - {{ $quote->title }}</title>

    @vite(['resources/css/app.css'])

</head>

<body class="bg-slate-100 text-slate-900">

<div class="min-h-screen flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-2xl bg-white shadow-2xl rounded-2xl overflow-hidden">

        {{-- HEADER --}}
        <div
            class="rounded-2xl p-6 text-white shadow-lg"
            style="
                background-color: {{ $quote->company?->primary_color ?? '#0f172a' }};
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            "
        >
            <div class="flex items-center gap-4">

                @if($quote->company?->logo)
                    <div class="flex items-center justify-center flex-shrink-0">
                        <img
                            src="{{ asset('storage/' . $quote->company->logo) }}"
                            alt="{{ $quote->company->name }}"
                            class="w-20 h-20 object-contain"
                        >
                    </div>
                @endif

                <div class="flex-1">
                    <h1 class="text-2xl font-black leading-tight">
                        Presupuesto
                    </h1>

                    <p class="text-white/80 text-sm mt-1">
                        {{ $quote->company?->name ?? 'Detalle del trabajo solicitado' }}
                    </p>
                </div>

                <span class="text-4xl leading-none">
                    🛗
                </span>

            </div>
        </div>

        {{-- CONTENIDO --}}
        <div class="p-6 space-y-6">

            {{-- TITULO --}}
            <div>
                <h2 class="text-xl font-semibold text-slate-900">
                    {{ $quote->title }}
                </h2>
            </div>

            {{-- DATOS DEL SERVICIO --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500 uppercase tracking-wider">Cliente</p>
                    <p class="font-bold text-slate-900 mt-1">
                        {{ $quote->client?->name ?? 'Sin cliente' }}
                    </p>
                </div>

                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500 uppercase tracking-wider">Edificio</p>
                    <p class="font-bold text-slate-900 mt-1">
                        {{ $quote->building?->name ?? 'Sin edificio' }}
                    </p>
                    @if($quote->building?->address)
                        <p class="text-xs text-slate-500 mt-1">
                            {{ $quote->building->address }}
                        </p>
                    @endif
                </div>

                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500 uppercase tracking-wider">Ascensor a reparar</p>
                    <p class="font-bold text-slate-900 mt-1">
                        {{ $quote->unit ?? 'Sin ascensor seleccionado' }}
                    </p>
                </div>

            </div>

            {{-- DESCRIPCIÓN --}}
            @if($quote->description)
                <div class="text-slate-600 leading-relaxed">
                    {{ $quote->description }}
                </div>
            @endif

            {{-- ÍTEMS --}}
            @if($quote->items->isNotEmpty())
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                            <tr>
                                <th class="text-left p-3">Concepto</th>
                                <th class="text-right p-3">Cant.</th>
                                <th class="text-right p-3">Precio unit.</th>
                                <th class="text-right p-3">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($quote->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-medium text-slate-800">{{ $item->concept }}</div>
                                        @if($item->description)<div class="text-xs text-slate-500">{{ $item->description }}</div>@endif
                                    </td>
                                    <td class="p-3 text-right whitespace-nowrap">{{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                                    <td class="p-3 text-right whitespace-nowrap">${{ number_format($item->unit_price, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right whitespace-nowrap font-semibold">${{ number_format($item->subtotal, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($quote->conditions)
                <div>
                    <p class="text-xs text-slate-500 mb-1">Condiciones</p>
                    <div class="text-slate-600 text-sm leading-relaxed whitespace-pre-line">{{ $quote->conditions }}</div>
                </div>
            @endif

            {{-- GRID INFO --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                {{-- MONTO --}}
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Total</p>
                    <p class="text-2xl font-bold text-green-600">
                        ${{ number_format($quote->amount, 2, ',', '.') }}
                    </p>
                </div>

                {{-- ESTADO --}}
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Estado</p>

                    @php
                        $statusColor = ['approved' => 'text-green-600', 'rejected' => 'text-red-600', 'sent' => 'text-blue-600', 'expired' => 'text-orange-600', 'void' => 'text-slate-500'][$quote->displayStatus()] ?? 'text-slate-600';
                    @endphp
                    <span class="{{ $statusColor }} font-semibold">{{ $quote->displayStatusLabel() }}</span>
                    @if($quote->valid_until)
                        <p class="text-xs text-slate-500 mt-1">Válido hasta el {{ $quote->valid_until->format('d/m/Y') }}</p>
                    @endif

                </div>

            </div>

            {{-- PRIORIDAD --}}
            <div class="bg-slate-50 rounded-xl p-4">
                <p class="text-xs text-slate-500">Prioridad</p>

                @switch($quote->priority)
                    @case('urgent')
                        <span class="text-red-600 font-bold">🔴 Urgente</span>
                        @break

                    @case('high')
                        <span class="text-orange-600 font-bold">🟠 Alta</span>
                        @break

                    @case('normal')
                        <span class="text-blue-600 font-bold">🔵 Normal</span>
                        @break

                    @case('low')
                        <span class="text-gray-600 font-bold">🟢 Baja</span>
                        @break

                    @default
                        <span class="text-slate-600 font-bold">
                            {{ $quote->priority }}
                        </span>
                @endswitch

            </div>

            {{-- BOTÓN WHATSAPP --}}
            @php
                $telefono = preg_replace('/\D/', '', $quote->client?->phone ?? '');
                if (str_starts_with($telefono, '0')) {
                    $telefono = substr($telefono, 1);
                }
                $telefono = '549' . $telefono;

                $mensaje =
                    "Hola 👋\n\n".
                    "Te enviamos el presupuesto solicitado.\n\n".
                    "📋 Trabajo: {$quote->title}\n".
                    "💰 Total: $" . number_format($quote->amount, 0, ',', '.') . "\n\n".
                    "Podés verlo completo en el siguiente link:\n".
                    route('quotes.public', [
                        'company' => $quote->company->slug,
                        'token' => $quote->public_token,
                    ]);
            @endphp

            <a
                href="https://wa.me/{{ $telefono }}?text={{ urlencode($mensaje) }}"
                target="_blank"
                class="block text-center bg-green-500 hover:bg-green-600 text-white font-semibold py-3 rounded-xl transition"
            >
                Enviar por WhatsApp
            </a>

        </div>

        {{-- FOOTER --}}
        <div class="bg-slate-50 text-center text-xs text-slate-500 p-4">
            Presupuesto generado automáticamente
        </div>

    </div>

</div>

</body>
</html>
