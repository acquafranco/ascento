{{--
    Eliminación de datos: quién puede pedir qué, cómo se verifica y qué se
    conserva. Refleja lo que el sistema hace hoy (19/10/2026): no hay un botón
    para borrar la propia cuenta; la empresa da de baja usuarios y accesos, y
    la eliminación definitiva de una empresa la hace Ascento a pedido
    (CompanyEraser).
--}}
@extends('legal.layout')

@section('title', 'Eliminación de datos')
@section('description', 'Cómo pedir la eliminación de tus datos o de tu empresa en Ascento, cómo se verifica la identidad y qué información puede conservarse.')
@section('active', 'data-deletion')
@section('kicker', 'Datos personales')
@section('heading', 'Eliminación de datos')
@section('lead', 'Cómo pedir que se eliminen tus datos o los de tu empresa, quién puede hacerlo, cómo verificamos el pedido y qué puede conservarse.')

@section('content')
    @php($email = config('legal.email'))
    @php($mailto = 'mailto:'.$email.'?subject='.rawurlencode('Solicitud de eliminación de datos'))

    <x-legal.section n="01" title="Elegí tu caso" id="casos">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl border border-[#14171C]/10 bg-white p-5">
                <p class="font-semibold text-[#14171C]">Soy dueño de una empresa y quiero eliminarla</p>
                <p class="mt-2 text-sm leading-6">Pedínoslo por correo desde el correo de la cuenta. Ver la sección 02.</p>
            </div>
            <div class="rounded-2xl border border-[#14171C]/10 bg-white p-5">
                <p class="font-semibold text-[#14171C]">Soy técnico o administrativo de una empresa</p>
                <p class="mt-2 text-sm leading-6">Tu empresa puede darte de baja. Si querés que se eliminen tus datos personales, escribinos. Ver la sección 03.</p>
            </div>
            <div class="rounded-2xl border border-[#14171C]/10 bg-white p-5">
                <p class="font-semibold text-[#14171C]">Uso el portal de clientes</p>
                <p class="mt-2 text-sm leading-6">La empresa que te invitó puede quitarte el acceso. Para eliminar tu cuenta, escribinos. Ver la sección 03.</p>
            </div>
            <div class="rounded-2xl border border-[#14171C]/10 bg-white p-5">
                <p class="font-semibold text-[#14171C]">Una empresa cargó mis datos (cliente, contacto)</p>
                <p class="mt-2 text-sm leading-6">Esa empresa decide sobre esos datos: pedíselo a ella o escribinos y le daremos intervención. Ver la sección 04.</p>
            </div>
        </div>
        <p>Hoy no hay un botón para que cada persona borre su propia cuenta desde Ascento: los pedidos se gestionan como se explica en esta página.</p>
    </x-legal.section>

    <x-legal.section n="02" title="Eliminar una empresa completa" id="empresa">
        <ol>
            <li><strong>Cancelá la suscripción</strong> desde «Mi suscripción», para que no haya cobros pendientes en Mercado Pago. Mientras una suscripción pueda seguir cobrando, la empresa no se elimina.</li>
            <li><strong>Exportá tus datos</strong> si los vas a necesitar (por ejemplo, remitos que debas conservar): desde el panel, «Exportar datos» genera un archivo con la información y los documentos de la empresa.</li>
            <li><strong>Escribinos</strong> a <a href="mailto:{{ $email }}">{{ $email }}</a> desde el correo del dueño de la cuenta, indicando el nombre de la empresa.</li>
            <li><strong>Confirmamos el pedido</strong> con el dueño antes de eliminar.</li>
        </ol>
        <x-legal.note tone="danger" title="La eliminación es definitiva">
            <p>Se borran de la base de datos la empresa, sus usuarios, clientes, edificios, ascensores, trabajos, remitos, reportes, presupuestos, avisos y exportaciones, y sus archivos (fotos, documentos, logo). No se puede deshacer.</p>
            <p>Si una persona del portal también tiene acceso por otra empresa, su cuenta se conserva para esa otra empresa y solo pierde el acceso a la eliminada.</p>
        </x-legal.note>
    </x-legal.section>

    <x-legal.section n="03" title="Eliminar tu cuenta de usuario" id="usuario">
        <p><strong>Técnicos y administrativos.</strong> El dueño de tu empresa puede darte de baja desde el panel: dejás de poder entrar. Los registros de trabajo que hiciste (por ejemplo, los remitos que firmaste) siguen siendo parte del historial de la empresa y conservan tu nombre, porque documentan trabajos realizados para sus clientes.</p>
        <p><strong>Usuarios del portal.</strong> La empresa que te invitó puede quitarte el acceso a sus edificios. Si además querés que se elimine tu cuenta, escribinos.</p>
        <p>En ambos casos, si querés que eliminemos tus datos personales, escribinos a <a href="mailto:{{ $email }}">{{ $email }}</a>. Revisaremos el pedido con la empresa, porque parte de esa información es del historial de su trabajo, y te diremos qué se elimina y qué se conserva y por qué.</p>
    </x-legal.section>

    <x-legal.section n="04" title="Datos que cargó una empresa sobre vos" id="terceros">
        <p>Si figurás en Ascento porque una empresa te cargó como cliente, contacto de un edificio o firmante de un remito, esa empresa es quien decide sobre esos datos. Podés pedirle directamente que los corrija o elimine. Si nos escribís a nosotros, le haremos llegar tu pedido y te ayudaremos a ejercer tus derechos.</p>
        <p>Quien usa el QR de un ascensor no tiene cuenta: un reporte se guarda sin nombre ni dirección IP. Si escribiste datos personales en un reporte o adjuntaste una foto y querés que se borren, pedíselo a la empresa de mantenimiento del edificio, que es quien gestiona ese reporte, o escribinos.</p>
    </x-legal.section>

    <x-legal.section n="05" title="Cómo verificamos el pedido" id="verificacion">
        <ul>
            <li>Respondemos al correo asociado a la cuenta y, si el pedido llega desde otro, te pedimos que lo confirmes desde ese correo.</li>
            <li>Para eliminar una empresa, solo atendemos el pedido de su dueño y lo confirmamos antes de hacerlo.</li>
            <li>Si hace falta, te pediremos algún dato más para identificarte, nunca más de lo necesario. No eliminamos nada a pedido de alguien cuya identidad no pudimos verificar.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="06" title="Qué puede conservarse" id="conservacion">
        <ul>
            <li><strong>Copias de seguridad:</strong> lo eliminado puede seguir en las copias de seguridad hasta que se reemplacen, en general en unas semanas. No se usan para otra cosa que recuperar el servicio ante una falla.</li>
            <li><strong>Historial de una empresa que sigue activa:</strong> los registros de trabajo con el nombre de un usuario dado de baja (sección 03).</li>
            <li><strong>Obligaciones legales:</strong> la información que una ley nos obligue a conservar, por el plazo que establezca, o la necesaria para defendernos ante un reclamo.</li>
        </ul>
        <p>Cuando no podamos eliminar algo, te lo diremos y te explicaremos el motivo.</p>
    </x-legal.section>

    <x-legal.section n="07" title="Plazos" id="plazos">
        <p>Respondemos dentro de los plazos que fija la Ley 25.326. Si para verificar tu identidad o consultar a una empresa necesitamos más información, te lo decimos y el plazo corre desde que la recibimos.</p>
        <p>Ascento no tiene hoy una integración con WhatsApp ni con otros servicios de Meta: no guardamos datos de esas plataformas. Lo que hayas enviado por WhatsApp desde tu propio teléfono queda en esa aplicación.</p>
        <p class="no-print"><a href="{{ $mailto }}" class="inline-flex items-center rounded-full bg-[#12151C] px-5 py-3 text-sm font-semibold !text-white hover:!no-underline">Solicitar eliminación por correo</a></p>
    </x-legal.section>
@endsection
