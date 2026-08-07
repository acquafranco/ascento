<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminación de Datos — Ascento</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F7F7F4; color: #14171C; }
        h1, h2 { font-family: 'Space Grotesk', sans-serif; }
    </style>
</head>
<body class="antialiased">

    <header class="border-b border-black/[0.06]" style="background-color:#12151C;">
        <div class="max-w-3xl mx-auto px-6 py-5 flex items-center justify-between">
            <a href="{{ url('/') }}" class="text-lg font-bold" style="color:#F7F7F4; font-family:'Space Grotesk',sans-serif;">
                Ascento<span style="color:#FF6A1A;">.</span>
            </a>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-6 py-14">

        <h1 class="text-3xl font-bold mb-2">Instrucciones para la Eliminación de Datos</h1>
        <p class="text-sm text-[#14171C]/50 mb-10">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>

        <div class="prose prose-neutral max-w-none space-y-8">

            <section>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Si querés que eliminemos tus datos personales de Ascento, incluidos los datos asociados a la
                    integración con WhatsApp Business, seguí alguno de los siguientes métodos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Opción 1: Solicitud por correo electrónico</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Enviá un correo a
                    <a href="mailto:[email protected]" style="color:#FF6A1A;">[email protected]</a>
                    desde la dirección de correo asociada a tu cuenta, con el asunto "Eliminación de datos", e
                    indicando:
                </p>
                <ul class="list-disc pl-5 text-[#14171C]/80 leading-relaxed space-y-1 mt-2">
                    <li>Nombre completo</li>
                    <li>Correo electrónico registrado</li>
                    <li>Empresa (tenant) a la que pertenece tu cuenta</li>
                    <li>Número de WhatsApp, si solicitás eliminar datos de esa integración</li>
                </ul>
                <p class="text-[#14171C]/80 leading-relaxed mt-2">
                    Procesaremos la solicitud dentro de un plazo máximo de 15 días hábiles y te confirmaremos por
                    correo una vez completada la eliminación.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Opción 2: Desde tu cuenta</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Si tenés acceso a tu cuenta, podés solicitar la baja directamente desde
                    <strong>Perfil → Eliminar mi cuenta</strong>, o pedirle al administrador de tu empresa que
                    gestione la baja de tu usuario.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Qué se elimina</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Al procesar tu solicitud, eliminamos o anonimizamos: datos de perfil (nombre, correo, teléfono),
                    historial de mensajes de WhatsApp asociados a tu contacto, y cualquier dato personal identificable
                    que no debamos conservar por obligación legal o contable (por ejemplo, registros de facturación,
                    que se conservan por el plazo exigido por la normativa impositiva argentina).
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Contacto</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Ante cualquier duda sobre este proceso, escribinos a
                    <a href="mailto:[email protected]" style="color:#FF6A1A;">[email protected]</a>.
                </p>
            </section>

        </div>
    </main>

    <footer class="border-t border-black/[0.06] py-8">
        <div class="max-w-3xl mx-auto px-6 flex flex-wrap gap-4 text-sm text-[#14171C]/50">
            <a href="{{ route('legal.terms') }}" class="hover:text-[#14171C]">Términos y Condiciones</a>
            <a href="{{ route('legal.privacy') }}" class="hover:text-[#14171C]">Política de Privacidad</a>
            <a href="{{ route('legal.data-deletion') }}" class="hover:text-[#14171C]">Eliminación de Datos</a>
        </div>
    </footer>

</body>
</html>
