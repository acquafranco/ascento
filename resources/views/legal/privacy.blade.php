<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Política de Privacidad — Ascento</title>
    <meta name="description" content="Política de Privacidad de Ascento. Información sobre el tratamiento, protección y conservación de datos personales.">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        html {
            scrollbar-gutter: stable;
        }

        body {
            -webkit-font-smoothing: antialiased;
        }

        ::selection {
            background: #FFE8D6;
            color: #14171C;
        }

        :focus-visible {
            outline: 2px solid #2E4FBE;
            outline-offset: 2px;
            border-radius: 4px;
        }

        .legal-content h2 {
            scroll-margin-top: 100px;
        }
    </style>
</head>

<body class="bg-[#F7F7F4] text-[#14171C] font-body antialiased">

<header
    x-data="{ open: false, scrolled: false }"
    @scroll.window="scrolled = window.scrollY > 12"
    class="fixed inset-x-0 top-0 z-50 transition-all duration-300"
    :class="scrolled
        ? 'bg-[#F7F7F4]/90 backdrop-blur-xl border-b border-[#14171C]/10'
        : 'bg-transparent'"
>
    <nav class="mx-auto max-w-7xl px-5 sm:px-8 h-16 flex items-center justify-between">

        <a href="/" class="flex items-center gap-2.5 shrink-0">
            <img
                src="{{ asset('images/logo.png') }}"
                alt="Ascento"
                class="h-8 w-8 rounded-md object-contain"
            >

            <span class="font-display font-semibold text-[17px] tracking-tight">
                Ascento
            </span>
        </a>

        <div class="hidden lg:flex items-center gap-8 text-sm font-medium text-[#14171C]/65">
            <a href="/" class="hover:text-[#14171C] transition-colors">
                Inicio
            </a>

            <a href="{{ route('legal.terms') }}" class="hover:text-[#14171C] transition-colors">
                Términos
            </a>

            <a href="{{ route('legal.privacy') }}" class="text-[#14171C]">
                Privacidad
            </a>

            <a href="{{ route('legal.data-deletion') }}" class="hover:text-[#14171C] transition-colors">
                Eliminación de datos
            </a>
        </div>

        <div class="hidden lg:flex">
            <a
                href="{{ Route::has('login') ? route('login') : '/login' }}"
                class="inline-flex items-center gap-2 rounded-full bg-[#12151C] text-white text-sm font-semibold px-4 py-2.5 shadow-sm hover:-translate-y-0.5 transition-all"
            >
                Entrar

                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                    <path
                        d="M3 8H13M13 8L9 4M13 8L9 12"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </a>
        </div>

        <button
            @click="open = !open"
            class="lg:hidden p-2 -mr-2"
            aria-label="Abrir menú"
        >
            <svg
                x-show="!open"
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
            >
                <path
                    d="M4 7H20M4 12H20M4 17H20"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                />
            </svg>

            <svg
                x-show="open"
                x-cloak
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
            >
                <path
                    d="M6 6L18 18M6 18L18 6"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                />
            </svg>
        </button>
    </nav>

    <div
        x-show="open"
        x-cloak
        x-transition
        class="lg:hidden bg-[#F7F7F4] border-b border-[#14171C]/10 px-5 pb-5 pt-2"
    >
        <div class="flex flex-col text-[15px] font-medium">

            <a href="/" class="py-3 text-[#14171C]/70">
                Inicio
            </a>

            <a href="{{ route('legal.terms') }}" class="py-3 text-[#14171C]/70">
                Términos y Condiciones
            </a>

            <a href="{{ route('legal.privacy') }}" class="py-3 text-[#14171C]">
                Política de Privacidad
            </a>

            <a href="{{ route('legal.data-deletion') }}" class="py-3 text-[#14171C]/70">
                Eliminación de Datos
            </a>

            <a
                href="{{ Route::has('login') ? route('login') : '/login' }}"
                class="mt-2 text-center rounded-full bg-[#12151C] text-white py-3 font-semibold"
            >
                Entrar
            </a>
        </div>
    </div>
</header>


