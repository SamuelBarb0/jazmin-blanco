<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de presentación para la reunión con la doctora:
 * servicios reales de la clínica + una conversación de ejemplo del bot.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstWhere('email', 'jazmin@consultorio.test');
        if (! $user) {
            return;
        }

        // Los servicios reales de la clínica se cargan en PolarixServicesSeeder
        // (catálogo POLARIX desde el xlsx + docx). No se siembran aquí.

        // ── Conversación de ejemplo del bot ──────────────────
        $user->conversations()->where('channel', 'panel')->delete();

        $conversation = $user->conversations()->create([
            'channel' => 'panel',
            'title' => 'Ejemplo · Endolifting',
        ]);

        $script = [
            ['user', '¡Hola! 😊 Vi en sus historias el Endolifting y me encantó. ¿Me cuentan más?'],
            ['assistant', '¡Hola! Claro que sí 💙 El Endolifting es un lifting facial sin cirugía: con energía láser estimulamos el colágeno para reafirmar la piel y definir el óvalo facial, con una recuperación muy rápida. Es ideal para flacidez leve o moderada. ¿Te gustaría que te cuente si eres buena candidata en una valoración con la Dra. Blanco?'],
            ['user', 'Suena increíble. ¿Cuánto cuesta?'],
            ['assistant', 'Como referencia, el Endolifting parte de $1.800.000 COP, pero el valor exacto depende de tu caso. En la valoración la Dra. Blanco evalúa tu piel y arma un plan personalizado con el precio definitivo, sin compromiso. ¿Quieres que te ayude a agendarla?'],
            ['user', '¿Y duele mucho?'],
            ['assistant', 'Es muy llevadero 🙌 Se aplica anestesia local para tu comodidad, así que la molestia es mínima. La mayoría de pacientes retoma su rutina muy pronto. La doctora te explica todos los cuidados en la valoración. ¿Te gustaría reservar tu cita?'],
            ['user', 'Sí, me gustaría agendar 🙏'],
            ['assistant', '¡Qué alegría! 🎉 Tenemos disponibilidad de lunes a viernes de 8:00 a.m. a 6:00 p.m. y sábados en la mañana. ¿Qué día y hora te quedan mejor? Con eso confirmo tu valoración con la Dra. Jasmin Blanco y te envío el recordatorio. 💙'],
        ];

        foreach ($script as [$role, $content]) {
            $conversation->messages()->create(['role' => $role, 'content' => $content]);
        }
    }
}
