<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidad — Ascento</title>
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

        <h1 class="text-3xl font-bold mb-2">Política de Privacidad</h1>
        <p class="text-sm text-[#14171C]/50 mb-10">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>

        <div class="prose prose-neutral max-w-none space-y-8">

            <section>
                <h2 class="text-xl font-semibold mb-2">1. Responsable del tratamiento</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    [Razón social / Nombre de la empresa], CUIT [00-00000000-0], con domicilio en [Dirección],
                    Argentina (en adelante, "Ascento"), es responsable del tratamiento de los datos personales
                    recolectados a través de la plataforma, conforme a la Ley N.º 25.326 de Protección de Datos
                    Personales de la República Argentina.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">2. Datos que recolectamos</h2>
                <ul class="list-disc pl-5 text-[#14171C]/80 leading-relaxed space-y-1">
                    <li>Datos de cuenta: nombre, correo electrónico, contraseña (cifrada), rol dentro de la empresa.</li>
                    <li>Datos de la empresa cliente: razón social, edificios, ascensores administrados.</li>
                    <li>Datos operativos: órdenes de trabajo, remitos, reportes de mantenimiento, firmas digitales,
                        fotografías cargadas por técnicos.</li>
                    <li>Datos de contacto de terceros: encargados de edificio, propietarios, cuando son cargados
                        por el cliente para la gestión del servicio.</li>
                    <li>Datos de comunicación: si se habilita WhatsApp Business, número de teléfono y contenido de
                        los mensajes intercambiados con clientes finales.</li>
                    <li>Datos técnicos: dirección IP, tipo de dispositivo, registros de uso (logs) con fines de
                        seguridad y soporte.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">3. Finalidad del tratamiento</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Utilizamos los datos para: operar y mantener la plataforma, gestionar órdenes de trabajo y
                    reportes de mantenimiento, permitir la comunicación entre la empresa de mantenimiento y sus
                    clientes (incluyendo, si corresponde, vía WhatsApp Business), brindar soporte técnico, prevenir
                    fraude y garantizar la seguridad del servicio, y cumplir obligaciones legales.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">4. Base legal</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    El tratamiento se basa en la ejecución del contrato de servicio con la empresa cliente, en el
                    consentimiento otorgado al crear una cuenta, y en el cumplimiento de obligaciones legales
                    aplicables en Argentina.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">5. Compartición de datos con terceros</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    No vendemos datos personales. Podemos compartir datos con proveedores de infraestructura
                    (hosting, almacenamiento) y, cuando el cliente habilita la funcionalidad, con Meta Platforms,
                    Inc. a través de la API de WhatsApp Business, únicamente para el envío y recepción de mensajes
                    autorizados por el usuario final. Estos proveedores están obligados contractualmente a proteger
                    los datos conforme a esta política.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">6. Conservación de datos</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Conservamos los datos mientras la cuenta de la empresa cliente permanezca activa y durante el
                    plazo adicional necesario para cumplir obligaciones legales, contables o fiscales. Podés
                    solicitar la eliminación de tus datos siguiendo el procedimiento descripto en nuestra
                    <a href="{{ route('legal.data-deletion') }}" style="color:#FF6A1A;">página de Eliminación de Datos</a>.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">7. Derechos del titular de los datos</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Conforme a la Ley N.º 25.326, tenés derecho a acceder, rectificar, actualizar y solicitar la
                    supresión de tus datos personales. Para ejercer estos derechos, escribinos a
                    <a href="mailto:[email protected]" style="color:#FF6A1A;">[email protected]</a>.
                    La Agencia de Acceso a la Información Pública, en su carácter de Órgano de Control de la Ley
                    N.º 25.326, tiene la atribución de atender reclamos y denuncias que presenten quienes resulten
                    afectados en sus derechos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">8. Seguridad</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Implementamos medidas técnicas y organizativas razonables (cifrado de contraseñas, control de
                    acceso por roles, conexiones HTTPS) para proteger los datos contra acceso no autorizado,
                    pérdida o alteración.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">9. Cambios en esta política</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Podemos actualizar esta Política de Privacidad. Los cambios significativos serán notificados
                    dentro de la plataforma o por correo electrónico.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">10. Contacto</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Para consultas sobre privacidad, escribinos a
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
