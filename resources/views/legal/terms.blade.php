{{--
    Términos y Condiciones. Cada afirmación sobre el producto está verificada
    en el código (ver el informe de la auditoría legal del 19/10/2026). Lo que
    requiere validación de un abogado queda indicado por el aviso de borrador
    (config legal.reviewed). Estilos propios: resources/css/public.css (ver layout).
--}}
@extends('legal.layout')

@section('title', 'Términos y Condiciones')
@section('description', 'Términos y Condiciones de uso de Ascento: qué es el servicio, qué no es, responsabilidades, pagos, suspensión y cancelación.')
@section('active', 'terms')
@section('kicker', 'Condiciones de uso')
@section('heading', 'Términos y Condiciones')
@section('lead', 'Qué es Ascento, qué hace y qué no hace, y las reglas de la relación entre Ascento, las empresas que lo contratan y las demás personas que lo usan.')

@section('content')
    @php($email = config('legal.email'))

    <x-legal.note tone="warn" title="Lo esencial, en pocas líneas">
        <ul>
            <li>Ascento es un <strong>software de gestión</strong>: sirve para registrar, organizar y comunicar información sobre el mantenimiento de ascensores. <strong>No mantiene, repara ni inspecciona ascensores</strong> y no reemplaza a la empresa conservadora, a los profesionales habilitados ni a los servicios de emergencia.</li>
            <li>Lo que se carga en Ascento (remitos, inspecciones, pruebas, fotos) lo cargan las empresas y sus técnicos. <strong>Un registro no prueba por sí solo que el trabajo se haya hecho bien</strong>.</li>
            <li>En <strong>Repuestos</strong>, Ascento conecta a empresas compradoras con proveedores: no vende, no cobra comisiones por las ventas ni procesa sus pagos, y no verifica la compatibilidad que declara cada proveedor. Pago, envío y entrega se acuerdan entre las partes (secciones 17 y 18).</li>
            <li>Ascento es una plataforma de gestión y comunicación. <strong>Un reporte desde el QR no es una solicitud de rescate</strong>: se registra e intenta avisar, pero no garantiza que alguien lo vea ni una respuesta inmediata. Ante un atrapamiento o un peligro: alarma o intercomunicador del ascensor y <strong>911</strong>.</li>
        </ul>
    </x-legal.note>

    <x-legal.section n="01" title="Quién presta el servicio y a quién se aplican estos términos" id="partes">
        <p>Ascento es un servicio de software prestado por su titular, cuyos datos figuran al pie de esta página (en adelante, «Ascento» o «nosotros»). En estos términos:</p>
        <ul>
            <li><strong>Empresa cliente:</strong> la empresa (habitualmente, una empresa de conservación o mantenimiento de ascensores) que crea una cuenta y contrata Ascento.</li>
            <li><strong>Usuarios de la empresa:</strong> el dueño de la cuenta, los administrativos y los técnicos que la empresa cliente da de alta.</li>
            <li><strong>Usuarios del portal:</strong> personas de las administraciones o consorcios a las que la empresa cliente invita a ver información de sus edificios.</li>
            <li><strong>Visitantes:</strong> personas sin cuenta que abren la página pública de un QR o un enlace público de un remito.</li>
        </ul>
        <p>Quien registra una empresa acepta estos términos al crear la cuenta (la aceptación queda registrada con su fecha) y declara que tiene facultades para obligar a esa empresa. Los demás usuarios quedan alcanzados por las reglas de uso que les corresponden cada vez que usan Ascento; la empresa cliente es quien los invita y debe informarles que la herramienta se rige por estos términos. Los visitantes del QR no necesitan aceptar nada para leer la información pública; la sección 07 explica el pedido de ayuda. Quien registra una cuenta de proveedor de repuestos acepta estos términos, en especial la sección 17.</p>
        <p>Ascento se ofrece para empresas que operan en la República Argentina y en otros países de Latinoamérica. El servicio lo presta su titular desde la República Argentina.</p>
        <p>Quien contrata desde otro país es responsable de cumplir las normas de ese país que alcancen a su actividad y al uso que haga de Ascento (por ejemplo, las de protección de datos personales, las fiscales o las que regulan el mantenimiento de ascensores). Ascento no verifica ni asesora sobre esas normas.</p>
    </x-legal.section>

    <x-legal.section n="02" title="Qué es Ascento y qué no es" id="servicio">
        <p>Según el plan contratado, Ascento permite registrar clientes, edificios y ascensores; organizar mantenimientos, inspecciones, órdenes de trabajo, pruebas de paracaídas y guardias; generar remitos con firma dibujada, fotos y reportes; compartir información con el portal de la administración; publicar un QR con información del ascensor; y enviar avisos dentro de la aplicación, por correo, por notificaciones del navegador y, si cada persona lo conecta, por Telegram.</p>
        <p><strong>Ascento no:</strong></p>
        <ul>
            <li>realiza por sí mismo el mantenimiento, la reparación, la inspección ni las pruebas físicas de los ascensores;</li>
            <li>es una empresa conservadora, ni sustituye al conservador habilitado, al técnico o profesional competente ni a las inspecciones que exija la normativa o la autoridad local;</li>
            <li>certifica ni garantiza que un ascensor sea seguro o esté en condiciones;</li>
            <li>define qué controles, pruebas o frecuencias son obligatorios: las periodicidades que se cargan (por ejemplo, de pruebas de paracaídas) las define la empresa cliente;</li>
            <li>contrata, ejecuta ni supervisa los trabajos: eso corresponde a la empresa cliente y a sus clientes, según los acuerdos que tengan entre ellos;</li>
            <li>presta servicios de emergencia, vigilancia ni atención permanente, ni reemplaza la alarma o el intercomunicador del ascensor ni a los servicios públicos de emergencia.</li>
        </ul>
        <p>Las funciones disponibles dependen del plan y de cómo la empresa cliente configure su cuenta, y pueden cambiar con el tiempo (sección 13).</p>
    </x-legal.section>

    <x-legal.section n="03" title="Responsabilidades de la empresa cliente" id="empresa">
        <p>La empresa cliente es responsable de su actividad y de cómo usa Ascento. En particular, debe:</p>
        <ul>
            <li>contar con las habilitaciones, seguros y personal que su actividad requiera, y cumplir sus contratos con sus clientes;</li>
            <li>verificar que lo que se registra (trabajos, inspecciones, pruebas, materiales, firmas, fotos) sea exacto y completo, y corregirlo cuando no lo sea;</li>
            <li>conservar, por los medios que corresponda, los documentos que la ley o sus contratos le obliguen a mantener: Ascento permite exportarlos, pero no reemplaza esa obligación;</li>
            <li>dar de alta solo a personas autorizadas, asignar los permisos adecuados y dar de baja a quien deje de estar autorizado;</li>
            <li>tener una base legal para cargar datos personales de sus clientes, técnicos y terceros, e informarles lo necesario (ver la <a href="{{ route('legal.privacy') }}">Política de Privacidad</a>);</li>
            <li>si recibe reportes de problemas desde el QR, revisarlos y gestionarlos según sus propios procedimientos y las obligaciones que le correspondan (sección 07), y mantener personas habilitadas para recibir los avisos;</li>
            <li>publicar en el QR o compartir en el portal solo información y documentos que tenga derecho a difundir, sin datos personales que no corresponda mostrar;</li>
            <li>cargar correctamente su país y su identificación fiscal, y cumplir las normas del país donde opera.</li>
        </ul>
        <p>La empresa cliente responde por lo que hacen en su cuenta los usuarios que ella da de alta, sin perjuicio de la responsabilidad personal de cada uno.</p>
    </x-legal.section>

    <x-legal.section n="04" title="Registros, remitos, firmas y fotos" id="registros">
        <p>Ascento guarda lo que los usuarios cargan, con la fecha y la hora del servidor y el usuario que lo hizo. Por eso:</p>
        <ul>
            <li>El registro de un mantenimiento, una inspección, una prueba de paracaídas o un remito <strong>muestra lo que alguien declaró en el sistema</strong>; no demuestra por sí solo que el trabajo físico se haya realizado ni que se haya realizado correctamente.</li>
            <li>La <strong>firma del remito es una imagen del trazo dibujado en la pantalla</strong>, acompañada del nombre escrito, la fecha y hora y el usuario que lo generó. No es una firma digital con certificado emitido por un certificador licenciado. Su valor como prueba lo determinará, en cada caso, quien deba evaluarlo.</li>
            <li>Un remito «no realizado» o un registro incompleto también queda en el historial: los remitos no se borran desde la aplicación.</li>
            <li>Cada remito tiene un enlace público: <strong>cualquier persona que tenga ese enlace puede ver el remito</strong>. La empresa cliente decide con quién lo comparte.</li>
            <li>Las fotos se guardan sin sus metadatos de ubicación y solo las ven las personas autorizadas de la empresa y, si la empresa lo comparte, el portal del cliente.</li>
        </ul>
        <p>Ascento no garantiza que un documento generado con la plataforma sea aceptado como suficiente por un cliente, una autoridad o un tercero.</p>
    </x-legal.section>

    <x-legal.section n="05" title="Avisos y notificaciones" id="avisos">
        <p>Ascento envía avisos dentro de la aplicación y, según cada caso y la configuración de cada persona, por correo electrónico, notificaciones del navegador o Telegram. Estos avisos son una ayuda, no un canal garantizado:</p>
        <ul>
            <li>pueden demorarse o no llegar por fallas de conexión, del dispositivo, del navegador, del servicio de correo o de terceros, o porque la persona no activó ese canal;</li>
            <li>Ascento solo puede saber que un aviso fue <strong>creado o enviado</strong> y, dentro de la aplicación, si la persona <strong>lo abrió</strong>; no puede saber si alguien lo leyó en otro medio ni si actuó en consecuencia;</li>
            <li>con la suscripción vencida, la empresa y sus usuarios dejan de recibir avisos.</li>
        </ul>
        <p>La empresa cliente no debe depender solo de estos avisos para cumplir obligaciones de seguridad o plazos críticos.</p>
    </x-legal.section>

    <x-legal.section n="06" title="Pruebas de paracaídas, guardias y ubicación de los técnicos" id="operacion">
        <p><strong>Pruebas de paracaídas.</strong> Ascento calcula la próxima fecha prevista a partir de la periodicidad que configura la empresa cliente y de las pruebas registradas. Si no hay periodicidad cargada, lo indica y no calcula ninguna. Un recordatorio nunca cuenta como prueba hecha: solo un registro. La empresa cliente determina qué pruebas corresponden y cuándo.</p>
        <p><strong>Guardias.</strong> Las guardias organizan quién cubre cada horario. Ascento no garantiza que haya un técnico disponible ni que la guardia se cumpla: eso depende de la empresa cliente y de su personal.</p>
        <p><strong>Ubicación.</strong> Si la empresa usa esa función y el técnico da permiso en su navegador, Ascento recibe la ubicación que informa su navegador (con su margen de precisión) solo mientras tiene la aplicación abierta, y la guarda por pocas horas. No hay seguimiento en segundo plano. La empresa cliente debe informar a sus técnicos y contar con la base legal para usarla.</p>
    </x-legal.section>

    <x-legal.section n="07" title="QR público y reporte de problemas" id="qr">
        <p>La empresa cliente puede publicar un QR por edificio con la información que ella elige (el «pasaporte del ascensor»): datos del edificio y de los equipos, fechas de servicios registrados, lo que corresponde en el período según la frecuencia que ella cargó, y los documentos y el estado de las incidencias que decida publicar uno por uno. Es un <strong>historial de registros y documentos</strong>: no es una certificación de seguridad, una habilitación ni una garantía de que el ascensor esté en condiciones de funcionamiento.</p>
        <p>Si su plan lo incluye y ella no lo desactiva, la página del QR permite <strong>«Reportar un problema»</strong>: cualquier persona, sin cuenta, elige qué notó, lo describe y puede adjuntar una foto.</p>
        <x-legal.note tone="danger" title="Reportar un problema no es un servicio de emergencias">
            <p>Ante un atrapamiento o un peligro, usá el botón de alarma o el intercomunicador del ascensor y llamá al número de emergencias de tu país (en Argentina, el <strong>911</strong>). Un reporte no es una solicitud de rescate atendida. Ascento no presta asistencia, no tiene personal de guardia y no garantiza una respuesta. El reporte no reemplaza los medios de comunicación y de emergencia con que deba contar el ascensor.</p>
        </x-legal.note>
        <p>Cuando alguien envía un reporte, pueden ocurrir cosas distintas, que Ascento distingue y no presenta como equivalentes:</p>
        <ol>
            <li><strong>Envío:</strong> el teléfono de la persona intenta mandar el reporte. Sin conexión, o si el servicio no está disponible, no llega: la página lo indica y no muestra una confirmación.</li>
            <li><strong>Registro:</strong> si llega, Ascento lo guarda con un código, fecha y hora, y se lo muestra a la persona.</li>
            <li><strong>Intento de aviso:</strong> Ascento intenta avisar a las personas de la empresa cliente que pueden recibir estos avisos y a los técnicos asignados a ese edificio, y registra cada intento. Emitir o registrar un aviso no garantiza que alguien lo haya recibido.</li>
            <li><strong>Lectura:</strong> Ascento solo sabe que un destinatario lo vio si lo abrió dentro de la aplicación.</li>
            <li><strong>Gestión:</strong> la empresa cliente gestiona cada reporte según sus procedimientos y las obligaciones que le correspondan. Sus estados («Recibido», «En revisión», «Asociado a una orden de trabajo», «Resuelto», «Cerrado») describen ese seguimiento administrativo y no confirman una reparación ni la seguridad del equipo. Crear una orden de trabajo es siempre una decisión de la empresa.</li>
        </ol>
        <p>Además, «Reportar un problema» deja de estar disponible si la empresa lo desactiva, si su plan no lo incluye o si su suscripción vence; hay límites de envíos para frenar abusos; y un aviso puede no llegar por las razones de la sección 05.</p>
        <p><strong>Uso indebido.</strong> Está prohibido enviar reportes falsos o abusivos, o fotos de personas sin su consentimiento. Ascento aplica límites automáticos y puede descartar envíos repetidos.</p>
        <p><strong>Pedido de ayuda (retirado).</strong> Hasta la incorporación de «Reportar un problema», el QR podía ofrecer un botón de pedido de ayuda por atrapamiento. Esa opción se retiró: ya no registra pedidos ni envía avisos. Los pedidos registrados antes se conservan como historial de la empresa cliente.</p>
    </x-legal.section>

    <x-legal.section n="08" title="Portal de clientes y reclamos" id="portal">
        <p>El portal permite a las administraciones ver solo lo que la empresa cliente comparte de los edificios autorizados (incluidos los reportes del QR que decida compartir y un resumen mensual armado con los registros de la empresa), y enviarle reclamos. Un reclamo es una comunicación a la empresa cliente: su respuesta, sus plazos y su atención dependen de ella. El resumen mensual no es un certificado de cumplimiento ni una evaluación técnica. El portal no es un canal de emergencias.</p>
    </x-legal.section>

    <x-legal.section n="09" title="Cuentas, seguridad y uso permitido" id="uso">
        <p>Cada usuario debe cuidar sus credenciales, no compartirlas y avisarnos si sospecha un acceso indebido. No está permitido:</p>
        <ul>
            <li>usar Ascento para fines ilícitos o para vulnerar derechos de terceros;</li>
            <li>intentar acceder a cuentas, empresas o datos ajenos, o eludir los permisos;</li>
            <li>afectar el funcionamiento o la seguridad del servicio, o cargar código malicioso;</li>
            <li>enviar pedidos de ayuda o reclamos falsos;</li>
            <li>revender o sublicenciar el servicio sin autorización.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="10" title="Planes, prueba gratuita y pagos" id="pagos">
        <ul>
            <li>Los planes, sus precios, la moneda y lo que incluyen se informan en el sitio y dentro de Ascento antes de contratar.</li>
            <li>La prueba gratuita no requiere medio de pago. Al vencer, para seguir usando Ascento hay que contratar un plan.</li>
            <li>Los pagos se procesan a través de Mercado Pago, según sus propias condiciones. Ascento no recibe ni guarda los datos completos de tarjetas.</li>
            <li>Si pagás desde otro país, la disponibilidad de los medios de pago, la conversión de moneda y los impuestos, percepciones o cargos de tu país o de tu banco dependen de Mercado Pago, de tu entidad y de la normativa aplicable, no de Ascento.</li>
            <li>Podemos cambiar los precios para períodos futuros avisando con anticipación razonable; el cambio no afecta períodos ya pagados.</li>
            <li>Si el acceso vence o el pago no se acredita, el dueño de la cuenta solo ve la pantalla de suscripción para regularizarla, los técnicos dejan de operar y se interrumpen los avisos y los reportes desde el QR. Los datos no se eliminan por la falta de pago (ver sección 12).</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="11" title="Disponibilidad, copias de seguridad y pérdida de datos" id="disponibilidad">
        <p>Trabajamos para que Ascento funcione de forma continua, pero no garantizamos un servicio sin interrupciones ni errores, ni un nivel de disponibilidad determinado. Puede haber cortes por mantenimiento, actualizaciones, fallas propias o de proveedores, problemas de conectividad o hechos fuera de nuestro control.</p>
        <p>Ascento hace copias de seguridad periódicas de la información, que se conservan por un tiempo limitado y luego se reemplazan. Reducen el riesgo de pérdida, pero no garantizan que siempre se pueda recuperar todo. Por eso, la empresa cliente puede exportar sus datos desde Ascento y le recomendamos guardar copias propias de lo que sea crítico para su actividad.</p>
        <p>Si detectamos un incidente de seguridad que afecte datos de una empresa cliente, se lo informaremos sin demoras indebidas, con lo que sepamos y las medidas tomadas, y daremos aviso a las autoridades cuando la normativa lo exija.</p>
    </x-legal.section>

    <x-legal.section n="12" title="Suspensión, cancelación y qué pasa con los datos" id="baja">
        <p>Podemos suspender una cuenta cuando sea razonablemente necesario para proteger el servicio, a otros usuarios o a terceros, o ante un incumplimiento grave de estos términos. Salvo urgencia, avisaremos antes y daremos la posibilidad de corregirlo.</p>
        <p>La empresa cliente puede cancelar su suscripción en cualquier momento desde «Mi suscripción» o escribiéndonos; la cancelación evita las renovaciones futuras. No se reintegran períodos ya iniciados, salvo que corresponda por ley.</p>
        <p>Los datos de una empresa no se borran solos al cancelar o al vencer la suscripción. La empresa puede exportarlos y pedirnos la eliminación definitiva, como explica la página de <a href="{{ route('legal.data-deletion') }}">Eliminación de datos</a>.</p>
    </x-legal.section>

    <x-legal.section n="13" title="Cambios en el servicio y en estos términos" id="cambios">
        <p>Podemos agregar, modificar o quitar funciones. Si un cambio afecta de forma sustancial lo contratado, o si cambiamos estos términos, lo avisaremos dentro de Ascento o por correo con anticipación razonable. Si la empresa cliente no está de acuerdo, puede cancelar antes de que el cambio entre en vigencia.</p>
    </x-legal.section>

    <x-legal.section n="14" title="Propiedad intelectual y datos de la empresa" id="propiedad">
        <p>El software, la marca y los materiales de Ascento son de su titular. La contratación da un derecho de uso limitado, no exclusivo e intransferible mientras dure la suscripción.</p>
        <p>Los datos que carga la empresa cliente siguen siendo suyos. Nos autoriza a alojarlos, procesarlos, respaldarlos y transmitirlos solo en la medida necesaria para prestar el servicio, según la <a href="{{ route('legal.privacy') }}">Política de Privacidad</a>.</p>
    </x-legal.section>

    <x-legal.section n="15" title="Responsabilidad" id="responsabilidad">
        <p>Ascento responde por el cumplimiento de sus propias obligaciones según la ley aplicable. No responde por:</p>
        <ul>
            <li>el estado de los ascensores, ni los trabajos, inspecciones, pruebas o asistencias que hagan o dejen de hacer la empresa cliente, sus técnicos u otras personas;</li>
            <li>la exactitud de la información que cargan los usuarios ni las decisiones tomadas en base a ella;</li>
            <li>que un aviso, un reporte o un pedido de ayuda registrado no sea visto o atendido por sus destinatarios;</li>
            <li>fallas de servicios de terceros (conectividad, correo, notificaciones del navegador, Telegram, pagos) que no estén bajo nuestro control razonable.</li>
        </ul>
        <p>Frente a empresas clientes, y en la medida en que la ley lo permita, la responsabilidad total de Ascento por reclamos vinculados al servicio se limita al monto efectivamente pagado por la empresa en los doce meses anteriores al hecho que la origina.</p>
        <p>Estas limitaciones <strong>no se aplican</strong> a los daños causados con dolo o culpa grave, a los daños a la vida o la salud de las personas, ni a los casos en que la ley no permite limitar o excluir la responsabilidad, incluidos los derechos que la normativa de defensa del consumidor reconozca a quien corresponda.</p>
    </x-legal.section>

    <x-legal.section n="16" title="Terceros" id="terceros">
        <p>Ascento usa servicios de terceros para funcionar (por ejemplo, alojamiento, correo electrónico, notificaciones del navegador, Telegram si cada persona lo conecta, mapas y procesamiento de pagos con Mercado Pago). Su funcionamiento depende también de sus propias condiciones. Hoy Ascento no tiene una integración con WhatsApp: los botones para compartir por WhatsApp abren la aplicación de quien los usa.</p>
    </x-legal.section>

    <x-legal.section n="17" title="Repuestos: Ascento conecta compradores y proveedores" id="repuestos">
        <p>Ascento ofrece un catálogo donde proveedores de repuestos aprobados publican productos y las empresas de mantenimiento les piden cotizaciones. <strong>Ascento conecta a las partes; no es parte de la compraventa.</strong></p>
        <ul>
            <li><strong>Ascento no vende, no compra, no cobra comisiones por las ventas ni procesa los pagos de los repuestos.</strong> Una cotización no es una compra: si el comprador la acepta, el pago, la factura, el envío, la entrega, la garantía y las devoluciones se acuerdan <strong>directamente</strong> entre el proveedor y el comprador. Ascento no marca ningún pedido como pagado ni confirma entregas.</li>
            <li>El nombre, la descripción, las fotos, el precio, la moneda, la disponibilidad, los plazos y la <strong>compatibilidad</strong> de cada producto los <strong>declara el proveedor</strong>, que es responsable de que sean ciertos y de que el producto sea legal y seguro. Ascento no los verifica. Antes de comprar o instalar un repuesto, la empresa de mantenimiento debe confirmar su compatibilidad y su idoneidad técnica.</li>
            <li><strong>Qué se comparte al pedir una cotización:</strong> el proveedor ve el nombre de la empresa compradora y los datos de contacto que esta elige compartir en el pedido (nombre, correo, teléfono, zona de entrega y notas); nunca sus clientes, edificios, técnicos, órdenes de trabajo, remitos ni documentos. El comprador ve el perfil comercial del proveedor y los medios de contacto que este publica. Cada parte debe usar esos datos solo para ese pedido.</li>
            <li><strong>Historial:</strong> los pedidos, cotizaciones, comentarios y cambios de estado se conservan como historial de las dos partes y no se editan. Si una de las partes elimina su cuenta, la otra conserva el historial sin los datos personales de la que se fue (ver <a href="{{ route('legal.data-deletion') }}">Eliminación de datos</a>).</li>
            <li><strong>Cancelaciones:</strong> el comprador puede cancelar un pedido mientras no esté completado y el proveedor puede avisar que no puede cotizar. Una cotización vencida no se puede aceptar. Ascento puede anular un pedido abierto ante una incidencia administrativa (por ejemplo, fraude o una cuenta cerrada), con el motivo visible para las dos partes; nunca cambia los importes cotizados.</li>
            <li><strong>Incidencias:</strong> cualquiera de las partes puede reportar un problema con un pedido. Ascento puede revisarlo y mediar, pero no garantiza pagos, envíos, entregas ni el cumplimiento de la otra parte.</li>
            <li><strong>Moderación:</strong> Ascento revisa y aprueba las cuentas de los proveedores y puede ocultar o retirar publicaciones, suspender cuentas o resolver reportes cuando una publicación es engañosa, insegura o no cumple estas reglas. La moderación es independiente de lo que el proveedor haya pagado.</li>
            <li>Las «operaciones completadas» que se muestran de cada proveedor cuentan solo los pedidos que el comprador confirmó como recibidos. No son reseñas ni una garantía de calidad.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="18" title="Condiciones comerciales para proveedores de repuestos" id="proveedores">
        <p>Además de la sección 17, a los proveedores se les aplica lo siguiente. La suscripción y los destacados son <strong>servicios de Ascento al proveedor</strong>, separados de las operaciones entre compradores y proveedores: no son una comisión sobre ventas.</p>
        <ul>
            <li><strong>Prueba gratuita.</strong> Al aprobar la cuenta empieza una prueba gratuita por los días que indique Ascento al momento de la aprobación (hoy, 30 días). Se otorga una sola vez por proveedor y no requiere un medio de pago.</li>
            <li><strong>Suscripción.</strong> Terminada la prueba, para que el catálogo siga visible y recibir pedidos nuevos puede requerirse una suscripción mensual. Su precio se informa en el área del proveedor antes de contratarla; mientras no esté publicado, no se cobra. Se paga con débito automático en Mercado Pago (los datos de pago se cargan en Mercado Pago, no en Ascento), se activa cuando Mercado Pago confirma el cobro y se puede cancelar en cualquier momento: la cancelación evita los cobros futuros y el acceso sigue hasta el fin del período pago. No se reintegran períodos ya iniciados, salvo que corresponda por ley.</li>
            <li><strong>Publicaciones destacadas.</strong> El proveedor puede contratar, por un pago único, visibilidad adicional para un producto durante el plazo elegido. Un destacado se muestra con la etiqueta «Destacado» y solo se ordena antes que productos <strong>igual de relevantes</strong> para la búsqueda del comprador: no hace que un producto que no corresponde a la búsqueda aparezca primero. Se activa cuando Mercado Pago confirma el pago; renovar es contratar otro período. Si la publicación se oculta, se retira por moderación o la cuenta se suspende, el destacado se cancela; la devolución, si corresponde, la revisa Ascento caso por caso.</li>
            <li><strong>Qué pasa al vencer la prueba o la suscripción.</strong> Los productos dejan de verse en el catálogo y no se reciben pedidos nuevos, pero <strong>no se borra nada</strong>: productos, fotos, perfil, pedidos y cotizaciones se conservan, y lo publicado vuelve a verse al reactivar la cuenta. Las operaciones ya aceptadas siguen su curso entre las partes. Las publicaciones ocultas o retiradas por moderación no se reactivan solas.</li>
            <li><strong>Suspensión y rechazo.</strong> Una cuenta suspendida o rechazada no se habilita pagando. Si se suspende una cuenta con una suscripción activa, Ascento puede cancelarla para que no se sigan generando cobros.</li>
            <li><strong>Otros países.</strong> En esta etapa los cobros solo están disponibles para proveedores registrados en Argentina. Los proveedores de otros países pueden operar sin cargo mientras Ascento mantenga esa modalidad, que puede cambiar con aviso previo. Ascento no cobra en monedas o medios que no estén disponibles en el área del proveedor. El país es el que el proveedor registró y validó con su identificación fiscal.</li>
            <li><strong>Cambios de precio.</strong> Cualquier cambio en el precio de la suscripción o de los destacados se avisa con anticipación razonable y no afecta períodos ya pagados.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="19" title="Calculadora y servicio de implementación" id="implementacion">
        <p>La calculadora del sitio sugiere un plan según los límites vigentes de cada uno y, cuando hay tarifas publicadas, estima el costo de que Ascento cargue los datos iniciales. Esa estimación es orientativa y no obliga a ninguna de las partes hasta que se confirme un presupuesto por escrito; si las tarifas no están publicadas, se muestra «a confirmar». El servicio de implementación, si se contrata, se presupuesta y se cobra aparte de la suscripción mensual.</p>
    </x-legal.section>

    <x-legal.section n="20" title="Ley aplicable, reclamos y jurisdicción" id="ley">
        <p>Estos términos se rigen por las leyes de la República Argentina. Si contratás o usás Ascento desde otro país, estos términos no excluyen las normas de ese país que no se puedan dejar de lado por contrato, que pueden aplicarse además de estas. Las operaciones de repuestos entre compradores y proveedores se rigen por lo que acuerden entre ellos y por la normativa que les corresponda. Antes de iniciar un reclamo, te pedimos que nos escribas a <a href="mailto:{{ $email }}">{{ $email }}</a> para intentar resolverlo.</p>
        <p>Las controversias se someten a los tribunales competentes según la ley aplicable. Si quien reclama es un consumidor, se respetan las reglas de competencia y los derechos que le reconoce la normativa de defensa del consumidor.</p>
        <p>Si alguna cláusula fuera inválida, el resto de los términos sigue vigente.</p>
    </x-legal.section>
@endsection
