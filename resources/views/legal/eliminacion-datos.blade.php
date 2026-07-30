@extends('legal.layout')

@section('titulo', 'Cómo eliminar tus datos')

@section('contenido')
    <p>
        Puedes pedirnos en cualquier momento que borremos la información que tenemos sobre ti. Es gratuito y no
        necesitas dar explicaciones.
    </p>

    <h2>Cómo solicitarlo</h2>
    <p>Por cualquiera de estos dos medios:</p>
    <ul>
        <li>
            <strong>Por WhatsApp</strong>, escribiendo al <strong>{{ $telefono }}</strong> desde el mismo número
            con el que nos contactaste, con el mensaje «Solicito la eliminación de mis datos».
        </li>
        <li>
            <strong>Por correo</strong>, a <a href="mailto:{{ $correo }}">{{ $correo }}</a>, indicando el número
            de teléfono con el que nos escribiste para poder identificarte.
        </li>
    </ul>
    <p>
        Solo te pediremos confirmar tu identidad si la solicitud llega desde un número o correo distinto al que
        usaste con nosotros. Es una comprobación para que nadie pueda borrar los datos de otra persona.
    </p>

    <h2>Qué se elimina</h2>
    <ul>
        <li>Tu nombre y número de teléfono.</li>
        <li>El historial completo de la conversación, incluidas las imágenes que hayas enviado.</li>
        <li>Tu ficha de paciente y el registro de origen de la campaña publicitaria.</li>
        <li>Las citas futuras que no se hayan realizado, que quedarán canceladas.</li>
    </ul>

    <h2>Qué puede no eliminarse</h2>
    <p>
        Si ya recibiste atención en el consultorio, hay información que la ley colombiana nos obliga a conservar
        durante plazos determinados, como la <strong>historia clínica</strong> y los
        <strong>soportes contables</strong> de los pagos realizados. Esa información no se borra por solicitud,
        pero queda restringida a su finalidad legal y no se usa para contactarte.
    </p>
    <p>
        Los registros que conservan por su cuenta terceros como WhatsApp o la pasarela de pagos se rigen por sus
        propias políticas; si quieres borrarlos, debes solicitarlo directamente a ellos.
    </p>

    <h2>Cuánto tarda</h2>
    <p>
        Confirmamos la recepción de inmediato y completamos la eliminación en un plazo máximo de
        <strong>15 días hábiles</strong>, conforme a la Ley 1581 de 2012. Te avisamos cuando esté hecho.
    </p>

    <div class="aviso">
        <p>
            Si prefieres no borrar todo, también puedes pedirnos algo más puntual: que dejemos de escribirte, que
            corrijamos un dato equivocado o que eliminemos solo una parte de la conversación. Dínoslo por el
            mismo canal.
        </p>
    </div>

    <h2>Contacto</h2>
    <p>
        WhatsApp <strong>{{ $telefono }}</strong> · <a href="mailto:{{ $correo }}">{{ $correo }}</a><br>
        Más detalle en nuestra <a href="{{ route('legal.privacidad') }}">política de tratamiento de datos</a>.
    </p>
@endsection
