@extends('legal.layout')

@section('titulo', 'Condiciones del servicio')

@section('contenido')
    <p>
        Estas condiciones regulan el uso del canal de atención por WhatsApp y de la plataforma de agendamiento de
        {{ $empresa['clinic_name'] }}. Al escribirnos y agendar por este medio, las aceptas.
    </p>

    <h2>1. Qué es este servicio</h2>
    <p>
        Un canal para resolver dudas sobre nuestros servicios, agendar una <strong>valoración médica</strong> y
        gestionar su pago. Parte de la atención la realiza un asistente virtual automatizado, disponible a
        cualquier hora; el personal del consultorio puede intervenir en la conversación en cualquier momento.
    </p>

    <div class="aviso">
        <p>
            <strong>Esto no es una consulta médica.</strong> La información que recibas por este canal es
            orientativa y comercial. No constituye diagnóstico, tratamiento ni recomendación médica, y no
            reemplaza una valoración presencial con un profesional de la salud. Si tienes una urgencia médica,
            acude a un servicio de urgencias.
        </p>
    </div>

    <h2>2. Citas</h2>
    <ul>
        <li>La cita queda confirmada cuando así te lo comunicamos, no cuando la solicitas.</li>
        <li>Para apartar el cupo se cobra el valor de la valoración. El cupo se confirma una vez verificado el pago.</li>
        <li>Si necesitas cancelar o reprogramar, avísanos con la mayor antelación posible por el mismo canal.</li>
        <li>Podemos reprogramar una cita por causas de fuerza mayor, avisándote lo antes posible.</li>
    </ul>

    <h2>3. Pagos</h2>
    <p>
        El pago de la valoración se realiza a través de <strong>Mercado Pago</strong>, mediante un enlace que
        generamos para ti. Los datos de tu medio de pago los procesa directamente Mercado Pago: el consultorio
        no los ve ni los almacena.
    </p>
    <p>
        Comparte únicamente los enlaces de pago que te enviemos por este canal.
        <strong>Nunca te pediremos claves, códigos de verificación ni el número completo de tu tarjeta</strong>
        por WhatsApp. Si recibes una solicitud así, desconfía y verifícalo con nosotros.
    </p>
    <p>
        El valor de la valoración puede abonarse al tratamiento según las condiciones que se te informen en la
        consulta. Las devoluciones se evalúan caso a caso conforme a la normativa aplicable.
    </p>

    <h2>4. Uso correcto del canal</h2>
    <ul>
        <li>Danos información veraz: agendar con datos falsos puede dejar sin cupo a otra paciente.</li>
        <li>No uses el canal para enviar contenido ofensivo, ilegal o publicidad de terceros.</li>
        <li>Podemos suspender la atención por este medio ante un uso abusivo.</li>
    </ul>

    <h2>5. Disponibilidad</h2>
    <p>
        Procuramos que el servicio esté siempre disponible, pero depende de terceros (WhatsApp, proveedores de
        alojamiento y de pagos) y puede interrumpirse por mantenimiento o fallas ajenas a nosotros. Esas
        interrupciones no generan responsabilidad para el consultorio más allá de reprogramar lo acordado.
    </p>

    <h2>6. Datos personales</h2>
    <p>
        El tratamiento de tus datos se rige por nuestra
        <a href="{{ route('legal.privacidad') }}">política de tratamiento de datos personales</a>.
    </p>

    <h2>7. Cambios y ley aplicable</h2>
    <p>
        Podemos actualizar estas condiciones publicando una nueva versión en esta dirección. Se rigen por la ley
        colombiana.
    </p>

    <h2>8. Contacto</h2>
    <p>
        WhatsApp <strong>{{ $telefono }}</strong> · <a href="mailto:{{ $correo }}">{{ $correo }}</a>
        @if($empresa['clinic_hours'])<br>Horario de atención: {{ $empresa['clinic_hours'] }}@endif
    </p>
@endsection
