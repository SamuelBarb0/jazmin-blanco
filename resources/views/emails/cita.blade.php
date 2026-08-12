{{--
    Correo de cita para la paciente.

    Escrito con tablas y estilos EN LÍNEA a propósito, que es lo que aguantan
    los clientes de correo: Outlook no entiende flexbox ni grid y Gmail borra
    las hojas de estilo del <head>. Aquí no se usa la maquetación moderna del
    resto del proyecto porque sencillamente no llega.

    Tampoco lleva imágenes: exigirían URLs absolutas y la mayoría de clientes
    las bloquean por defecto, así que un correo que dependa de ellas se ve roto
    justo en la primera impresión. Todo el peso visual lo llevan el tipo y el
    color.
--}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $reprogramada ? 'Tu cita cambió de fecha' : 'Tu cita quedó agendada' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f1ee;">

{{-- Lo que se ve en la bandeja de entrada, debajo del asunto, sin abrir el
     correo. Si no se pone, los clientes cogen el primer texto que encuentren. --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">
  {{ $dia }} a las {{ $hora }} · {{ $clinica }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#f4f1ee;padding:28px 12px;">
  <tr>
    <td align="center">

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
             style="max-width:560px;background-color:#ffffff;border-radius:14px;overflow:hidden;">

        {{-- Cabecera --}}
        <tr>
          <td style="background-color:#2f2a26;padding:26px 32px;">
            <div style="font-family:Georgia,'Times New Roman',serif;font-size:19px;line-height:1.3;color:#ffffff;">
              {{ $clinica }}
            </div>
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#c4a882;padding-top:6px;">
              {{ $reprogramada ? 'Cambio de fecha' : 'Confirmación de cita' }}
            </div>
          </td>
        </tr>

        {{-- Cuerpo --}}
        <tr>
          <td style="padding:32px;font-family:Arial,Helvetica,sans-serif;color:#3a3532;">

            <p style="margin:0 0 18px;font-size:16px;line-height:1.6;">
              @if ($nombre)Hola {{ $nombre }},@else Hola,@endif
            </p>

            <p style="margin:0 0 26px;font-size:15px;line-height:1.7;color:#5a534e;">
              @if ($reprogramada)
                Tu cita fue reprogramada. Esta es la nueva fecha:
              @else
                Tu cita quedó agendada. Estos son los datos:
              @endif
            </p>

            {{-- El dato que la paciente va a volver a buscar dentro de una
                 semana. Va destacado y solo. --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#faf8f6;border-left:3px solid #c4a882;border-radius:6px;">
              <tr>
                <td style="padding:20px 22px;">
                  <div style="font-family:Georgia,'Times New Roman',serif;font-size:20px;line-height:1.35;color:#2f2a26;">
                    {{ ucfirst($dia) }}
                  </div>
                  <div style="font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:1.2;color:#2f2a26;padding-top:4px;">
                    {{ $hora }}
                  </div>
                  @if ($servicio)
                    <div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#7a716b;padding-top:12px;">
                      {{ $servicio }}
                    </div>
                  @endif
                </td>
              </tr>
            </table>

            {{-- Dónde --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="padding-top:26px;">
              <tr>
                <td style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#a1968e;padding-bottom:6px;">
                  Dónde
                </td>
              </tr>
              <tr>
                <td style="font-size:15px;line-height:1.6;color:#3a3532;">
                  {{ $direccion }}
                </td>
              </tr>
            </table>

            {{-- La política de cancelación va SIEMPRE en este correo, no solo
                 cuando la preguntan: es dinero ya pagado y tiene que quedarle
                 claro antes de que le afecte, no cuando ya la incumplió. --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="padding-top:26px;">
              <tr>
                <td style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#a1968e;padding-bottom:6px;">
                  Si necesitas cancelar o cambiarla
                </td>
              </tr>
              <tr>
                <td style="font-size:14px;line-height:1.7;color:#5a534e;">
                  Avísanos con más de 24 horas de anticipación, contadas desde la hora
                  de tu cita. Con menos tiempo, o si no puedes asistir, el valor de la
                  valoración no se reembolsa.
                </td>
              </tr>
            </table>

            <p style="margin:26px 0 0;font-size:15px;line-height:1.7;color:#5a534e;">
              Si tienes cualquier duda, respóndenos por WhatsApp y te ayudamos.
            </p>

          </td>
        </tr>

        {{-- Pie --}}
        <tr>
          <td style="background-color:#faf8f6;padding:20px 32px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#8d837c;border-top:1px solid #eee7e1;">
            {{ $clinica }}<br>
            {{ $horario }}
          </td>
        </tr>

      </table>

    </td>
  </tr>
</table>

</body>
</html>
