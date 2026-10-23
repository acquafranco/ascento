{{--
    Política de Privacidad. Describe SOLO los datos y tratamientos que Ascento
    hace hoy (verificados en el código el 19/10/2026). Plazos con valor
    concreto: los configurados en el sistema (config/location.php,
    CompanyExport::KEEP_DAYS, config/backup.php).
--}}
@extends('legal.layout')

@section('title', 'Política de Privacidad')
@section('description', 'Qué datos personales trata Ascento, para qué, quién los ve, cuánto tiempo se conservan y cómo ejercer tus derechos.')
@section('active', 'privacy')
@section('kicker', 'Privacidad')
@section('heading', 'Política de Privacidad')
@section('lead', 'Qué datos personales trata Ascento, quién decide sobre ellos, para qué se usan, quién los ve, cuánto tiempo se guardan y cómo ejercer tus derechos.')

@section('content')
    @php($email = config('legal.email'))

    <x-legal.section n="01" title="Quién decide sobre tus datos" id="roles">
        <p>En Ascento hay dos situaciones distintas, y conviene saber en cuál estás:</p>
        <ul>
            <li><strong>Datos que trata Ascento por su cuenta.</strong> Los de las cuentas de las empresas que contratan Ascento y de sus usuarios (nombre, correo, teléfono, acceso), la facturación de la suscripción, la seguridad del servicio (por ejemplo, registros de acceso y prevención de abusos) y la atención de consultas. Para estos datos, el responsable es el titular de Ascento, identificado al pie de esta página.</li>
            <li><strong>Datos que una empresa cliente carga para su trabajo.</strong> Los de sus clientes y edificios, los de las personas de contacto, los remitos con firmas y fotos, los reportes, las pruebas, los reclamos, los reportes de problemas desde el QR y los pedidos de ayuda registrados de sus edificios. Sobre estos datos decide <strong>esa empresa</strong>: ella define qué carga, para qué y con quién lo comparte. Ascento los guarda y procesa para prestarle el servicio, siguiendo sus instrucciones y sin usarlos para fines propios distintos de prestar, proteger y mantener el servicio.</li>
        </ul>
        <p>Si tus datos figuran en Ascento porque los cargó una empresa (por ejemplo, sos administrador de un consorcio o técnico de una empresa), lo más rápido para consultarlos, corregirlos o pedir que se borren es dirigirte a esa empresa. También podés escribirnos y le haremos llegar tu pedido.</p>
        <p>El titular de Ascento está en la República Argentina y el tratamiento se rige por la Ley 25.326 de Protección de Datos Personales y su reglamentación.</p>
        <p>Ascento también se usa desde otros países de Latinoamérica. Si estás en uno de ellos, tus datos se guardan con los proveedores de la sección 05, que pueden estar fuera de tu país, y además pueden asistirte los derechos que te reconozca la normativa de protección de datos de tu país: podés ejercerlos con nosotros por los mismos medios. Si una empresa de otro país carga datos de terceros, es ella quien debe cumplir la normativa de su país frente a esas personas.</p>
    </x-legal.section>

    <x-legal.section n="02" title="Qué datos se tratan" id="datos">
        <div class="overflow-x-auto rounded-2xl border border-[#14171C]/10 bg-white">
            <table class="w-full text-sm leading-6">
                <thead class="bg-[#12151C] text-white text-left">
                    <tr><th class="px-4 py-3 font-semibold">De quién</th><th class="px-4 py-3 font-semibold">Qué datos</th></tr>
                </thead>
                <tbody class="divide-y divide-[#14171C]/10 align-top">
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Dueño y administrativos de la empresa</td><td class="px-4 py-3">Nombre, correo, teléfono, contraseña (guardada cifrada de forma irreversible), permisos, aceptación de los términos y datos de la empresa (nombre, CUIT, domicilio, logo).</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Técnicos</td><td class="px-4 py-3">Nombre, correo, teléfono, foto de perfil si la cargan, trabajos asignados, remitos y firmas, y, solo si la empresa usa esa función y el técnico da permiso en su navegador, su ubicación mientras tiene la aplicación abierta.</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Clientes, administraciones y contactos</td><td class="px-4 py-3">Nombre, persona de contacto, teléfono, correo y notas que cargue la empresa; si tienen acceso al portal, su cuenta (nombre, correo, contraseña cifrada), los edificios autorizados y los reclamos que envíen, con sus fotos.</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Personas en los registros de trabajo</td><td class="px-4 py-3">Nombre y firma dibujada de quien firma un remito (técnico y, si firma, alguien del cliente), y lo que aparezca en fotos, reportes u observaciones. Las fotos se guardan sin metadatos, incluida la ubicación GPS.</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Visitantes del QR</td><td class="px-4 py-3">No se pide ningún dato personal ni una cuenta. Si alguien reporta un problema, se guardan el edificio, el ascensor si lo elige, lo que notó, lo que escribe, la foto si la adjunta, la fecha y hora y una huella cifrada de la conexión para frenar abusos; <strong>la dirección IP no se guarda</strong>. La foto queda en un archivo privado que solo ven la empresa y los técnicos asignados al edificio (no se publica ni se comparte con la administración). Recomendamos no incluir datos personales en el texto ni personas en la foto.</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Proveedores de repuestos</td><td class="px-4 py-3">Datos de la empresa proveedora (nombre comercial, razón social, identificación fiscal, país, contacto de ventas, cobertura, rubros y marcas), su catálogo con fotos (sin metadatos) y, de sus usuarios, nombre, correo y contraseña cifrada. El perfil y el catálogo aprobados son visibles para las empresas de mantenimiento con cuenta. Para Premium y los destacados: el plan, el estado de la suscripción, y de cada cobro el importe, la moneda, la fecha, el estado y el número de operación que informa Mercado Pago (nunca los datos de la tarjeta, que se cargan en Mercado Pago).</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Pedidos de repuestos</td><td class="px-4 py-3">Los productos pedidos, las cotizaciones, su historial, los comentarios entre las partes, las incidencias reportadas a Ascento y los datos de contacto que el comprador decide compartir con ese proveedor (nombre, correo, teléfono y zona de entrega). Ascento no recibe datos de pago de estos pedidos: el pago se coordina entre las partes.</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Perfiles profesionales y solicitudes de contacto</td><td class="px-4 py-3">Lo que el titular publica en su perfil (nombre público, tipo, descripción, experiencia, especialidades, servicios, marcas, zonas y sitio web), que es <strong>público</strong>; nunca se publican sus correos ni teléfonos. En cada solicitud, lo que escribe la empresa (necesidad, lugar y los datos de contacto que decide compartir) y los mensajes entre las partes, que solo ven ellas; Ascento accede al contenido solo por una necesidad administrativa y deja registro. Si una cuenta se cierra, el perfil queda archivado sin datos y las solicitudes se conservan sin los datos de contacto de la empresa que se dio de baja.</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Calculadora del sitio</td><td class="px-4 py-3">Para ver el resultado no se pide ningún dato personal. Si alguien pide la implementación o una propuesta, se guardan su nombre, correo, teléfono y empresa si los escribe, lo que ingresó en la calculadora y una huella cifrada de la conexión para frenar abusos (no la dirección IP).</td></tr>
                    <tr><td class="px-4 py-3 font-semibold text-[#14171C]">Todas las personas con cuenta</td><td class="px-4 py-3">Sesiones (con dirección IP y navegador), registro de acciones sensibles en el panel (con IP), avisos recibidos y su lectura, suscripciones a notificaciones del navegador y, si lo conectan, el identificador de su chat de Telegram.</td></tr>
                </tbody>
            </table>
        </div>
        <p>Ascento no pide datos sensibles (como salud, religión u opiniones políticas) y no está pensado para que se carguen. Si una empresa los incluye en notas, fotos u observaciones, es responsable de hacerlo con base legal.</p>
    </x-legal.section>

    <x-legal.section n="03" title="Para qué se usan" id="finalidades">
        <ul>
            <li><strong>Prestar el servicio:</strong> crear y administrar cuentas, permitir que cada empresa organice su trabajo, genere remitos y comparta información con sus clientes.</li>
            <li><strong>Avisos:</strong> enviar los avisos que corresponden a cada persona según su rol (trabajos asignados, remitos firmados, guardias, pruebas, pedidos de ayuda).</li>
            <li><strong>Seguridad:</strong> controlar accesos, detectar usos indebidos y abusos de los reportes desde el QR, y dejar constancia de acciones sensibles.</li>
            <li><strong>Suscripción y soporte:</strong> gestionar los planes y pagos de las empresas y responder consultas.</li>
            <li><strong>Repuestos:</strong> mostrar el catálogo, permitir que compradores y proveedores se contacten y sigan sus pedidos, moderar publicaciones y gestionar Premium y los destacados de los proveedores. No se usan para ordenar el catálogo según quién es el comprador.</li>
            <li><strong>Obligaciones legales:</strong> cumplir lo que exija la normativa.</li>
        </ul>
        <p>No vendemos datos personales, no los usamos para publicidad y Ascento no usa herramientas de analítica ni de publicidad de terceros.</p>
        <p><strong>Base del tratamiento.</strong> Los datos de las cuentas de las empresas se tratan para cumplir el contrato del servicio; los de seguridad y prevención de abusos, por el interés en proteger el servicio y a sus usuarios; y los que cargan las empresas sobre terceros, según la base que cada empresa tenga con esas personas (contrato, relación laboral, consentimiento u otra que corresponda), que es su responsabilidad.</p>
    </x-legal.section>

    <x-legal.section n="04" title="Quién puede ver los datos" id="destinatarios">
        <ul>
            <li><strong>Dentro de cada empresa:</strong> solo sus usuarios, según los permisos que la empresa les asigna. Una empresa nunca ve los datos de otra.</li>
            <li><strong>Portal del cliente:</strong> solo lo que la empresa comparte, de los edificios que autorizó para cada persona.</li>
            <li><strong>Página pública del QR:</strong> la información del edificio y del ascensor que la empresa decide publicar, incluidos los documentos y el estado de las incidencias que publique uno por uno. No muestra datos de técnicos, residentes ni contactos, ni el texto o las fotos de los reportes.</li>
            <li><strong>Enlace público de un remito:</strong> cualquier persona que tenga el enlace puede ver ese remito, incluidos los nombres y las firmas. La empresa decide con quién lo comparte.</li>
            <li><strong>Repuestos:</strong> un proveedor ve de cada empresa compradora solo su nombre y los datos de contacto que esa empresa comparte en sus pedidos, nunca sus clientes, edificios, técnicos ni documentos. Las empresas con cuenta ven el perfil y el catálogo de los proveedores aprobados, incluido su contacto de ventas.</li>
            <li><strong>El equipo de Ascento:</strong> solo cuando es necesario para soporte, seguridad, moderación del catálogo de repuestos o mantenimiento del servicio, y para responder los pedidos de la calculadora.</li>
            <li><strong>Proveedores que ayudan a prestar el servicio</strong> (sección 05) y autoridades, cuando una ley u orden judicial lo exija.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="05" title="Proveedores y transferencias" id="proveedores">
        <p>Para funcionar, Ascento usa estos tipos de proveedores, que tratan los datos solo para prestar su servicio:</p>
        <ul>
            <li><strong>Alojamiento del servidor y base de datos,</strong> donde se guarda la información y sus copias de seguridad.</li>
            <li><strong>Envío de correo electrónico,</strong> para avisos e invitaciones.</li>
            <li><strong>Mercado Pago,</strong> para las suscripciones de las empresas y los cobros a proveedores del marketplace (suscripción y destacados): el medio de pago se carga en Mercado Pago y Ascento no lo recibe.</li>
            <li><strong>Geoapify,</strong> para ubicar en el mapa las direcciones de los edificios y, si está configurado, mostrar el mapa del panel (si no, el mapa usa OpenStreetMap).</li>
            <li><strong>Servicios de notificaciones de los navegadores</strong> (por ejemplo, de Google, Apple o Mozilla), solo para quien activa las notificaciones.</li>
            <li><strong>Telegram,</strong> solo para quien conecta su cuenta para recibir avisos.</li>
        </ul>
        <p>Algunos de estos proveedores pueden procesar datos fuera de la Argentina y fuera del país desde donde usás Ascento. Cuando eso ocurra, procuramos que sea con proveedores que ofrezcan un nivel de protección adecuado o con las garantías que exija la normativa aplicable.</p>
    </x-legal.section>

    <x-legal.section n="06" title="Cuánto tiempo se guardan" id="conservacion">
        <ul>
            <li><strong>Datos de la cuenta y de trabajo de una empresa:</strong> mientras la empresa use Ascento. No se borran solos al cancelar o al vencer la suscripción: se eliminan cuando la empresa lo pide (ver <a href="{{ route('legal.data-deletion') }}">Eliminación de datos</a>).</li>
            <li><strong>Remitos y su historial:</strong> no se borran desde la aplicación, porque son el registro firmado del trabajo; se eliminan con la empresa.</li>
            <li><strong>Usuarios dados de baja por su empresa:</strong> dejan de poder entrar; su nombre se conserva en el historial de la empresa (por ejemplo, en los remitos que firmaron).</li>
            <li><strong>Ubicación de los técnicos:</strong> solo las posiciones recientes; se borran automáticamente a las pocas horas (hoy, a las 12 horas).</li>
            <li><strong>Exportaciones de datos:</strong> el archivo se puede descargar durante 7 días y después se borra.</li>
            <li><strong>Copias de seguridad:</strong> se hacen periódicamente y se conservan por un tiempo limitado, en general unas semanas, hasta que se reemplazan por copias nuevas. Un dato eliminado puede seguir en ellas hasta que venzan; no se usan para otra cosa que recuperar el servicio.</li>
            <li><strong>Pedidos de repuestos:</strong> se conservan como historial de la empresa compradora y del proveedor. Si una de las dos partes elimina su cuenta, la otra conserva el pedido, pero sin los datos de contacto, notas ni comentarios de la que se fue.</li>
            <li><strong>Productos de un proveedor que vuelve al plan gratuito:</strong> no se borran; los que no entran en su límite quedan «fuera del límite» hasta que el proveedor los vuelva a publicar o pida cerrar la cuenta.</li>
            <li><strong>Cobros a proveedores:</strong> se conservan como registro contable y fiscal por el plazo que exija la ley, aunque la cuenta del proveedor se cierre.</li>
            <li><strong>Pedidos de la calculadora:</strong> mientras sean útiles para responder y hacer el seguimiento comercial; se borran antes si la persona lo pide.</li>
            <li><strong>Datos que la ley obligue a conservar:</strong> por el plazo que la ley establezca.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="07" title="Tus derechos" id="derechos">
        <p>Podés pedir acceso a tus datos, su rectificación, actualización o supresión, en los términos de la Ley 25.326.</p>
        <p>El titular de los datos personales tiene la facultad de ejercer el derecho de acceso a los mismos en forma gratuita a intervalos no inferiores a seis meses, salvo que se acredite un interés legítimo al efecto conforme lo establecido en el artículo 14, inciso 3 de la Ley N.º 25.326.</p>
        <p>Escribinos a <a href="mailto:{{ $email }}">{{ $email }}</a>, de ser posible desde el correo asociado a tu cuenta. Podemos pedirte información para verificar que sos quien decís ser (nunca más de lo necesario). Respondemos dentro de los plazos que fija la ley. Si los datos los cargó una empresa, le daremos intervención, porque es quien decide sobre ellos.</p>
        <p>Si estás en otro país de Latinoamérica, también podés dirigirte a la autoridad de protección de datos de tu país, si existe.</p>
        <p>La Agencia de Acceso a la Información Pública, en su carácter de Órgano de Control de la Ley N.º 25.326, tiene la atribución de atender las denuncias y reclamos que interpongan quienes resulten afectados en sus derechos por incumplimiento de las normas vigentes en materia de protección de datos personales.</p>
    </x-legal.section>

    <x-legal.section n="08" title="Seguridad" id="seguridad">
        <p>Aplicamos medidas razonables para proteger la información, entre ellas: contraseñas guardadas con cifrado irreversible, permisos por rol y por empresa, separación de los datos de cada empresa, archivos privados (fotos, documentos) que solo se entregan a usuarios autorizados, segundo factor de autenticación para los administradores de la plataforma y registro de acciones sensibles.</p>
        <p>Ningún sistema es completamente seguro. Si ocurre un incidente que afecte datos personales, lo investigaremos, tomaremos medidas para contenerlo, lo informaremos a las empresas afectadas sin demoras indebidas y daremos aviso a las autoridades y a las personas afectadas cuando corresponda.</p>
    </x-legal.section>

    <x-legal.section n="09" title="Cookies y almacenamiento del navegador" id="cookies">
        <p>Ascento usa solo cookies necesarias para iniciar sesión y proteger los formularios, y guarda algunas preferencias en tu navegador. El detalle está en la <a href="{{ route('legal.cookies') }}">Política de Cookies</a>.</p>
    </x-legal.section>

    <x-legal.section n="10" title="Cambios en esta política" id="cambios">
        <p>Si cambiamos esta política, publicaremos la nueva versión con su fecha y, si el cambio es relevante, lo avisaremos dentro de Ascento o por correo.</p>
    </x-legal.section>
@endsection
