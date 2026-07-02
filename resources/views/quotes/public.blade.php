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
        <div class="bg-gradient-to-r from-slate-900 to-slate-700 text-white p-6">
            <h1 class="text-2xl font-bold">Presupuesto</h1>
            <p class="text-slate-300 text-sm mt-1">
                Detalle del trabajo solicitado
            </p>
        </div>

        {{-- CONTENIDO --}}
        <div class="p-6 space-y-6">

            {{-- TITULO --}}
            <div>
                <h2 class="text-xl font-semibold text-slate-900">
                    {{ $quote->title }}
                </h2>
            </div>

            {{-- DESCRIPCIÓN --}}
            @if($quote->description)
                <div class="text-slate-600 leading-relaxed">
                    {{ $quote->description }}
                </div>
            @endif

            {{-- GRID INFO --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                {{-- MONTO --}}
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Total</p>
                    <p class="text-2xl font-bold text-green-600">
                        ${{ number_format($quote->amount, 0, ',', '.') }}
                    </p>
                </div>

                {{-- ESTADO --}}
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Estado</p>

                    @switch($quote->status)
                        @case('pending')
                            <span class="text-yellow-600 font-semibold">Pendiente</span>
                            @break

                        @case('sent')
                            <span class="text-blue-600 font-semibold">Enviado</span>
                            @break

                        @case('approved')
                            <span class="text-green-600 font-semibold">Aprobado</span>
                            @break

                        @case('rejected')
                            <span class="text-red-600 font-semibold">Rechazado</span>
                            @break

                        @default
                            <span class="text-slate-600 font-semibold">
                                {{ $quote->status }}
                            </span>
                    @endswitch

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
                    route('quotes.public', ['token' => $quote->public_token]);
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
