@extends('legal.layout')

@section('titulo', 'Política de tratamiento de datos personales')

@section('contenido')
    <p>
        Esta política explica qué datos personales tratamos en {{ $empresa['clinic_name'] }}, con qué finalidad,
        con quién los compartimos y cómo puedes ejercer tus derechos. Se rige por la
        <strong>Ley 1581 de 2012</strong> y el <strong>Decreto 1074 de 2015</strong> de Colombia.
    </p>

    <h2>1. Quién responde por tus datos</h2>
    <p>
        El responsable del tratamiento es <strong>{{ $empresa['clinic_name'] }}</strong>@if($empresa['clinic_address']),
        con domicilio en {{ $empresa['clinic_address'] }}@endif.
    </p>
    <p>
        Canales de contacto: WhatsApp <strong>{{ $telefono }}</strong> y correo
        <a href="mailto:{{ $correo }}">{{ $correo }}</a>.
    </p>

    <h2>2. Qué datos recogemos</h2>
    <p>Solo tratamos lo necesario para atenderte y agendar tu cita:</p>
    <div class="tabla-scroll">
        <table>
            <thead>
                <tr><th>Dato</th><th>De dónde sale</th></tr>
            </thead>
            <tbody>
                <tr><td>Nombre y número de teléfono</td><td>De tu perfil de WhatsApp cuando nos escribes, o de lo que nos indiques</td></tr>
                <tr><td>Contenido de la conversación</td><td>Los mensajes que intercambias con nosotros por WhatsApp</td></tr>
                <tr><td>Imágenes o archivos que envíes</td><td>Por ejemplo, el comprobante de pago de la valoración</td></tr>
                <tr><td>Datos de la cita</td><td>Fecha, hora y motivo de consulta que acordamos contigo</td></tr>
                <tr><td>Información de tu pago</td><td>Monto, estado y medio de pago. <strong>Nunca vemos ni guardamos los datos de tu tarjeta</strong></td></tr>
                <tr><td>Anuncio de origen</td><td>Si llegaste tocando un anuncio, guardamos de qué campaña vino el contacto</td></tr>
            </tbody>
        </table>
    </div>

    <div class="aviso">
        <p>
            <strong>Datos de salud.</strong> Si en la conversación mencionas información sobre tu salud, esa
            información es un <em>dato sensible</em> según la ley colombiana. No estás obligada a entregarla, y
            solo la tratamos con tu autorización previa y expresa, con la finalidad de valorar y prestarte el
            servicio. Puedes pedirnos en cualquier momento que la eliminemos.
        </p>
    </div>

    <h2>3. Para qué los usamos</h2>
    <ul>
        <li>Responder tus mensajes y resolver tus dudas sobre los servicios del consultorio.</li>
        <li>Agendar, confirmar y recordarte tus citas.</li>
        <li>Gestionar el pago de la valoración y verificar que se haya realizado.</li>
        <li>Llevar el historial de la conversación para darte continuidad y no pedirte lo mismo dos veces.</li>
        <li>Medir de forma agregada qué campañas funcionan, para no invertir a ciegas en publicidad.</li>
    </ul>
    <p>No vendemos tus datos ni los usamos para enviarte publicidad de terceros.</p>

    <h2>4. Un asistente automatizado atiende el chat</h2>
    <p>
        Parte de las respuestas por WhatsApp las genera un asistente virtual con inteligencia artificial. Siempre
        se identifica como tal si se lo preguntas, y en cualquier momento puedes pedir hablar con una persona del
        consultorio. Las decisiones clínicas <strong>siempre</strong> las toma el personal médico, nunca el asistente.
    </p>

    <h2>5. Con quién compartimos la información</h2>
    <p>
        No cedemos tus datos a terceros para sus propios fines. Sí nos apoyamos en proveedores que los tratan por
        encargo nuestro y bajo sus propias políticas de privacidad:
    </p>
    <div class="tabla-scroll">
        <table>
            <thead>
                <tr><th>Proveedor</th><th>Para qué</th><th>Qué recibe</th></tr>
            </thead>
            <tbody>
                <tr><td>Meta (WhatsApp Business)</td><td>Transportar los mensajes</td><td>Tu número y el contenido del chat</td></tr>
                <tr><td>Anthropic (Claude)</td><td>Generar las respuestas del asistente</td><td>El texto de la conversación</td></tr>
                <tr><td>Google (Calendar)</td><td>Agendar la cita</td><td>Tu nombre y los datos de la cita</td></tr>
                <tr><td>Mercado Pago</td><td>Procesar el pago</td><td>Los datos que ingresas al pagar</td></tr>
                <tr><td>Proveedor de hosting</td><td>Alojar la plataforma</td><td>La información almacenada en el sistema</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        Algunos de estos proveedores están fuera de Colombia, así que puede haber transferencia internacional de
        datos. Al autorizar esta política aceptas esa transferencia, que se hace con proveedores que ofrecen
        niveles adecuados de protección.
    </p>

    <h2>6. Cuánto tiempo los conservamos</h2>
    <p>
        Mantenemos tus datos mientras exista la relación contigo y durante el tiempo que la ley nos exija
        conservarlos (por ejemplo, la historia clínica tiene plazos propios). Cuando ya no sean necesarios, los
        eliminamos o los anonimizamos.
    </p>

    <h2>7. Tus derechos</h2>
    <p>Como titular de los datos puedes, gratuitamente y en cualquier momento:</p>
    <ul>
        <li><strong>Conocer</strong> qué datos tuyos tenemos y cómo los usamos.</li>
        <li><strong>Actualizar y rectificar</strong> los que estén incompletos o equivocados.</li>
        <li><strong>Solicitar prueba</strong> de la autorización que nos diste.</li>
        <li><strong>Revocar la autorización</strong> y <strong>pedir que los eliminemos</strong>, cuando no exista un deber legal de conservarlos.</li>
        <li><strong>Presentar quejas</strong> ante la Superintendencia de Industria y Comercio.</li>
    </ul>
    <p>
        Para ejercerlos, escríbenos por WhatsApp al <strong>{{ $telefono }}</strong> o a
        <a href="mailto:{{ $correo }}">{{ $correo }}</a>. Respondemos las consultas en un máximo de 10 días
        hábiles y los reclamos en 15 días hábiles, según los plazos de ley. En
        <a href="{{ route('legal.eliminacion') }}">esta página</a> te explicamos paso a paso cómo pedir la
        eliminación.
    </p>

    <h2>8. Seguridad</h2>
    <p>
        La plataforma se sirve cifrada (HTTPS), el acceso al panel está restringido al personal autorizado del
        consultorio y las credenciales de los servicios se guardan cifradas. Ningún sistema es infalible, pero
        aplicamos medidas razonables para proteger tu información.
    </p>

    <h2>9. Cambios en esta política</h2>
    <p>
        Si la modificamos, publicaremos la nueva versión en esta misma dirección con su fecha de actualización.
        Si el cambio afecta de forma sustancial la finalidad del tratamiento, te lo comunicaremos.
    </p>
@endsection
