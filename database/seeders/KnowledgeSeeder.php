<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class KnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstWhere('email', 'jazmin@consultorio.test');
        if (! $user || $user->knowledgeEntries()->exists()) {
            return;
        }

        $entries = [
            ['valoracion', '¿Qué incluye la valoración médica?', 'La valoración es una cita presencial con la Dra. Jasmin Blanco donde se evalúa tu caso, se revisan antecedentes y contraindicaciones, y se diseña un plan personalizado. Es el primer paso para cualquier tratamiento.'],
            ['contraindicacion', 'Contraindicaciones generales', 'Algunos tratamientos no se recomiendan en embarazo, lactancia, infecciones activas en la zona, o ciertas condiciones médicas. Cada caso se evalúa de forma individual en la valoración previa. Nunca damos un tratamiento sin valoración médica.'],
            ['faq', '¿Los tratamientos son dolorosos?', 'La mayoría de los procedimientos son mínimamente molestos. Según el tratamiento se usa anestesia local o tópica para tu comodidad. En la valoración se te explica qué esperar en cada caso.'],
            ['faq', '¿Cuánto dura la recuperación?', 'Depende del procedimiento: muchos tratamientos estéticos permiten retomar la rutina el mismo día, otros requieren algunos días de cuidados. La doctora te indica los cuidados específicos en tu valoración.'],
            ['faq', '¿Cuándo se ven los resultados?', 'Varía según el tratamiento y cada persona. Algunos resultados son progresivos a lo largo de semanas. No prometemos resultados garantizados; la valoración permite darte expectativas realistas.'],
            ['diferenciador', '¿Por qué elegir el consultorio de la Dra. Jasmin Blanco?', 'Atención personalizada y premium, planes a la medida, tecnología de última generación y un acompañamiento cercano antes, durante y después de cada procedimiento. La doctora valora cada caso de forma individual.'],
            ['politica', 'Agendamiento y confirmación', 'Las valoraciones se agendan según disponibilidad de la agenda. Te enviamos recordatorio antes de la cita. Si necesitas reprogramar, avísanos con anticipación para reasignar el espacio.'],
        ];

        foreach ($entries as $i => [$category, $title, $content]) {
            $user->knowledgeEntries()->create([
                'category' => $category,
                'title' => $title,
                'content' => $content,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }
    }
}
