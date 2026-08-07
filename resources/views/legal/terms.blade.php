<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Términos y Condiciones — Ascento</title>
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

        <h1 class="text-3xl font-bold mb-2">Términos y Condiciones de Uso</h1>
        <p class="text-sm text-[#14171C]/50 mb-10">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>

        <div class="prose prose-neutral max-w-none space-y-8">

            <section>
                <h2 class="text-xl font-semibold mb-2">1. Aceptación de los términos</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Al acceder o utilizar la plataforma Ascento (en adelante, "el Servicio"), operada por
                    [Razón social / Nombre de la empresa], CUIT [00-00000000-0], con domicilio en [Dirección],
                    Argentina, aceptás quedar obligado por estos Términos y Condiciones. Si no estás de acuerdo,
                    no debés utilizar el Servicio.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">2. Descripción del servicio</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Ascento es una plataforma de gestión para empresas de mantenimiento de ascensores, que permite
                    administrar clientes, edificios, ascensores, órdenes de trabajo, remitos y reportes mensuales
                    de mantenimiento, entre otras funcionalidades.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">3. Cuentas de usuario</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Cada empresa (tenant) que utiliza Ascento es responsable de la veracidad de los datos que
                    carga, de la administración de sus propios usuarios (administradores, técnicos, ingenieros) y
                    de mantener la confidencialidad de sus credenciales de acceso. Sos responsable de toda actividad
                    realizada bajo tu cuenta.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">4. Uso de WhatsApp Business</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Si tu empresa habilita la integración con WhatsApp Business dentro de Ascento, aceptás además
                    los términos de servicio de Meta Platforms, Inc. y de WhatsApp, y sos responsable del contenido
                    de los mensajes enviados a través de dicha integración.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">5. Propiedad intelectual</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    El software, diseño, marca y contenidos de Ascento son propiedad de [Razón social] y están
                    protegidos por la legislación de propiedad intelectual vigente en Argentina. No se otorga
                    ningún derecho de uso más allá del necesario para operar el Servicio conforme a estos términos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">6. Limitación de responsabilidad</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    El Servicio se provee "tal cual". [Razón social] no garantiza disponibilidad ininterrumpida y
                    no será responsable por daños indirectos derivados del uso o imposibilidad de uso del Servicio,
                    en la máxima medida permitida por la ley argentina.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">7. Suspensión y cancelación</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Nos reservamos el derecho de suspender o cancelar cuentas que incumplan estos términos, incluyan
                    contenido ilegal, o comprometan la seguridad de la plataforma o de otros usuarios.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">8. Modificaciones</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Podemos actualizar estos Términos en cualquier momento. Los cambios relevantes serán notificados
                    dentro de la plataforma. El uso continuado del Servicio implica la aceptación de los nuevos términos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">9. Ley aplicable y jurisdicción</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Estos Términos se rigen por las leyes de la República Argentina. Cualquier controversia será
                    sometida a los tribunales ordinarios de [Ciudad], Argentina.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">10. Contacto</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Ante consultas sobre estos Términos, escribinos a
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
