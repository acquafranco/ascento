<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Eliminación de Datos — Ascento</title>
    <meta name="description" content="Instrucciones para solicitar la eliminación de datos personales y cuentas de usuario en Ascento.">

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

            <a href="{{ route('legal.privacy') }}" class="hover:text-[#14171C] transition-colors">
                Privacidad
            </a>

            <a href="{{ route('legal.data-deletion') }}" class="text-[#14171C]">
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

            <a href="{{ route('legal.privacy') }}" class="py-3 text-[#14171C]/70">
                Política de Privacidad
            </a>

            <a href="{{ route('legal.data-deletion') }}" class="py-3 text-[#14171C]">
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
                Datos personales
            </div>

            <h1 class="mt-5 font-display text-4xl sm:text-5xl font-semibold tracking-tight">
                Eliminación de Datos
            </h1>

            <p class="mt-5 max-w-2xl text-base sm:text-lg leading-relaxed text-[#14171C]/60">
                Si querés solicitar la eliminación de tus datos personales o
                de tu cuenta de usuario en Ascento, acá te explicamos cómo
                hacerlo y qué información puede ser eliminada o conservada.
            </p>

            <p class="mt-6 text-sm text-[#14171C]/45">
                Última actualización:
                {{ now()->translatedFormat('d \d\e F \d\e Y') }}
            </p>

        </div>

    </section>


    <section class="max-w-4xl mx-auto px-6 py-14 sm:py-20">

        <div class="space-y-12">


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    01
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    ¿Quién puede solicitar una eliminación?
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Toda persona titular de datos personales puede solicitar,
                    cuando corresponda, la actualización, rectificación o
                    supresión de sus datos conforme a la normativa aplicable.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Si tu usuario pertenece a una empresa que utiliza Ascento,
                    la eliminación de determinados datos operativos de la empresa
                    puede requerir la intervención del administrador o responsable
                    de dicha empresa.
                </p>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    02
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Solicitud por correo electrónico
                </h2>

                <div class="mt-5 rounded-3xl bg-white border border-[#14171C]/10 p-6 sm:p-8 shadow-sm">

                    <p class="text-[#14171C]/75 leading-8">
                        Podés enviar una solicitud a:
                    </p>

                    <a
                        href="mailto:[email protected]"
                        class="inline-flex mt-3 text-xl font-semibold text-[#FF6A1A] hover:underline"
                    >
                        [email protected]
                    </a>

                    <p class="mt-5 text-[#14171C]/75 leading-8">
                        Para poder identificar correctamente la cuenta y evitar
                        solicitudes fraudulentas, incluí, en la medida necesaria:
                    </p>

                    <ul class="mt-4 space-y-3 text-[#14171C]/70">

                        <li class="flex gap-3">
                            <span class="text-[#FF6A1A]">✓</span>
                            Nombre completo.
                        </li>

                        <li class="flex gap-3">
                            <span class="text-[#FF6A1A]">✓</span>
                            Correo electrónico asociado a la cuenta.
                        </li>

                        <li class="flex gap-3">
                            <span class="text-[#FF6A1A]">✓</span>
                            Empresa a la que pertenece la cuenta, si corresponde.
                        </li>

                        <li class="flex gap-3">
                            <span class="text-[#FF6A1A]">✓</span>
                            Una descripción clara de la solicitud.
                        </li>

                    </ul>

                </div>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    03
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Solicitud desde la plataforma
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Si Ascento dispone de una funcionalidad específica para
                    eliminar o desactivar una cuenta, el usuario podrá utilizar
                    dicho mecanismo siguiendo las instrucciones que aparezcan
                    dentro de la plataforma.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    El administrador de una empresa también puede gestionar los
                    usuarios pertenecientes a su organización conforme a las
                    funcionalidades disponibles en su cuenta.
                </p>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    04
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    ¿Qué información puede eliminarse?
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Cuando corresponda legalmente, podremos eliminar o anonimizar
                    datos personales vinculados a una cuenta, tales como datos
                    identificatorios, información de contacto y determinados
                    registros asociados al usuario.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    La eliminación de un usuario no implica necesariamente la
                    eliminación de información perteneciente a la empresa cliente,
                    como órdenes de trabajo, remitos, reportes, registros
                    históricos o documentación comercial.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    En esos casos, la información podrá mantenerse cuando sea
                    necesaria para la relación contractual, para garantizar la
                    integridad de los registros de la empresa, para resolver
                    reclamos o cuando exista una obligación legal de conservación.
                </p>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    05
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Verificación de identidad
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Antes de procesar determinadas solicitudes podremos requerir
                    información adicional para verificar la identidad del
                    solicitante y evitar que terceros accedan o eliminen
                    información sin autorización.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    No solicitaremos información adicional que resulte
                    innecesaria para verificar la solicitud.
                </p>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    06
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Plazos y respuesta
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Las solicitudes serán analizadas y respondidas dentro de los
                    plazos establecidos por la normativa aplicable, considerando
                    la naturaleza de la solicitud, la necesidad de verificar la
                    identidad y las obligaciones legales de conservación que
                    pudieran resultar aplicables.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Una vez analizada la solicitud, informaremos si corresponde
                    proceder con la eliminación, rectificación, anonimización o
                    conservación de determinada información y, cuando corresponda,
                    las razones legales o contractuales que impidan su eliminación.
                </p>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    07
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    Datos relacionados con WhatsApp
                </h2>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Si utilizaste una integración de WhatsApp Business mediante
                    Ascento, la solicitud podrá incluir datos personales tratados
                    en relación con dicha integración.
                </p>

                <p class="mt-4 text-[#14171C]/75 leading-8">
                    Sin embargo, la eliminación de información almacenada o
                    procesada directamente por Meta, WhatsApp u otros proveedores
                    externos puede estar sujeta a sus propios procedimientos,
                    políticas y condiciones.
                </p>

            </section>


            <section class="rounded-3xl bg-[#12151C] text-white p-7 sm:p-9">

                <span class="font-mono text-xs tracking-widest text-[#FF8A3D] uppercase">
                    Solicitar eliminación
                </span>

                <h2 class="mt-2 font-display text-2xl font-semibold">
                    ¿Querés solicitar la eliminación de tus datos?
                </h2>

                <p class="mt-3 text-white/60 leading-7">
                    Enviá tu solicitud explicando qué datos o cuenta querés
                    eliminar y utilizá, preferentemente, el correo asociado
                    a tu cuenta.
                </p>

                <a
                    href="mailto:[email protected]?subject=Solicitud%20de%20eliminación%20de%20datos"
                    class="inline-flex mt-6 rounded-full bg-[#FF6A1A] text-[#12151C] font-semibold px-5 py-3 hover:bg-[#FF8A3D] transition-colors"
                >
                    Solicitar eliminación
                </a>

            </section>


            <section>

                <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">
                    Contacto
                </span>

                <p class="mt-3 text-[#14171C]/60 leading-7">
                    Si tenés dudas sobre este procedimiento o sobre el tratamiento
                    de tus datos personales, podés comunicarte con nosotros en:
                </p>

                <a
                    href="mailto:[email protected]"
                    class="inline-flex mt-3 text-[#FF6A1A] font-semibold hover:underline"
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

            <a href="{{ route('legal.privacy') }}" class="hover:text-[#14171C]">
                Privacidad
            </a>

            <a href="{{ route('legal.data-deletion') }}" class="text-[#14171C]">
                Eliminación de Datos
            </a>

        </div>

    </div>

</footer>


</body>
</html>
