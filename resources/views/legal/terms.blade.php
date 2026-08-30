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

        <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink: '#14171C',
                        paper: '#F7F7F4',
                        graphite: '#12151C',
                        graphite2: '#1B1F29',
                        amber: { 100: '#FFE8D6', 400: '#FF8A3D', 500: '#FF6A1A', 600: '#E85A0A', 700: '#C24800' },
                        rail: { 100: '#E7ECFB', 400: '#5A78D6', 500: '#2E4FBE', 600: '#233D99' },
                    },
                    fontFamily: {
                        display: ['"Space Grotesk"', 'sans-serif'],
                        body: ['Inter', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    boxShadow: {
                        card: '0 1px 2px rgba(20,23,28,0.04), 0 8px 24px -8px rgba(20,23,28,0.10)',
                        cardHover: '0 4px 10px rgba(20,23,28,0.06), 0 20px 40px -12px rgba(20,23,28,0.18)',
                    },
                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

    <style>
        html { scrollbar-gutter: stable; }
        body { -webkit-font-smoothing: antialiased; }
        .font-tabular { font-variant-numeric: tabular-nums; }

        /* Reveal-on-scroll */
        [data-reveal] { opacity: 0; transform: translateY(14px); transition: opacity .6s ease, transform .6s ease; }
        [data-reveal].is-visible { opacity: 1; transform: translateY(0); }

        /* Shaft rail dot pulse for the active floor */
        .rail-dot { transition: background-color .25s ease, transform .25s ease, box-shadow .25s ease; }
        .rail-dot.is-active { transform: scale(1.35); box-shadow: 0 0 0 4px rgba(255,106,26,0.16); }

        @media (prefers-reduced-motion: reduce) {
            [data-reveal] { opacity: 1; transform: none; transition: none; }
            * { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; transition-duration: 0.001ms !important; }
        }

        ::selection { background: #FFE8D6; color: #14171C; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
    </style>
</head>
<body class="bg-paper text-ink font-body antialiased">

    {{-- ============ NAVBAR ============ --}}
    <header x-data="{ open: false, scrolled: false }" @scroll.window="scrolled = window.scrollY > 12"
            class="fixed inset-x-0 top-0 z-50 transition-colors duration-300"
            :class="scrolled ? 'bg-paper/90 backdrop-blur border-b border-ink/10' : 'bg-transparent border-b border-transparent'">
        <nav class="mx-auto max-w-7xl px-5 sm:px-8 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5 shrink-0">
               <img src="{{ asset('images/logo.png') }}" alt="Ascento" class="rounded-md h-8 w-8 object-contain">
                <span class="font-display font-semibold text-[17px] tracking-tight">Ascento</span>
            </a>

            <div class="hidden lg:flex items-center gap-8 text-sm font-medium text-ink/70">
                <a href="#beneficios" class="hover:text-ink transition-colors">Beneficios</a>
                <a href="#como-funciona" class="hover:text-ink transition-colors">Cómo funciona</a>
                <a href="#planes" class="hover:text-ink transition-colors">Planes</a>
                <a href="#faq" class="hover:text-ink transition-colors">Preguntas frecuentes</a>
            </div>

            <div class="hidden lg:flex items-center gap-3">
                <a href="{{ Route::has('login') ? route('login') : '/login' }}"
                   class="inline-flex items-center gap-1.5 rounded-full bg-graphite text-white text-sm font-semibold pl-4 pr-3.5 py-2.5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-200">
                    Entrar
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>

            <button @click="open = !open" class="lg:hidden p-2 -mr-2 text-ink" aria-label="Abrir menú">
                <svg x-show="!open" width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <svg x-show="open" x-cloak width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M6 6L18 18M6 18L18 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </button>
        </nav>

        <div x-show="open" x-cloak x-transition class="lg:hidden bg-paper border-b border-ink/10 px-5 pb-5 pt-1">
            <div class="flex flex-col gap-1 text-[15px] font-medium">
                <a @click="open=false" href="#beneficios" class="py-2.5 text-ink/75">Beneficios</a>
                <a @click="open=false" href="#como-funciona" class="py-2.5 text-ink/75">Cómo funciona</a>
                <a @click="open=false" href="#planes" class="py-2.5 text-ink/75">Planes</a>
                <a @click="open=false" href="#faq" class="py-2.5 text-ink/75">Preguntas frecuentes</a>
                <div class="flex gap-2 pt-3">
                    <a href="{{ Route::has('login') ? route('login') : '/login' }}" class="flex-1 text-center rounded-full bg-graphite text-white px-4 py-2.5 text-sm font-semibold">Entrar</a>
                </div>
            </div>
        </div>
    </header>

    {{-- ============ VERTICAL SHAFT RAIL (desktop only) ============ --}}
    <div class="hidden xl:flex flex-col items-center fixed left-8 top-1/2 -translate-y-1/2 z-40" aria-hidden="true">
        <div class="relative h-[420px] w-px bg-ink/10">
            <div id="railFill" class="absolute top-0 left-0 w-px bg-amber-500 transition-all duration-300 ease-out" style="height:0%"></div>
            <template x-for="(f, i) in ['PB','01','02','03','04','05']" :key="i"></template>
        </div>
        <div class="absolute flex flex-col items-center gap-1" style="top:-2.75rem">
            <span id="railLabel" class="font-mono text-[11px] tracking-wider text-ink/50 font-tabular">PB</span>
        </div>
    </div>


   <main class="max-w-3xl mx-auto px-6 py-14">

    <h1 class="text-3xl font-bold mb-2">
        Términos y Condiciones de Uso
    </h1>

    <p class="text-sm text-[#14171C]/50 mb-10">
        Última actualización:
        {{ now()->translatedFormat('d \d\e F \d\e Y') }}
    </p>

    <div class="prose prose-neutral max-w-none space-y-10">

        {{-- 1 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                1. Identificación del proveedor y aceptación
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Estos Términos y Condiciones de Uso (en adelante, los
                "Términos") regulan el acceso y utilización de la plataforma
                denominada <strong>Ascento</strong> (en adelante, "Ascento" o
                el "Servicio"), operada por
                <strong>[RAZÓN SOCIAL / NOMBRE COMPLETO]</strong>,
                CUIT <strong>[CUIT]</strong>, con domicilio en
                <strong>[DOMICILIO]</strong>, República Argentina
                (en adelante, el "Proveedor").
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El acceso, registro, contratación o utilización de Ascento
                implica la aceptación expresa de estos Términos, así como de
                la Política de Privacidad y de las demás políticas que resulten
                aplicables al Servicio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Si una persona utiliza Ascento en representación de una empresa
                u otra persona jurídica, declara y garantiza que posee facultades
                suficientes para obligarla contractualmente.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Si no está de acuerdo con estos Términos, deberá abstenerse de
                registrarse o utilizar el Servicio.
            </p>
        </section>

        {{-- 2 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                2. Objeto y descripción del Servicio
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Ascento es una plataforma de software como servicio (SaaS)
                destinada a facilitar la gestión administrativa y operativa de
                empresas dedicadas, entre otras actividades, al mantenimiento,
                inspección y asistencia técnica de ascensores y otros equipos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Según el plan contratado y las funcionalidades disponibles,
                Ascento puede permitir la gestión de empresas, usuarios,
                técnicos, clientes, edificios, equipos, mantenimientos,
                inspecciones, órdenes de trabajo, visitas, remitos,
                reportes, firmas, historial de actividades, notificaciones
                e indicadores de gestión.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Las funcionalidades disponibles pueden variar según el plan
                contratado, la configuración de la cuenta y las modificaciones
                realizadas por el Proveedor.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Ascento constituye una herramienta de gestión y organización.
                No reemplaza las obligaciones legales, técnicas, profesionales
                o de seguridad que correspondan al usuario, a sus técnicos,
                profesionales, clientes o terceros conforme a la normativa
                aplicable.
            </p>
        </section>

        {{-- 3 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                3. Registro y cuenta de usuario
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Para utilizar determinadas funcionalidades será necesario
                crear una cuenta y proporcionar información válida, completa
                y actualizada.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El titular de la cuenta será responsable de mantener actualizada
                la información proporcionada y de informar cualquier cambio
                relevante.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Las credenciales de acceso son personales e intransferibles.
                El titular de la cuenta deberá adoptar medidas razonables para
                impedir el acceso no autorizado y deberá comunicar al Proveedor,
                tan pronto como sea posible, cualquier sospecha de acceso
                indebido, pérdida o compromiso de sus credenciales.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                La empresa contratante será responsable de las acciones
                realizadas por los usuarios que haya creado, autorizado o
                incorporado a su cuenta, sin perjuicio de las responsabilidades
                que legalmente correspondan a cada usuario.
            </p>
        </section>

        {{-- 4 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                4. Responsabilidad sobre la información cargada
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El usuario conserva la responsabilidad sobre la información,
                documentos, fotografías, firmas, datos de clientes, datos de
                edificios, registros de mantenimiento, reportes y cualquier
                otro contenido que incorpore a Ascento.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario declara que cuenta con las autorizaciones,
                legitimaciones o bases jurídicas necesarias para recopilar,
                utilizar y almacenar dicha información mediante el Servicio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor no será responsable por la veracidad, exactitud,
                legalidad, integridad o legitimidad de la información ingresada
                por los usuarios.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario deberá abstenerse de cargar información cuya
                utilización, almacenamiento o tratamiento resulte contrario
                a la legislación aplicable o a estos Términos.
            </p>
        </section>

        {{-- 5 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                5. Datos personales y privacidad
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El tratamiento de datos personales realizado mediante Ascento
                se encuentra sujeto a la Política de Privacidad del Servicio
                y a la legislación argentina aplicable en materia de protección
                de datos personales.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor implementará medidas técnicas y organizativas
                razonables destinadas a preservar la seguridad y
                confidencialidad de la información bajo su control.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Cuando una empresa utilice Ascento para almacenar y gestionar
                datos personales de sus propios clientes, empleados,
                contratistas u otros terceros, dicha empresa será responsable
                de determinar la legitimidad de la recopilación y tratamiento
                de dichos datos y de cumplir las obligaciones que le
                correspondan como responsable del tratamiento.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá tratar dichos datos en la medida necesaria
                para prestar, mantener, proteger y mejorar el Servicio,
                conforme a estos Términos y a la Política de Privacidad.
            </p>
        </section>

        {{-- 6 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                6. Propiedad y titularidad de los datos del usuario
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Salvo respecto de los derechos que correspondan al Proveedor
                sobre el software, la marca y demás elementos propios de
                Ascento, el usuario conserva sus derechos sobre los datos y
                contenidos que incorpore legítimamente al Servicio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario otorga al Proveedor una autorización limitada,
                no exclusiva y necesaria para alojar, procesar, transmitir,
                respaldar y utilizar técnicamente dicha información únicamente
                en la medida necesaria para prestar y mantener el Servicio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor no adquiere la propiedad de los datos ingresados
                por el usuario por el solo hecho de que estos sean almacenados
                en Ascento.
            </p>
        </section>

        {{-- 7 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                7. Uso permitido del Servicio
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El Servicio deberá utilizarse exclusivamente para fines lícitos
                y de acuerdo con estos Términos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Está prohibido utilizar Ascento para:
            </p>

            <ul class="mt-3 list-disc pl-6 text-[#14171C]/80 space-y-2">
                <li>
                    realizar actividades contrarias a la legislación aplicable;
                </li>
                <li>
                    vulnerar derechos de terceros;
                </li>
                <li>
                    intentar acceder sin autorización a cuentas, sistemas o
                    información de terceros;
                </li>
                <li>
                    introducir código malicioso, virus o cualquier mecanismo
                    destinado a afectar el funcionamiento del Servicio;
                </li>
                <li>
                    interferir con la seguridad, disponibilidad o integridad
                    de la plataforma;
                </li>
                <li>
                    utilizar el Servicio para fines fraudulentos o ilícitos;
                </li>
                <li>
                    revender, sublicenciar o explotar comercialmente el
                    Servicio fuera de las condiciones expresamente autorizadas.
                </li>
            </ul>
        </section>

        {{-- 8 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                8. Suscripciones, precios y pagos
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El acceso a determinadas funcionalidades de Ascento puede
                requerir la contratación de un plan de suscripción.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Los precios, impuestos aplicables, períodos de facturación,
                funcionalidades incluidas y condiciones particulares del plan
                serán informados antes de la contratación y podrán encontrarse
                detallados en el sitio web o dentro de la plataforma.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá modificar los precios de los planes para
                períodos futuros. Las modificaciones serán comunicadas con
                anticipación razonable y no afectarán períodos ya abonados,
                salvo que corresponda legalmente otra solución.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                En caso de falta de pago, el Proveedor podrá limitar o suspender
                determinadas funcionalidades hasta la regularización de la
                situación, respetando las obligaciones legales aplicables.
            </p>
        </section>

        {{-- 9 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                9. Cancelación de la suscripción
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El titular de la cuenta podrá solicitar la cancelación de su
                suscripción conforme al procedimiento disponible dentro de
                Ascento o mediante los canales de contacto habilitados.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                La cancelación evitará futuras renovaciones cuando corresponda,
                pero no necesariamente implicará el reintegro de importes
                correspondientes a períodos ya iniciados, salvo que dicho
                reintegro corresponda conforme al plan contratado o a la
                legislación aplicable.
            </p>
        </section>

        {{-- 10 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                10. Disponibilidad y mantenimiento
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El Proveedor realizará esfuerzos razonables para mantener
                Ascento disponible y operativo, pero no garantiza una
                disponibilidad ininterrumpida o libre de errores.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Servicio podrá experimentar interrupciones temporales por
                tareas de mantenimiento, actualizaciones, mejoras, fallas
                técnicas, problemas de infraestructura, proveedores externos,
                interrupciones de conectividad, fuerza mayor u otras
                circunstancias que razonablemente escapen al control del
                Proveedor.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Cuando resulte razonablemente posible, el Proveedor procurará
                realizar mantenimientos programados de manera que reduzcan el
                impacto sobre los usuarios.
            </p>
        </section>

        {{-- 11 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                11. Copias de seguridad y conservación de información
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá implementar mecanismos de respaldo y
                recuperación de información destinados a reducir el riesgo de
                pérdida de datos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                No obstante, ningún sistema informático puede garantizar la
                recuperación absoluta de toda la información ante cualquier
                circunstancia. El usuario deberá conservar, cuando resulte
                necesario por la naturaleza de su actividad, copias propias de
                aquella información que considere crítica.
            </p>
        </section>

        {{-- 12 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                12. Integraciones y servicios de terceros
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Ascento puede integrarse con servicios de terceros, incluyendo
                proveedores de mensajería, pagos, almacenamiento, autenticación
                u otros servicios tecnológicos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El funcionamiento de dichas integraciones puede depender de
                servicios, políticas, condiciones técnicas o decisiones de
                terceros ajenos al control del Proveedor.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario acepta que el uso de dichos servicios puede estar
                sujeto adicionalmente a sus propios términos y políticas.
            </p>
        </section>

        {{-- 13 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                13. WhatsApp y Meta
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Cuando Ascento permita el envío de comunicaciones mediante
                WhatsApp u otros servicios de Meta, dichas funcionalidades
                estarán sujetas a la disponibilidad, políticas, requisitos,
                límites y condiciones establecidos por los respectivos
                proveedores.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario será responsable de contar con las autorizaciones
                y bases legales necesarias para enviar comunicaciones a sus
                contactos y de cumplir las políticas aplicables de WhatsApp,
                Meta y demás proveedores involucrados.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor no garantiza que un mensaje enviado mediante una
                integración de terceros sea entregado, leído o procesado por
                el destinatario.
            </p>
        </section>

        {{-- 14 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                14. Propiedad intelectual
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Ascento, incluyendo su software, código fuente, arquitectura,
                diseño, interfaz, marca, logotipos, elementos gráficos,
                documentación y demás componentes desarrollados por el
                Proveedor, son propiedad del Proveedor o se encuentran
                legítimamente licenciados a este.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                La contratación del Servicio no implica la transferencia al
                usuario de ningún derecho de propiedad intelectual sobre
                Ascento.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Se concede únicamente un derecho limitado, personal,
                no exclusivo y no transferible de utilización del Servicio
                durante la vigencia de la suscripción y conforme a estos
                Términos.
            </p>
        </section>

        {{-- 15 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                15. Firmas, remitos y registros generados mediante Ascento
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Las funcionalidades de firma, generación de remitos, órdenes
                de trabajo, registros de visitas y demás documentos disponibles
                en Ascento constituyen herramientas tecnológicas para registrar
                información proporcionada por los usuarios.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario será responsable de verificar que la información
                registrada sea correcta y de determinar, según la naturaleza
                de su actividad y la normativa aplicable, si los documentos
                generados resultan suficientes para sus necesidades legales,
                comerciales, técnicas o administrativas.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Ascento no garantiza que un determinado documento generado
                mediante la plataforma sea considerado por una autoridad,
                organismo, cliente o tercero como documento legalmente
                suficiente en todos los casos.
            </p>
        </section>

        {{-- 16 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                16. Limitación de responsabilidad
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                En la máxima medida permitida por la legislación aplicable,
                el Proveedor no será responsable por daños, pérdidas o
                perjuicios derivados de información incorrecta ingresada por
                los usuarios, decisiones tomadas sobre la base de dicha
                información, incumplimientos de obligaciones propias del
                usuario o hechos atribuibles a terceros ajenos al control
                razonable del Proveedor.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Tampoco será responsable por interrupciones, demoras o fallas
                originadas en servicios de terceros, proveedores de
                infraestructura, conectividad a Internet, servicios de
                mensajería, plataformas de pago u otros servicios externos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Las limitaciones establecidas en esta sección no serán
                aplicables en aquellos supuestos en los que la legislación
                vigente no permita limitar o excluir la responsabilidad.
            </p>
        </section>

        {{-- 17 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                17. Suspensión y terminación de cuentas
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá suspender temporalmente una cuenta cuando
                resulte razonablemente necesario para proteger la seguridad
                del Servicio, prevenir abusos, investigar actividades
                potencialmente ilícitas, evitar daños a terceros o ante
                incumplimientos de estos Términos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Cuando las circunstancias lo permitan, el Proveedor procurará
                informar al titular de la cuenta y otorgarle la posibilidad de
                subsanar el incumplimiento antes de proceder a una terminación
                definitiva.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                La terminación de una cuenta no afectará las obligaciones que,
                por su naturaleza, deban continuar vigentes después de la
                finalización de la relación contractual.
            </p>
        </section>

        {{-- 18 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                18. Eliminación y conservación de datos
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Luego de la cancelación de una cuenta, los datos asociados a
                ella podrán conservarse durante el período que resulte
                necesario para cumplir obligaciones legales, resolver
                controversias, prevenir fraudes, mantener registros contables
                o ejercer derechos legítimos.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Una vez cumplidos dichos plazos, la información podrá ser
                eliminada o anonimizada conforme a las políticas internas del
                Proveedor y a la legislación aplicable.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El usuario podrá solicitar información sobre los mecanismos
                disponibles para la exportación o eliminación de sus datos,
                sujeto a las obligaciones legales de conservación que resulten
                aplicables.
            </p>
        </section>

        {{-- 19 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                19. Modificaciones del Servicio
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá modificar, actualizar, agregar o retirar
                funcionalidades de Ascento con el objetivo de mejorar,
                mantener o adaptar el Servicio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Cuando una modificación implique un cambio sustancial en las
                condiciones contratadas, el Proveedor procurará comunicarlo
                con una antelación razonable cuando ello sea posible.
            </p>
        </section>

        {{-- 20 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                20. Modificaciones de estos Términos
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá actualizar estos Términos cuando resulte
                necesario por cambios legales, regulatorios, técnicos,
                comerciales o en el funcionamiento del Servicio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Las modificaciones relevantes serán comunicadas mediante la
                plataforma, correo electrónico u otro medio razonable
                disponible.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                La nueva versión será aplicable a partir de la fecha indicada
                en ella, sin perjuicio de los derechos que correspondan al
                usuario conforme a la legislación aplicable.
            </p>
        </section>

        {{-- 21 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                21. Comunicaciones
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El usuario acepta que determinadas comunicaciones relacionadas
                con la prestación del Servicio, incluyendo avisos de seguridad,
                mantenimiento, cambios de funcionalidades, facturación y
                cuestiones administrativas, puedan realizarse mediante correo
                electrónico, notificaciones dentro de Ascento u otros medios
                asociados a la cuenta.
            </p>
        </section>

        {{-- 22 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                22. Cesión
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                El usuario no podrá ceder, transferir o sublicenciar los
                derechos derivados de estos Términos sin autorización previa
                del Proveedor, salvo que la legislación aplicable disponga
                expresamente lo contrario.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                El Proveedor podrá transferir sus derechos u obligaciones
                derivados del Servicio a una sociedad vinculada, sucesora o
                adquirente del negocio, siempre respetando los derechos que
                correspondan a los usuarios conforme a la legislación aplicable.
            </p>
        </section>

        {{-- 23 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                23. Nulidad parcial
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Si alguna disposición de estos Términos fuera declarada nula,
                inválida o inaplicable, ello no afectará la validez de las
                restantes disposiciones, salvo que la legislación aplicable
                determine lo contrario.
            </p>
        </section>

        {{-- 24 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                24. Ley aplicable y jurisdicción
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Estos Términos se regirán e interpretarán de conformidad con
                las leyes de la República Argentina, sin perjuicio de las
                normas imperativas que resulten aplicables al usuario en
                función de su condición y domicilio.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Cualquier controversia será sometida a la jurisdicción de los
                tribunales que resulten competentes conforme a la legislación
                aplicable.
            </p>

            <p class="mt-3 text-[#14171C]/80 leading-relaxed">
                Cuando resulte aplicable la normativa de defensa del consumidor,
                se respetarán las reglas de competencia y jurisdicción previstas
                por dicha normativa.
            </p>
        </section>

        {{-- 25 --}}
        <section>
            <h2 class="text-xl font-semibold mb-3">
                25. Contacto
            </h2>

            <p class="text-[#14171C]/80 leading-relaxed">
                Para consultas, solicitudes o comunicaciones relacionadas con
                estos Términos o con el funcionamiento del Servicio, el usuario
                podrá comunicarse con el Proveedor mediante:
            </p>

            <ul class="mt-3 list-disc pl-6 text-[#14171C]/80 space-y-2">
                <li>
                    <strong>Responsable:</strong>
                    [RAZÓN SOCIAL / NOMBRE COMPLETO]
                </li>
                <li>
                    <strong>CUIT:</strong>
                    [CUIT]
                </li>
                <li>
                    <strong>Domicilio:</strong>
                    [DOMICILIO]
                </li>
                <li>
                    <strong>Email:</strong>
                    <a
                        href="mailto:[EMAIL LEGAL]"
                        class="text-[#FF6A1A] hover:underline"
                    >
                        [EMAIL LEGAL]
                    </a>
                </li>
            </ul>
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
