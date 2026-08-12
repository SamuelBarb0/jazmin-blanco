<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo que recibe la paciente cuando le agendan o le mueven la cita.
 *
 * Va en paralelo al aviso de WhatsApp, no en su lugar: son dos canales con
 * problemas distintos. El WhatsApp depende de que la paciente tenga teléfono
 * registrado y de la ventana de 24 h de Meta —fuera de ella solo entran
 * plantillas aprobadas—, mientras que el correo llega siempre que haya
 * dirección. De 102 citas solo 31 tienen teléfono, así que para muchas
 * pacientes este correo es el ÚNICO aviso que van a recibir.
 *
 * Se manda de forma síncrona y no en cola, igual que el WhatsApp y por el
 * mismo motivo: la doctora acaba de darle a guardar y tiene que saber en ese
 * momento si el aviso salió o no. Una cola diría «encolado», que no es lo
 * mismo que «le llegó».
 */
class CitaParaLaPaciente extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $tipo  'agendada' (cita nueva) o 'reprogramada' (cambió la hora)
     */
    public function __construct(
        public Appointment $appointment,
        public string $tipo = 'agendada',
    ) {}

    public function envelope(): Envelope
    {
        $clinica = Settings::botConfig()['clinic_name'];

        return new Envelope(
            subject: $this->tipo === 'reprogramada'
                ? 'Tu cita cambió de fecha · '.$clinica
                : 'Tu cita quedó agendada · '.$clinica,
        );
    }

    public function content(): Content
    {
        $tz = Settings::googleTimezone();
        // shiftTimezone y NO tz(): `starts_at` guarda la hora de PARED del
        // consultorio, pero `app.timezone` es UTC y Laravel la lee como si
        // fuera UTC. Convertirla le resta 5 horas; aquí solo hay que
        // reetiquetarla. Ver el comentario largo en AppointmentController.
        $inicio = $this->appointment->starts_at->copy()->shiftTimezone($tz)->locale('es');
        $config = Settings::botConfig();

        // El nombre se saca igual que en el aviso de WhatsApp: primero el del
        // lead, que es como se presentó ella misma en el chat.
        $nombre = trim(explode(' ', trim((string) ($this->appointment->lead?->name ?: $this->appointment->patient_name)))[0] ?: '');

        return new Content(
            view: 'emails.cita',
            with: [
                'reprogramada' => $this->tipo === 'reprogramada',
                'nombre' => $nombre !== '' ? mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8') : null,
                'dia' => $inicio->isoFormat('dddd D [de] MMMM [de] YYYY'),
                'hora' => $inicio->isoFormat('h:mm a'),
                'servicio' => $this->appointment->service?->name,
                'clinica' => $config['clinic_name'],
                'direccion' => $config['clinic_address'],
                'horario' => $config['clinic_hours'],
            ],
        );
    }
}