<main class="pt-16">

    <section class="border-b border-[#14171C]/[0.06]">
        <div class="max-w-4xl mx-auto px-6 py-20 sm:py-24">

            <div class="inline-flex items-center gap-2 rounded-full bg-[#FFE8D6] text-[#C24800] px-3 py-1.5 text-xs font-semibold uppercase tracking-wider">
                Privacidad
            </div>

            <h1 class="mt-5 font-display text-4xl sm:text-5xl font-semibold tracking-tight">
                Política de Privacidad
            </h1>

            <p class="mt-5 max-w-2xl text-base sm:text-lg leading-relaxed text-[#14171C]/60">
                Esta política explica qué datos personales puede tratar Ascento,
                para qué se utilizan, cómo se protegen y cuáles son los derechos
                de las personas titulares de esos datos.
            </p>

            <p class="mt-6 text-sm text-[#14171C]/45">
                Última actualización:
                {{ now()->translatedFormat('d \d\e F \d\e Y') }}
            </p>

        </div>
    </section>


    <section class="max-w-4xl mx-auto px-6 py-14 sm:py-20">

        <div class="legal-content space-y-12">

            <section id="responsable">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    01
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Responsable del tratamiento
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    La plataforma Ascento es operada por
                    <strong>[Razón social / Nombre de la empresa]</strong>,
                    CUIT <strong>[00-00000000-0]</strong>, con domicilio en
                    <strong>[Dirección]</strong>, República Argentina.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    A los efectos de esta Política, dicha entidad será denominada
                    "Ascento", "nosotros" o "el responsable".
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    El tratamiento de datos personales se realiza de conformidad
                    con la normativa argentina aplicable, incluyendo la
                    Ley N.º 25.326 de Protección de Datos Personales y su normativa
                    reglamentaria.
                </p>
            </section>


            <section id="datos">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    02
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Datos que podemos recopilar
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Dependiendo de las funcionalidades utilizadas y de la relación
                    existente con la empresa que contrata Ascento, podemos tratar
                    las siguientes categorías de información:
                </p>

                <div class="mt-6 grid sm:grid-cols-2 gap-4">

                    <div class="rounded-2xl border border-[#14171C]/10 bg-white/50 p-5">
                        <h3 class="font-semibold">Datos de cuenta</h3>
                        <p class="mt-2 text-sm leading-6 text-[#14171C]/60">
                            Nombre, correo electrónico, teléfono, rol y datos
                            necesarios para gestionar el acceso.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-[#14171C]/10 bg-white/50 p-5">
                        <h3 class="font-semibold">Datos de empresa</h3>
                        <p class="mt-2 text-sm leading-6 text-[#14171C]/60">
                            Información de la empresa, clientes, edificios,
                            ascensores y demás información operativa ingresada.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-[#14171C]/10 bg-white/50 p-5">
                        <h3 class="font-semibold">Datos operativos</h3>
                        <p class="mt-2 text-sm leading-6 text-[#14171C]/60">
                            Órdenes de trabajo, visitas, remitos, reportes,
                            fotografías, firmas y registros asociados.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-[#14171C]/10 bg-white/50 p-5">
                        <h3 class="font-semibold">Datos técnicos</h3>
                        <p class="mt-2 text-sm leading-6 text-[#14171C]/60">
                            Dirección IP, dispositivo, navegador, registros
                            técnicos y eventos necesarios para seguridad y soporte.
                        </p>
                    </div>

                </div>
            </section>


            <section id="finalidad">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    03
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Finalidad del tratamiento
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Los datos son utilizados, según corresponda, para prestar,
                    mantener y mejorar el Servicio, gestionar cuentas y usuarios,
                    administrar órdenes de trabajo, edificios, visitas, remitos
                    y reportes, facilitar las comunicaciones solicitadas por la
                    empresa cliente, brindar soporte, detectar usos indebidos,
                    mantener la seguridad de la plataforma y cumplir obligaciones
                    legales aplicables.
                </p>
            </section>


            <section id="base">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    04
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Base y legitimidad del tratamiento
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    El tratamiento podrá encontrarse fundado, según el caso, en
                    la existencia de una relación contractual, en la necesidad
                    de prestar las funcionalidades solicitadas, en el cumplimiento
                    de obligaciones legales o en el consentimiento del titular
                    cuando este resulte necesario.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Cuando una empresa utiliza Ascento para gestionar información
                    de sus propios clientes, empleados, técnicos, encargados u
                    otros terceros, dicha empresa es responsable de garantizar que
                    cuenta con una base legítima para incorporar y utilizar esos
                    datos mediante el Servicio.
                </p>
            </section>


            <section id="terceros">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    05
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Proveedores y terceros
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Para operar Ascento podemos utilizar proveedores tecnológicos
                    que presten servicios de infraestructura, alojamiento,
                    almacenamiento, correo electrónico, procesamiento de pagos,
                    monitoreo, seguridad u otras funciones necesarias para el
                    funcionamiento de la plataforma.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Cuando el cliente habilite integraciones de terceros, como
                    WhatsApp Business, determinados datos podrán ser procesados
                    por dichos proveedores conforme a sus propias condiciones y
                    políticas de privacidad.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Ascento no vende datos personales a terceros.
                </p>
            </section>


            <section id="whatsapp">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    06
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Integraciones con WhatsApp
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Si una empresa habilita funcionalidades de comunicación
                    mediante WhatsApp Business, los números de teléfono y demás
                    datos necesarios para la comunicación podrán ser tratados
                    mediante los servicios correspondientes de Meta y WhatsApp.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    La utilización de dichas funcionalidades también queda sujeta
                    a los términos y políticas aplicables de los respectivos
                    proveedores.
                </p>
            </section>


            <section id="conservacion">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    07
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Conservación de la información
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Conservamos la información durante el tiempo necesario para
                    cumplir las finalidades para las que fue recopilada, mantener
                    la relación contractual, resolver eventuales reclamos,
                    garantizar la seguridad del Servicio y cumplir obligaciones
                    legales, fiscales, contables o regulatorias.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Una vez que los datos dejan de ser necesarios, podrán ser
                    eliminados, anonimizados o conservados cuando exista una
                    obligación legal o una causa legítima que justifique su
                    conservación.
                </p>
            </section>


            <section id="derechos">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    08
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Derechos de los titulares
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    De acuerdo con la normativa aplicable, los titulares de datos
                    personales pueden ejercer, según corresponda, sus derechos
                    de información, acceso, rectificación, actualización y
                    supresión.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Las solicitudes deberán permitir verificar razonablemente la
                    identidad de quien las realiza y podrán enviarse a:
                </p>

                <a
                    href="mailto:[email protected]"
                    class="inline-flex mt-5 text-[#FF6A1A] font-semibold hover:underline"
                >
                    [email protected]
                </a>

                <p class="mt-4 text-sm text-[#14171C]/50 leading-6">
                    Los derechos se ejercerán de conformidad con los plazos,
                    requisitos y excepciones establecidos por la normativa vigente.
                </p>
            </section>


            <section id="seguridad">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    09
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Seguridad
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Implementamos medidas técnicas y organizativas razonables
                    orientadas a proteger la información contra accesos no
                    autorizados, pérdida, alteración, divulgación o destrucción.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Entre otras medidas, Ascento utiliza mecanismos de autenticación,
                    control de acceso por roles, conexiones seguras y protección
                    de credenciales. No obstante, ningún sistema informático puede
                    garantizar seguridad absoluta.
                </p>
            </section>


            <section id="cambios">
                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    10
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Cambios en esta política
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Podemos modificar esta Política de Privacidad cuando resulte
                    necesario para reflejar cambios en el Servicio, en nuestras
                    prácticas o en la normativa aplicable.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Cuando corresponda, comunicaremos los cambios relevantes
                    mediante la plataforma, correo electrónico u otro medio
                    razonable.
                </p>
            </section>


            <section id="contacto" class="rounded-3xl bg-[#12151C] text-white p-7 sm:p-9">

                <span class="font-mono text-xs tracking-widest text-[#FF8A3D] uppercase">
                    Contacto
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    ¿Tenés una consulta?
                </h2>

                <p class="mt-3 text-white/60 leading-7">
                    Para consultas relacionadas con privacidad y tratamiento de
                    datos personales, podés comunicarte con nosotros.
                </p>

                <a
                    href="mailto:[email protected]"
                    class="inline-flex mt-5 text-[#FF8A3D] font-semibold hover:text-white transition-colors"
                >
                    [email protected]
                </a>

            </section>

        </div>

    </section>

</main>


<footer class="border-t border-black/[0.06] py-8">

    <div class="max-w-4xl mx-auto px-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

        <p class="text-sm text-[#14171C]/40">
            © {{ date('Y') }} Ascento. Todos los derechos reservados.
        </p>

        <div class="flex flex-wrap gap-5 text-sm text-[#14171C]/50">

            <a href="{{ route('legal.terms') }}" class="hover:text-[#14171C]">
                Términos y Condiciones
            </a>

            <a href="{{ route('legal.privacy') }}" class="text-[#14171C]">
                Privacidad
            </a>

            <a href="{{ route('legal.data-deletion') }}" class="hover:text-[#14171C]">
                Eliminación de Datos
            </a>

        </div>

    </div>

</footer>

</body>
</html>
